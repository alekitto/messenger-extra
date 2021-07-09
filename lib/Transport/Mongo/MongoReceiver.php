<?php

declare(strict_types=1);

namespace Kcs\MessengerExtra\Transport\Mongo;

use Doctrine\DBAL\Exception\RetryableException;
use MongoDB\BSON\ObjectId;
use MongoDB\Collection;
use Ramsey\Uuid\Uuid;
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
use function microtime;
use function time;

/**
 * Serializer Messenger receiver to get messages from MongoDB connection.
 */
class MongoReceiver implements ReceiverInterface, ListableReceiverInterface, MessageCountAwareInterface
{
    private SerializerInterface $serializer;
    private Collection $collection;
    private float $removeExpiredMessagesLastExecutedAt;
    private int $retryingSafetyCounter = 0;

    public function __construct(Collection $collection, ?SerializerInterface $serializer = null)
    {
        $this->collection = $collection;
        $this->serializer = $serializer ?? Serializer::create();
    }

    /**
     * {@inheritdoc}
     */
    public function get(): iterable
    {
        $this->removeExpiredMessages();

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

        $this->collection->deleteOne(['_id' => $messageIdStamp->getId()]);
    }

    public function reject(Envelope $envelope): void
    {
        $this->ack($envelope);
    }

    /**
     * {@inheritdoc}
     */
    public function all(?int $limit = null): iterable
    {
        $options = [];
        if ($options !== null) {
            $options['limit'] = $limit;
        }

        /** @phpstan-var array{_id:string, body:string, headers:string, id:resource|string, time_to_live: ?int} $deliveredMessage */
        foreach ($this->collection->find([], $options) as $deliveredMessage) {
            yield $this->hydrate((array) $deliveredMessage);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @param mixed $id
     */
    public function find($id): ?Envelope
    {
        $id = $id instanceof ObjectId ? $id : new ObjectId($id);

        /** @phpstan-var array{_id: string, body: string, headers: string, id: (resource|string), time_to_live: ?int}|null $deliveredMessage */
        $deliveredMessage = $this->collection->findOne(['_id' => $id]);
        if (! $deliveredMessage) {
            return null;
        }

        return $this->hydrate((array) $deliveredMessage);
    }

    public function getMessageCount(): int
    {
        return $this->collection->countDocuments();
    }

    /**
     * @param array<string, string> $row
     * @phpstan-param array{_id:string, body:string, headers:string, id:resource|string, time_to_live: ?int} $row
     */
    private function hydrate(array $row): Envelope
    {
        $envelope = $this->serializer->decode($row);

        return $envelope->with(new TransportMessageIdStamp($row['_id']));
    }

    /**
     * Fetches a message if it is any.
     */
    private function fetchMessage(): ?Envelope
    {
        $deliveryId = Uuid::uuid4()->toString();
        $now = time();

        /** @phpstan-var array{_id: string, body: string, headers: string, id: (resource|string), time_to_live: ?int}|null $message */
        $message = $this->collection->findOneAndUpdate(
            [
                '$and' => [
                    [
                        '$or' => [
                            ['delayed_until' => ['$exists' => false]],
                            ['delayed_until' => null],
                            ['delayed_until' => ['$lte' => $now]],
                        ],
                    ],
                    [
                        '$or' => [
                            ['delivery_id' => ['$exists' => false]],
                            ['delivery_id' => null],
                            ['redeliver_at' => ['$lte' => $now]],
                        ],
                    ],
                ],
            ],
            [
                '$set' => [
                    'delivery_id' => $deliveryId,
                    'redeliver_at' => $now + 300,
                ],
            ],
            [
                'sort' => ['priority' => -1, 'published_at' => 1],
                'typeMap' => ['root' => 'array', 'document' => 'array'],
            ]
        );

        if ($message === null) {
            return null;
        }

        if (empty($message['time_to_live']) || $message['time_to_live'] > time()) {
            return $this->hydrate($message);
        }

        return null;
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

        $this->collection->deleteMany([
            ['time_to_live' => ['$lt' => time()]],
        ]);

        $this->removeExpiredMessagesLastExecutedAt = microtime(true);
    }
}
