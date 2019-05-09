<?php declare(strict_types=1);

namespace Kcs\MessengerExtra\Transport\Mongo;

use Kcs\MessengerExtra\Message\DelayedMessageInterface;
use Kcs\MessengerExtra\Message\PriorityAwareMessageInterface;
use Kcs\MessengerExtra\Message\TTLAwareMessageInterface;
use MongoDB\BSON\ObjectId;
use MongoDB\Collection;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Transport\Serialization\Serializer;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Serializer Messenger sender to send messages through DBAL connection.
 *
 * @author Alessandro Chitolina <alekitto@gmail.com>
 */
class MongoSender implements SenderInterface
{
    /**
     * @var Collection
     */
    private $collection;

    /**
     * @var SerializerInterface
     */
    private $serializer;

    public function __construct(Collection $collection, SerializerInterface $serializer = null)
    {
        $this->collection = $collection;
        $this->serializer = $serializer ?? Serializer::create();
    }

    /**
     * {@inheritdoc}
     */
    public function send(Envelope $envelope): Envelope
    {
        $message = $envelope->getMessage();
        $encodedMessage = $this->serializer->encode($envelope);

        $values = [
            '_id' => new ObjectId(),
            'published_at' => \time(),
            'body' => $encodedMessage['body'],
            'headers' => $encodedMessage['headers'],
            'properties' => [],
            'priority' => 0,
            'time_to_live' => null,
            'delayed_until' => null,
            'delivery_id' => null,
            'redeliver_at' => null,
        ];

        if ($message instanceof TTLAwareMessageInterface) {
            $values['time_to_live'] = \time() + $message->getTtl();
        }

        if ($message instanceof DelayedMessageInterface) {
            $timestamp = \microtime(true) + ($message->getDelay() * 1000);
            $values['delayed_until'] = (int) $timestamp;
        }

        if ($message instanceof PriorityAwareMessageInterface) {
            $values['priority'] = $message->getPriority();
        }

        $this->collection->insertOne($values);

        return $envelope;
    }
}
