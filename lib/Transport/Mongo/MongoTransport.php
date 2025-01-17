<?php

declare(strict_types=1);

namespace Kcs\MessengerExtra\Transport\Mongo;

use MongoDB\Client;
use MongoDB\Collection;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Serializer Messenger Transport to produce and consume messages from/to Mongo database.
 */
class MongoTransport implements TransportInterface, ListableReceiverInterface, MessageCountAwareInterface
{
    private Collection $collection;
    private MongoReceiver $receiver;
    private MongoSender $sender;

    /** @param array<string, mixed> $options */
    public function __construct(
        Client $client,
        private readonly SerializerInterface|null $serializer = null,
        array $options = [],
    ) {
        $this->collection = $client->{$options['database_name']}->{$options['collection_name']};
    }

    /**
     * {@inheritDoc}
     */
    public function get(): iterable
    {
        return ($this->receiver ?? $this->getReceiver())->get();
    }

    public function ack(Envelope $envelope): void
    {
        ($this->receiver ?? $this->getReceiver())->ack($envelope);
    }

    public function reject(Envelope $envelope): void
    {
        ($this->receiver ?? $this->getReceiver())->reject($envelope);
    }

    /**
     * {@inheritDoc}
     */
    public function all(int|null $limit = null): iterable
    {
        yield from ($this->receiver ?? $this->getReceiver())->all($limit);
    }

    /**
     * {@inheritDoc}
     *
     * @param mixed $id
     */
    public function find($id): Envelope|null
    {
        return ($this->receiver ?? $this->getReceiver())->find($id);
    }

    public function getMessageCount(): int
    {
        return ($this->receiver ?? $this->getReceiver())->getMessageCount();
    }

    public function send(Envelope $envelope): Envelope
    {
        return ($this->sender ?? $this->getSender())->send($envelope);
    }

    private function getReceiver(): MongoReceiver
    {
        return $this->receiver = new MongoReceiver($this->collection, $this->serializer);
    }

    private function getSender(): MongoSender
    {
        return $this->sender = new MongoSender($this->collection, $this->serializer);
    }
}
