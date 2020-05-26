<?php declare(strict_types=1);

namespace Kcs\MessengerExtra\Transport\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Types;
use Ramsey\Uuid\Codec\StringCodec;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Transport\Serialization\Serializer;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Serializer Messenger receiver to get messages from DBAL connection.
 *
 * @author Alessandro Chitolina <alekitto@gmail.com>
 */
class DbalReceiver implements ReceiverInterface, MessageCountAwareInterface, ListableReceiverInterface
{
    /**
     * @var SerializerInterface
     */
    private $serializer;

    /**
     * @var string
     */
    private $tableName;

    /**
     * @var Connection
     */
    private $connection;

    /**
     * @var float
     */
    private $redeliverMessagesLastExecutedAt;

    /**
     * @var float
     */
    private $removeExpiredMessagesLastExecutedAt;

    /**
     * @var StringCodec
     */
    private $codec;

    /**
     * @var QueryBuilder
     */
    private $select;

    /**
     * @var QueryBuilder
     */
    private $update;

    public function __construct(Connection $connection, string $tableName, SerializerInterface $serializer = null)
    {
        $this->connection = $connection;
        $this->tableName = $tableName;
        $this->serializer = $serializer ?? Serializer::create();
        $this->codec = new StringCodec((new UuidFactory())->getUuidBuilder());

        $this->select = $this->connection->createQueryBuilder()
            ->select('id')
            ->from($this->tableName)
            ->andWhere('delayed_until IS NULL OR delayed_until <= :delayedUntil')
            ->andWhere('delivery_id IS NULL')
            ->addOrderBy('priority', 'asc')
            ->addOrderBy('published_at', 'asc')
            ->addOrderBy('id', 'asc')
            ->setParameter(':delayedUntil', new \DateTimeImmutable(), Types::DATETIMETZ_IMMUTABLE)
            ->setMaxResults(1);

        $this->update = $this->connection->createQueryBuilder()
            ->update($this->tableName)
            ->set('delivery_id', ':deliveryId')
            ->set('redeliver_after', ':redeliverAfter')
            ->andWhere('id = :messageId')
            ->andWhere('delivery_id IS NULL')
        ;
    }

