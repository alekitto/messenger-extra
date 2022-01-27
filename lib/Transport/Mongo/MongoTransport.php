<?php

declare(strict_types=1);

namespace Kcs\MessengerExtra\Transport\Mongo;

use MongoDB\Client;
use MongoDB\Collection;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\SetupableTransportInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Serializer Messenger Transport to produce and consume messages from/to Mongo database.
 */
class MongoTransport implements TransportInterface, ListableReceiverInterface, MessageCountAwareInterface, SetupableTransportInterface
{
    private Collection $collection;
    private ?SerializerInterface $serializer;
    private MongoReceiver $receiver;
    private MongoSender $sender;

    private $queueName;
    private $database;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(Client $client, ?SerializerInterface $serializer = null, array $options = [])
    {
        $this->collection = $client->{$options['database_name']}->{$options['collection_name']};
        $this->serializer = $serializer;
        $this->database = $client->{$options['database_name']};
        $this->queueName = array_key_exists('queue_name', $options) ? $options['queue_name'] : '';
    }

    /**
     * {@inheritdoc}
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
     * {@inheritdoc}
     */
    public function all(?int $limit = null): iterable
    {
        yield from ($this->receiver ?? $this->getReceiver())->all($limit);
    }

    /**
     * {@inheritdoc}
     *
     * @param mixed $id
     */
    public function find($id): ?Envelope
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
        return $this->receiver = new MongoReceiver($this->collection, $this->serializer, $this->queueName);
    }

    private function getSender(): MongoSender
    {
        return $this->sender = new MongoSender($this->collection, $this->serializer, $this->queueName);
    }

    public function setup(): void
    {
        $messengerCollectionName = $this->collection->getCollectionName();
        $this->database->selectCollection($messengerCollectionName);
        $this->collection->createIndex(
            ['queue_name' => 1],
            ['sparse' => true]
        );
    }
}
