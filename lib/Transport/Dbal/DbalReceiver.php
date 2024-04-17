<?php

declare(strict_types=1);

namespace Kcs\MessengerExtra\Transport\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Types;
use Ramsey\Uuid\Codec\StringCodec;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidFactory;
use Safe\DateTimeImmutable;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Transport\Serialization\Serializer;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Throwable;

use function assert;
use function bin2hex;
use function is_resource;
use function json_decode;
use function microtime;
use function Safe\hex2bin;
use function Safe\preg_match;
use function Safe\stream_get_contents;

use const JSON_THROW_ON_ERROR;

/**
 * Serializer Messenger receiver to get messages from DBAL connection.
 */
class DbalReceiver implements ReceiverInterface, MessageCountAwareInterface, ListableReceiverInterface
{
    private SerializerInterface $serializer;
    private string $tableName;
    private Connection $connection;
    private float $redeliverMessagesLastExecutedAt;
    private float $removeExpiredMessagesLastExecutedAt;
    private StringCodec $codec;
    private QueryBuilder $select;
    private QueryBuilder $update;
    private int $retryingSafetyCounter = 0;

    public function __construct(Connection $connection, string $tableName, ?SerializerInterface $serializer = null)
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
            ->setParameter('delayedUntil', new DateTimeImmutable(), Types::DATETIMETZ_IMMUTABLE)
            ->setMaxResults(1);