    /**
     * {@inheritdoc}
     */
    public function get(): iterable
    {
        $this->removeExpiredMessages();
        $this->redeliverMessages();

        /** @var Envelope $envelope */
        $envelope = $this->fetchMessage();
        if (null === $envelope) {
            return;
        }

        try {
            yield $envelope;

            $this->ack($envelope);
        } catch (\Throwable $e) {
            $this->redeliver($envelope->last(TransportMessageIdStamp::class)->getId());

            throw $e;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function ack(Envelope $envelope): void
    {
        /** @var TransportMessageIdStamp $messageIdStamp */
        $messageIdStamp = $envelope->last(TransportMessageIdStamp::class);

        $this->connection->delete($this->tableName, ['id' => $messageIdStamp->getId()], ['id' => ParameterType::BINARY]);
    }

    /**
     * {@inheritdoc}
     */
    public function reject(Envelope $envelope): void
    {
        $this->ack($envelope);
    }

    /**
     * {@inheritdoc}
     */
    public function all(int $limit = null): iterable
    {
        $statement = $this->connection->createQueryBuilder()
            ->select('*')
            ->from($this->tableName)
            ->setMaxResults($limit)
            ->execute()
        ;

        while (($row = $statement->fetch())) {
            yield $this->hydrate($row);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function find($id): ?Envelope
    {
        $deliveredMessage = $this->connection->createQueryBuilder()
            ->select('*')
            ->from($this->tableName)
            ->andWhere('id = :identifier')
            ->setParameter(':identifier', $id, ParameterType::BINARY)
            ->setMaxResults(1)
            ->execute()
            ->fetch()
        ;

        return $deliveredMessage ? $this->hydrate($deliveredMessage) : null;
    }

    /**
     * {@inheritdoc}
     */
    public function getMessageCount(): int
    {
        return (int) $this->connection->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($this->tableName)
            ->setMaxResults(1)
            ->execute()
            ->fetchColumn()
        ;
    }

    private function hydrate(array $row): Envelope
    {
        $envelope = $this->serializer->decode([
            'body' => $row['body'],
            'headers' => \json_decode($row['headers'], true),
        ]);

        return $envelope->with(new TransportMessageIdStamp($row['id']));
    }

    /**
     * Fetches a message if it is any.
     */
    private function fetchMessage(): ?Envelope
    {
        $deliveryId = $this->codec->encodeBinary(Uuid::uuid4());
        $result = $this->select->execute()->fetch();
        if (! $result) {
            return null;
        }

        $id = $result['id'];
        if (\is_resource($id)) {
            $id = \stream_get_contents($id);
        }

        $this->update
            ->setParameter(':deliveryId', $deliveryId, ParameterType::BINARY)
            ->setParameter(':redeliverAfter', new \DateTimeImmutable('+5 minutes'), Types::DATETIMETZ_IMMUTABLE)
            ->setParameter(':messageId', $id, ParameterType::BINARY)
        ;

        if ($this->update->execute()) {
            $deliveredMessage = $this->connection->createQueryBuilder()
                ->select('*')
                ->from($this->tableName)
                ->andWhere('delivery_id = :deliveryId')
                ->setParameter(':deliveryId', $deliveryId, ParameterType::BINARY)
                ->setMaxResults(1)
                ->execute()
                ->fetch()
            ;

            // the message has been removed by a 3rd party, such as truncate operation.
            if (false === $deliveredMessage) {
                return null;
            }

            if (
                empty($deliveredMessage['time_to_live']) ||
                new \DateTimeImmutable($deliveredMessage['time_to_live']) > new \DateTimeImmutable()
            ) {
                return $this->hydrate($deliveredMessage);
            }
        }

        return null;
    }

    /**
     * Redelivers timed out messages.
     */
    private function redeliverMessages(): void
    {
        if (null === $this->redeliverMessagesLastExecutedAt) {
            $this->redeliverMessagesLastExecutedAt = \microtime(true);
        } elseif ((\microtime(true) - $this->redeliverMessagesLastExecutedAt) < 1) {
            return;
        }

        $this->connection->createQueryBuilder()
            ->update($this->tableName)
            ->set('delivery_id', ':deliveryId')
            ->andWhere('redeliver_after < :now')
            ->andWhere('delivery_id IS NOT NULL')
            ->setParameter(':now', new \DateTimeImmutable(), Types::DATETIMETZ_IMMUTABLE)
            ->setParameter(':deliveryId', null)
            ->execute()
        ;

        $this->redeliverMessagesLastExecutedAt = \microtime(true);
    }

    /**
     * Removes the expired messages.
     */
    private function removeExpiredMessages(): void
    {
        if (null === $this->removeExpiredMessagesLastExecutedAt) {
            $this->removeExpiredMessagesLastExecutedAt = \microtime(true);
        } elseif ((\microtime(true) - $this->removeExpiredMessagesLastExecutedAt) < 1) {
            return;
        }

        $this->connection->createQueryBuilder()
            ->delete($this->tableName)
            ->andWhere('(time_to_live IS NOT NULL) AND (time_to_live < :now)')
            ->andWhere('delivery_id IS NULL')
            ->setParameter(':now', new \DateTimeImmutable(), Types::DATETIMETZ_IMMUTABLE)
            ->execute()
        ;

        $this->removeExpiredMessagesLastExecutedAt = \microtime(true);
    }

    /**
     * Redeliver a single message.
     */
    private function redeliver(string $id): void
    {
        $this->connection->createQueryBuilder()
            ->update($this->tableName)
            ->set('delivery_id', ':deliveryId')
            ->andWhere('id = :id')
            ->setParameter(':id', $id, ParameterType::BINARY)
            ->setParameter(':deliveryId', null)
            ->execute()
        ;
    }
}
