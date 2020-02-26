<?php declare(strict_types=1);

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
 *
 * @author Alessandro Chitolina <alekitto@gmail.com>
 */
class MongoTransport implements TransportInterface, ListableReceiverInterface, MessageCountAwareInterface
{
    /**
     * @var Collection
     */
    private $collection;

    /**
     * @var SerializerInterface
     */
    private $serializer;

    /**
     * @var MongoReceiver
     */
    private $receiver;

    /**
     * @var MongoSender
     */
    private $sender;

    public function __construct(Client $client, SerializerInterface $serializer = null, array $options = [])
    {
        $this->collection = $client->{$options['database_name']}->{$options['collection_name']};
        $this->serializer = $serializer;
    }

    /**
     * {@inheritdoc}
     */
    public function get(): iterable
    {
        return $this->getReceiver()->get();
    }

    /**
     * {@inheritdoc}
     */
    public function ack(Envelope $envelope): void
    {
        $this->getReceiver()->ack($envelope);
    }

    /**
     * {@inheritdoc}
     */
    public function reject(Envelope $envelope): void
    {
        $this->getReceiver()->reject($envelope);
    }

    /**
     * {@inheritdoc}
     */
    public function all(int $limit = null): iterable
    {
        yield from $this->getReceiver()->all($limit);
    }

    /**
     * {@inheritdoc}
     */
    public function find($id): ?Envelope
    {
        return $this->getReceiver()->find($id);
    }

    /**
     * {@inheritdoc}
     */
    public function getMessageCount(): int
    {
        return $this->getReceiver()->getMessageCount();
    }

    /**
     * {@inheritdoc}
     */
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