        $this->update = $this->connection->createQueryBuilder()
            ->update($this->tableName)
            ->set('delivery_id', ':deliveryId')
            ->set('redeliver_after', ':redeliverAfter')
            ->andWhere('id = :messageId')
            ->andWhere('delivery_id IS NULL');
    }

    /**
     * {@inheritDoc}
     */
    public function get(): iterable
    {
        $this->removeExpiredMessages();
        $this->redeliverMessages();

        $envelope = $this->fetchMessage();
        if ($envelope === null) {
            return;
        }

        try {
            yield $envelope;

            $this->retryingSafetyCounter = 0; // reset counter
        } catch (RetryableException $e) {
            if (++$this->retryingSafetyCounter > 3) {
                throw new TransportException($e->getMessage(), 0, $e);
            }
        } catch (Throwable $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        }
    }

    public function ack(Envelope $envelope): void
    {
        $messageIdStamp = $envelope->last(TransportMessageIdStamp::class);
        assert($messageIdStamp instanceof TransportMessageIdStamp);

        $this->connection->delete($this->tableName, ['id' => hex2bin($messageIdStamp->getId())], ['id' => ParameterType::BINARY]);
    }

    public function reject(Envelope $envelope): void
    {
        $this->ack($envelope);
    }

    /**
     * {@inheritDoc}
     */
    public function all(?int $limit = null): iterable
    {
        $result = $this->connection->createQueryBuilder()
            ->select('body', 'headers', 'id')
            ->from($this->tableName)
            ->setMaxResults($limit)
            ->executeQuery();

        while (true) {
            /** @phpstan-var array{body: string, headers: string, id: string|resource}|false $row */
            $row = $result->fetchAssociative();
            if ($row === false) {
                break;
            }

            yield $this->hydrate($row);
        }
    }

    /**
     * {@inheritDoc}
     *
     * @param mixed $id
     */
    public function find($id): ?Envelope
    {
        if (preg_match('/^[0-9a-f]+$/i', $id)) {
            $id = hex2bin($id);
        }

        $result = $this->connection->createQueryBuilder()
            ->select('body', 'headers', 'id')
            ->from($this->tableName)
            ->andWhere('id = :identifier')
            ->setParameter('identifier', $id, ParameterType::BINARY)
            ->setMaxResults(1)
            ->executeQuery();

        /** @phpstan-var array{body: string, headers: string, id: string|resource}|false $deliveredMessage */
        $deliveredMessage = $result->fetchAssociative();

        return $deliveredMessage !== false ? $this->hydrate($deliveredMessage) : null;
    }

    public function getMessageCount(): int
    {
        $queryBuilder = $this->connection->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($this->tableName)
            ->setMaxResults(1);

        return (int) $queryBuilder->executeQuery()->fetchOne();
    }

    /**
     * @param array<string, string> $row
     * @phpstan-param array{body:string, headers:string, id:resource|string} $row
     */
    private function hydrate(array $row): Envelope
    {
        $envelope = $this->serializer->decode([
            'body' => $row['body'],
            'headers' => json_decode($row['headers'], true, 512, JSON_THROW_ON_ERROR),
        ]);

        if (is_resource($row['id'])) {
            $row['id'] = stream_get_contents($row['id']);
        }

        return $envelope->with(new TransportMessageIdStamp(bin2hex($row['id'])));
    }

    /**
     * Fetches a message if it is any.
     */
    private function fetchMessage(): ?Envelope
    {
        $deliveryId = $this->codec->encodeBinary(Uuid::uuid4());
        $result = $this->select
            ->setParameter('delayedUntil', new DateTimeImmutable(), Types::DATETIMETZ_IMMUTABLE)
            ->executeQuery()
            ->fetchAssociative();

        if (! $result) {
            return null;
        }

        $id = $result['id'];
        if (is_resource($id)) {
            $id = stream_get_contents($id);
        }

        $this->update
            ->setParameter('deliveryId', $deliveryId, ParameterType::BINARY)
            ->setParameter('redeliverAfter', new DateTimeImmutable('+5 minutes'), Types::DATETIMETZ_IMMUTABLE)
            ->setParameter('messageId', $id, ParameterType::BINARY);

        if ($this->update->executeStatement()) {
            /** @phpstan-var array{body: string, headers: string, id: string|resource, time_to_live: string}|false $deliveredMessage */
            $deliveredMessage = $this->connection->createQueryBuilder()
                ->select('body', 'headers', 'id', 'time_to_live')
                ->from($this->tableName)
                ->andWhere('delivery_id = :deliveryId')
                ->setParameter('deliveryId', $deliveryId, ParameterType::BINARY)
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();

            // the message has been removed by a 3rd party, such as truncate operation.
            if ($deliveredMessage === false) {
                return null;
            }

            if (
                empty($deliveredMessage['time_to_live']) ||
                new DateTimeImmutable($deliveredMessage['time_to_live']) > new DateTimeImmutable()
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
        if (! isset($this->redeliverMessagesLastExecutedAt)) {
            $this->redeliverMessagesLastExecutedAt = microtime(true);
        } elseif (microtime(true) - $this->redeliverMessagesLastExecutedAt < 1) {
            return;
        }

        $this->connection->createQueryBuilder()
            ->update($this->tableName)
            ->set('delivery_id', ':deliveryId')
            ->andWhere('redeliver_after < :now')
            ->andWhere('delivery_id IS NOT NULL')
            ->setParameter('now', new DateTimeImmutable(), Types::DATETIMETZ_IMMUTABLE)
            ->setParameter('deliveryId', null)
            ->executeStatement();

        $this->redeliverMessagesLastExecutedAt = microtime(true);
    }

    /**
     * Removes the expired messages.
     */
    private function removeExpiredMessages(): void
    {
        if (! isset($this->removeExpiredMessagesLastExecutedAt)) {
            $this->removeExpiredMessagesLastExecutedAt = microtime(true);
        } elseif (microtime(true) - $this->removeExpiredMessagesLastExecutedAt < 1) {
            return;
        }

        $this->connection->createQueryBuilder()
            ->delete($this->tableName)
            ->andWhere('(time_to_live IS NOT NULL) AND (time_to_live < :now)')
            ->andWhere('delivery_id IS NULL')
            ->setParameter('now', new DateTimeImmutable(), Types::DATETIMETZ_IMMUTABLE)
            ->executeStatement();

        $this->removeExpiredMessagesLastExecutedAt = microtime(true);
    }
}
