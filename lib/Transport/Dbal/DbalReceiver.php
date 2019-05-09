<?php declare(strict_types=1);

namespace Kcs\MessengerExtra\Transport\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\DateTimeTzImmutableType;
use Doctrine\DBAL\Types\Type;
use Ramsey\Uuid\Doctrine\UuidBinaryOrderedTimeType;
use Ramsey\Uuid\Doctrine\UuidBinaryType;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Transport\Serialization\Serializer;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Serializer Messenger receiver to get messages from DBAL connection.
 *
 * @author Alessandro Chitolina <alekitto@gmail.com>
 */
class DbalReceiver implements ReceiverInterface
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
     * @var bool
     */
    private $shouldStop;

    /**
     * @var DateTimeTzImmutableType
     */
    private $dateTimeType;

    /**
     * @var UuidBinaryOrderedTimeType
     */
    private $uuidType;

    /**
     * @var float
     */
    private $redeliverMessagesLastExecutedAt;

    /**
     * @var float
     */
    private $removeExpiredMessagesLastExecutedAt;

    public function __construct(Connection $connection, string $tableName, SerializerInterface $serializer = null)
    {
        $this->connection = $connection;
        $this->tableName = $tableName;
        $this->serializer = $serializer ?? Serializer::create();
        $this->shouldStop = false;

        $this->dateTimeType = Type::getType(Type::DATETIMETZ_IMMUTABLE);
        $this->uuidType = Type::getType(UuidBinaryOrderedTimeType::NAME);
    }

    /**
     * {@inheritdoc}
     */
    public function receive(callable $handler): void
    {
        while (! $this->shouldStop) {
            $this->removeExpiredMessages();
            $this->redeliverMessages();

            [$id, $envelope] = $this->fetchMessage() ?? [null, null, null];
            if (null === $envelope) {
                $handler(null);

                \usleep(200000);
                if (\function_exists('pcntl_signal_dispatch')) {
                    \pcntl_signal_dispatch();
                }

                continue;
            }

            try {
                $handler($envelope);
                $this->acknowledge($id);
            } catch (\Throwable $e) {
                $this->redeliver($id);

                throw $e;
            } finally {
                if (\function_exists('pcntl_signal_dispatch')) {
                    \pcntl_signal_dispatch();
                }
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function stop(): void
    {
        $this->shouldStop = true;
    }

    /**
     * Fetches a message if it is any.
     *
     * @return array|null
     */
    private function fetchMessage(): ?array
    {
        $deliveryId = Uuid::uuid4();
        $endAt = \microtime(true) + 0.2; // add 200ms

        $select = $this->connection->createQueryBuilder()
            ->select('id')
            ->from($this->tableName)
            ->andWhere('delayed_until IS NULL OR delayed_until <= :delayedUntil')
            ->andWhere('delivery_id IS NULL')
            ->addOrderBy('priority', 'asc')
            ->addOrderBy('published_at', 'asc')
            ->setParameter(':delayedUntil', new \DateTimeImmutable(), Type::DATETIMETZ_IMMUTABLE)
            ->setMaxResults(1);

        $update = $this->connection->createQueryBuilder()
            ->update($this->tableName)
            ->set('delivery_id', ':deliveryId')
            ->set('redeliver_after', ':redeliverAfter')
            ->andWhere('id = :messageId')
            ->andWhere('delivery_id IS NULL')
            ->setParameter(':deliveryId', $deliveryId, UuidBinaryType::NAME)
            ->setParameter(':redeliverAfter', new \DateTimeImmutable('+5 minutes'), Type::DATETIMETZ_IMMUTABLE)
        ;

        while (\microtime(true) < $endAt) {
            $result = $select->execute()->fetch();
            if (! $result) {
                return null;
            }

            $id = $this->uuidType->convertToPHPValue($result['id'], $this->connection->getDatabasePlatform());
            $update->setParameter(':messageId', $id, UuidBinaryOrderedTimeType::NAME);

            if ($update->execute()) {
                $deliveredMessage = $this->connection->createQueryBuilder()
                    ->select('*')
                    ->from($this->tableName)
                    ->andWhere('delivery_id = :deliveryId')
                    ->setParameter(':deliveryId', $deliveryId, UuidBinaryType::NAME)
                    ->setMaxResults(1)
                    ->execute()
                    ->fetch()
                ;

                // the message has been removed by a 3rd party, such as truncate operation.
                if (false === $deliveredMessage) {
                    continue;
                }

                /** @var \DateTimeImmutable $publishedAt */
                $publishedAt = $this->dateTimeType
                    ->convertToPHPValue($deliveredMessage['published_at'], $this->connection->getDatabasePlatform());

                if (empty($deliveredMessage['time_to_live']) ||
                    $publishedAt->modify('+ '.$deliveredMessage['time_to_live'].' seconds') > new \DateTimeImmutable()) {
                    return [
                        $id,
                        $this->serializer->decode([
                            'body' => $deliveredMessage['body'],
                            'headers' => \json_decode($deliveredMessage['headers'], true),
                        ]),
                    ];
                }
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
            ->setParameter(':now', new \DateTimeImmutable(), Type::DATETIMETZ_IMMUTABLE)
            ->setParameter(':deliveryId', null, UuidBinaryType::NAME)
            ->execute()
        ;

        $this->redeliverMessagesLastExecutedAt = \microtime(true);
    }

    /**
     * Mark a message as acknowledged (and deletes it).
     *
     * @param UuidInterface $id
     */
    private function acknowledge(UuidInterface $id): void
    {
        $this->connection->delete($this->tableName, ['id' => $id], ['id' => UuidBinaryOrderedTimeType::NAME]);
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
            ->setParameter(':now', (new \DateTimeImmutable())->getTimestamp(), Type::INTEGER)
            ->execute()
        ;

        $this->removeExpiredMessagesLastExecutedAt = \microtime(true);
    }

    /**
     * Redeliver a single message.
     *
     * @param UuidInterface $id
     */
    private function redeliver(UuidInterface $id): void
    {
        $this->connection->createQueryBuilder()
            ->update($this->tableName)
            ->set('delivery_id', ':deliveryId')
            ->andWhere('id = :id')
            ->setParameter(':id', $id, UuidBinaryOrderedTimeType::NAME)
            ->setParameter(':deliveryId', null, UuidBinaryType::NAME)
            ->execute()
        ;
    }
}
