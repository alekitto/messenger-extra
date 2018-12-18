<?php declare(strict_types=1);

namespace Kcs\MessengerExtra\Transport\Mongo;

use MongoDB\Collection;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Transport\Serialization\Serializer;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Serializer Messenger receiver to get messages from MongoDB connection.
 *
 * @author Alessandro Chitolina <alekitto@gmail.com>
 */
class MongoReceiver implements ReceiverInterface
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
     * @var bool
     */
    private $shouldStop;

    /**
     * @var float
     */
    private $removeExpiredMessagesLastExecutedAt;

    public function __construct(Collection $collection, SerializerInterface $serializer = null)
    {
        $this->collection = $collection;
        $this->serializer = $serializer ?? Serializer::create();
        $this->shouldStop = false;
    }

    /**
     * {@inheritdoc}
     */
    public function receive(callable $handler): void
    {
        while (! $this->shouldStop) {
            $this->removeExpiredMessages();

            [$message, $envelope] = $this->fetchMessage() ?? [null, null, null];
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
                $this->acknowledge($message);
            } catch (\Throwable $e) {
                $this->redeliver($message);

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
                        ]
                    ]
                ]
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

        if (empty($message['time_to_live']) || $message['time_to_live'] > time()) {
            return [
                $message,
                $this->serializer->decode([
                    'body' => $message['body'],
                    'headers' => $message['headers'],
                ]),
            ];
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
     * Mark a message as acknowledged (and deletes it).
     *
     * @param array $message
     */
    private function acknowledge(array $message): void
    {
        $this->collection->deleteOne(['_id' => $message['_id']]);
    }

    /**
     * Redeliver a single message.
     *
     * @param array $message
     */
    private function redeliver(array $message): void
    {
        $this->collection->updateOne([
            '_id' => $message['_id'],
        ], [
            '$set' => [
                'delivery_id' => null,
                'redeliver_at' => null,
            ],
        ]);
    }
}
