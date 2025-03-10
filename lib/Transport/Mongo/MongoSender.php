<?php

declare(strict_types=1);

namespace Kcs\MessengerExtra\Transport\Mongo;

use Kcs\MessengerExtra\Message\DelayedMessageInterface;
use Kcs\MessengerExtra\Message\PriorityAwareMessageInterface;
use Kcs\MessengerExtra\Message\TTLAwareMessageInterface;
use Kcs\MessengerExtra\Message\UniqueMessageInterface;
use MongoDB\BSON\ObjectId;
use MongoDB\Collection;
use Safe\DateTimeImmutable;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\SentStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Transport\Serialization\Serializer;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

use function assert;
use function microtime;
use function time;

/**
 * Serializer Messenger sender to send messages through DBAL connection.
 */
class MongoSender implements SenderInterface
{
    private SerializerInterface $serializer;

    public function __construct(private readonly Collection $collection, SerializerInterface|null $serializer = null)
    {
        $this->serializer = $serializer ?? Serializer::create();
    }

    public function send(Envelope $envelope): Envelope
    {
        $message = $envelope->getMessage();
        $delay = null;

        $delayStamp = $envelope->last(DelayStamp::class);
        assert($delayStamp instanceof DelayStamp || $delayStamp === null);
        if ($delayStamp !== null) {
            $delay = (new DateTimeImmutable('+ ' . $delayStamp->getDelay() . ' milliseconds'))->getTimestamp();
        }

        $encodedMessage = $this->serializer->encode($envelope
            ->withoutStampsOfType(SentStamp::class)
            ->withoutStampsOfType(TransportMessageIdStamp::class)
            ->withoutStampsOfType(DelayStamp::class));

        $values = [
            '_id' => new ObjectId(),
            'published_at' => (int) (microtime(true) * 10000),
            'body' => $encodedMessage['body'],
            'headers' => $encodedMessage['headers'] ?? [],
            'properties' => [],
            'priority' => 0,
            'time_to_live' => null,
            'delayed_until' => $delay,
            'delivery_id' => null,
            'redeliver_at' => null,
            'uniq_key' => null,
        ];

        if ($message instanceof TTLAwareMessageInterface) {
            $values['time_to_live'] = time() + $message->getTtl();
        }

        if ($message instanceof DelayedMessageInterface) {
            $timestamp = microtime(true) + ($message->getDelay() * 1000);
            $values['delayed_until'] = (int) $timestamp;
        }

        if ($message instanceof PriorityAwareMessageInterface) {
            $values['priority'] = $message->getPriority();
        }

        if ($message instanceof UniqueMessageInterface) {
            $values['uniq_key'] = $uniqKey = $message->getUniquenessKey();

            $result = $this->collection->findOne([
                '$and' => [
                    ['uniq_key' => $uniqKey],
                    [
                        '$or' => [
                            ['delivery_id' => ['$exists' => false]],
                            ['delivery_id' => null],
                        ],
                    ],
                ],
            ]);

            if ($result !== null) {
                return $envelope;
            }
        }

        $result = $this->collection->insertOne($values);

        return $envelope
            ->with(new TransportMessageIdStamp((string) $result->getInsertedId()));
    }
}
