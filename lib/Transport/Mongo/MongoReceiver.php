<?php declare(strict_types=1);

namespace Kcs\MessengerExtra\Transport\Mongo;

use MongoDB\BSON\ObjectId;
use MongoDB\Collection;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Transport\Serialization\Serializer;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Serializer Messenger receiver to get messages from MongoDB connection.
 *
 * @author Alessandro Chitolina <alekitto@gmail.com>
 */
class MongoReceiver implements ReceiverInterface, ListableReceiverInterface, MessageCountAwareInterface
{
    /**
     * @var SerializerInterface
     */
    private $serializer;

    /**
     * @var Collection
     */
    private $collection;

    /**
     * @var float
     */
    private $removeExpiredMessagesLastExecutedAt;

    public function __construct(Collection $collection, SerializerInterface $serializer = null)
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

        $this->collection->deleteOne(['_id' => $messageIdStamp->getId()]);
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
        $options = [];
        if (null !== $options) {
            $options['limit'] = $limit;
        }

        foreach ($this->collection->find([], $options) as $deliveredMessage) {
            yield $this->hydrate((array) $deliveredMessage);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function find($id): ?Envelope
    {
        $id = $id instanceof ObjectId ? $id : new ObjectId($id);

        $deliveredMessage = $this->collection->findOne(['_id' => $id]);
        if (! $deliveredMessage) {
            return null;
        }

        return $this->hydrate((array) $deliveredMessage);
    }

    /**
     * {@inheritdoc}
     */
    public function getMessageCount(): int
    {
        return $this->collection->countDocuments();
    }

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
        $now = \time();
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

        if (null === $message) {
            return null;
        }

        if (empty($message['time_to_live']) || $message['time_to_live'] > \time()) {
            return $this->hydrate($message);
        }

        return null;
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

        $this->collection->deleteMany([
            ['time_to_live' => ['$lt' => \time()]],
        ]);

        $this->removeExpiredMessagesLastExecutedAt = \microtime(true);
    }

    /**
     * Redeliver a single message.
     */
    private function redeliver(string $id): void
    {
        $this->collection->updateOne([
            '_id' => $id,
        ], [
            '$set' => [
                'delivery_id' => null,
                'redeliver_at' => null,
            ],
        ]);
    }
}
