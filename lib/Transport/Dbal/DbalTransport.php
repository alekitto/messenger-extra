<?php declare(strict_types=1);

namespace Kcs\MessengerExtra\Transport\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Serializer Messenger Transport to produce and consume messages from/to DBAL connection.
 *
 * @author Alessandro Chitolina <alekitto@gmail.com>
 */
class DbalTransport implements TransportInterface
{
    /**
     * @var Connection
     */
    private $connection;

    /**
     * @var SerializerInterface
     */
    private $serializer;

    /**
     * @var array
     */
    private $options;

    /**
     * @var DbalReceiver
     */
    private $receiver;

    /**
     * @var DbalSender
     */
    private $sender;

    public function __construct(Connection $connection, SerializerInterface $serializer = null, array $options = [])
    {
        $this->connection = $connection;
        $this->serializer = $serializer;
        $this->options = $options;
    }

    /**
     * Register the table into the doctrine schema.
     *
     * @param GenerateSchemaEventArgs $eventArgs
     */
    public function postGenerateSchema(GenerateSchemaEventArgs $eventArgs): void
    {
        $schema = $eventArgs->getSchema();
        if ($schema->hasTable($this->options['table_name'])) {
            return;
        }

        $this->_createTable($schema);
    }

    /**
     * Creates the queue table.
     */
    public function createTable(): void
    {
        $this->connection->connect();
        $schemaManager = $this->connection->getSchemaManager();
        $schema = $schemaManager->createSchema();

        if (! $schema->hasTable($this->options['table_name'])) {
            $schemaManager->createTable($this->_createTable($schema));
        }
    }

    /**
     * {@inheritdoc}
     */
    public function receive(callable $handler): void
    {
        ($this->receiver ?? $this->getReceiver())->receive($handler);
    }

    /**
     * {@inheritdoc}
     */
    public function stop(): void
    {
        ($this->receiver ?? $this->getReceiver())->stop();
    }

    /**
     * {@inheritdoc}
     */
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

    private function _createTable(Schema $schema): Table
    {
        $table = $schema->createTable($this->options['table_name']);
        $table->addColumn('id', Type::BINARY, [ 'length' => 16, 'fixed' => true ]);
        $table->addColumn('published_at', Type::DATETIMETZ_IMMUTABLE);

        $table->addColumn('delayed_until', Type::DATETIMETZ_IMMUTABLE)
            ->setNotnull(false);
        $table->addColumn('time_to_live', Type::DATETIMETZ_IMMUTABLE)
            ->setNotnull(false);

        $table->addColumn('body', Type::TEXT);
        $table->addColumn('headers', Type::JSON);
        $table->addColumn('properties', Type::JSON);
        $table->addColumn('priority', Type::INTEGER);

        $table->addColumn('delivery_id', Type::BINARY, [ 'length' => 16, 'fixed' => true ])
            ->setNotnull(false);
        $table->addColumn('redeliver_after', Type::DATETIMETZ_IMMUTABLE)
            ->setNotnull(false);

        $table->setPrimaryKey(['id']);
        $table->addIndex(['delivery_id']);
        $table->addIndex(['priority']);

        return $table;
    }
}
