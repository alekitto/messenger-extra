<?php

declare(strict_types=1);

namespace Kcs\MessengerExtra\Transport\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\SetupableTransportInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Serializer Messenger Transport to produce and consume messages from/to DBAL connection.
 */
class DbalTransport implements TransportInterface, ListableReceiverInterface, MessageCountAwareInterface, SetupableTransportInterface
{
    private DbalReceiver $receiver;
    private DbalSender $sender;

    /** @param array<string, mixed> $options */
    public function __construct(
        private readonly Connection $connection,
        private readonly SerializerInterface|null $serializer = null,
        private readonly array $options = [],
    ) {
    }

    /**
     * Register the table into the doctrine schema.
     */
    public function postGenerateSchema(GenerateSchemaEventArgs $eventArgs): void
    {
        $schema = $eventArgs->getSchema();
        if ($schema->hasTable($this->options['table_name'])) {
            return;
        }

        $this->_createTable($schema);
    }

    public function setup(): void
    {
        $this->createTable();
    }

    /**
     * Creates the queue table.
     */
    public function createTable(): void
    {
        $this->connection->connect();
        $schemaManager = $this->connection->createSchemaManager();

        $schema = $schemaManager->createSchema();
        if ($schema->hasTable($this->options['table_name'])) {
            return;
        }

        $schemaManager->createTable($this->_createTable($schema));
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
     *
     * @return iterable<Envelope>
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

    private function getReceiver(): DbalReceiver
    {
        return $this->receiver = new DbalReceiver($this->connection, $this->options['table_name'], $this->serializer);
    }

    private function getSender(): DbalSender
    {
        return $this->sender = new DbalSender($this->connection, $this->options['table_name'], $this->serializer);
    }

    private function _createTable(Schema $schema): Table // phpcs:ignore
    {
        $table = $schema->createTable($this->options['table_name']);
        $table->addColumn('id', Types::BINARY, ['length' => 16, 'fixed' => true]);
        $table->addColumn('published_at', Types::DATETIMETZ_IMMUTABLE);

        $table->addColumn('delayed_until', Types::DATETIMETZ_IMMUTABLE)
            ->setNotnull(false);
        $table->addColumn('time_to_live', Types::DATETIMETZ_IMMUTABLE)
            ->setNotnull(false);

        $table->addColumn('body', Types::TEXT);
        $table->addColumn('headers', Types::JSON);
        $table->addColumn('properties', Types::JSON);
        $table->addColumn('priority', Types::INTEGER);
        $table->addColumn('uniq_key', Types::STRING, ['length' => 70, 'notnull' => false]);

        $table->addColumn('delivery_id', Types::BINARY, ['length' => 16, 'fixed' => true])
            ->setNotnull(false);
        $table->addColumn('redeliver_after', Types::DATETIMETZ_IMMUTABLE)
            ->setNotnull(false);

        $table->setPrimaryKey(['id']);
        $table->addIndex(['delivery_id']);
        $table->addIndex(['priority']);
        $table->addIndex(['uniq_key']);

        return $table;
    }
}
