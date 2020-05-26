<?php declare(strict_types=1);

namespace Kcs\MessengerExtra\Tests\Transport\Dbal;

use Doctrine\DBAL\Cache\ArrayStatement;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Query\Expression\ExpressionBuilder;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Kcs\MessengerExtra\Message\DelayedMessageInterface;
use Kcs\MessengerExtra\Message\TTLAwareMessageInterface;
use Kcs\MessengerExtra\Message\UniqueMessageInterface;
use Kcs\MessengerExtra\Transport\Dbal\DbalTransport;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Ramsey\Uuid\Codec\OrderedTimeCodec;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

class DbalTransportTest extends TestCase
{
    /**
     * @var EntityManagerInterface|ObjectProphecy
     */
    private $em;

    /**
     * @var Connection|ObjectProphecy
     */
    private $connection;

    /**
     * @var DbalTransport
     */
    private $transport;

    protected function setUp(): void
    {
        $this->em = $this->prophesize(EntityManagerInterface::class);
        $this->connection = $this->prophesize(Connection::class);
        $this->transport = new DbalTransport($this->connection->reveal(), null, ['table_name' => 'messenger']);
    }

    public function testPostGenerateSchema(): void
    {
        $event = new GenerateSchemaEventArgs($this->em->reveal(), new Schema());
        $this->transport->postGenerateSchema($event);

        self::assertTrue($event->getSchema()->hasTable('messenger'));
    }

    public function testSend(): void
    {
        $message = new class() implements DelayedMessageInterface, TTLAwareMessageInterface, UniqueMessageInterface {
            public function getDelay(): int
            {
                return 5000;
            }

            public function getTtl(): int
            {
                return 10;
            }

            public function getUniquenessKey(): string
            {
                return 'uniq';
            }
        };

        $this->connection->getDatabasePlatform()->willReturn($this->prophesize(AbstractPlatform::class));
        $this->connection->createQueryBuilder()->willReturn(new QueryBuilder($this->connection->reveal()));
        $this->connection->getExpressionBuilder()->willReturn(new ExpressionBuilder($this->connection->reveal()));

        $this->connection
            ->executeQuery('SELECT id FROM messenger WHERE (uniq_key = :uniq_key) AND (delivery_id IS NULL)', ['uniq_key' => 'uniq'], [])
            ->willReturn(new ArrayStatement([]));

        $this->connection->insert('messenger', Argument::allOf(
            Argument::withEntry('id', Argument::type('string')),
            Argument::withEntry('published_at', Argument::type(\DateTimeImmutable::class)),
            Argument::withEntry('body', '{"delay":5000,"ttl":10,"uniquenessKey":"uniq"}'),
            Argument::withEntry('headers', Argument::allOf(
                Argument::withEntry('type', \get_class($message)),
                Argument::withKey('X-Message-Stamp-Symfony\Component\Messenger\Stamp\RedeliveryStamp')
            )),
            Argument::withEntry('properties', []),
            Argument::withEntry('priority', Argument::allOf(Argument::type('int'), 0)),
            Argument::withEntry('time_to_live', Argument::type(\DateTimeImmutable::class)),
            Argument::withEntry('delayed_until', Argument::type(\DateTimeImmutable::class)),
            Argument::withEntry('uniq_key', 'uniq')
        ), [
            'id' => ParameterType::BINARY,
            'published_at' => Types::DATETIMETZ_IMMUTABLE,
            'body' => Types::TEXT,
            'headers' => Types::JSON,
            'properties' => Types::JSON,
            'priority' => Types::INTEGER,
            'time_to_live' => Types::DATETIMETZ_IMMUTABLE,
            'delayed_until' => Types::DATETIMETZ_IMMUTABLE,
            'uniq_key' => ParameterType::STRING,
        ])
            ->shouldBeCalled();

        $envelope = $this->transport->send((new Envelope($message))->with(
            new RedeliveryStamp(2)
        ));

        self::assertNotEmpty($envelope->last(TransportMessageIdStamp::class)->getId());
    }

    public function testSendShouldNotSendIfUniqueMessageIsInQueue(): void
    {
        $message = new class() implements UniqueMessageInterface {
            public function getUniquenessKey(): string
            {
                return 'uniq';
            }
        };

        $this->connection->getDatabasePlatform()->willReturn($this->prophesize(AbstractPlatform::class));
        $this->connection->createQueryBuilder()->willReturn(new QueryBuilder($this->connection->reveal()));
        $this->connection->getExpressionBuilder()->willReturn(new ExpressionBuilder($this->connection->reveal()));

        $this->connection
            ->executeQuery('SELECT id FROM messenger WHERE (uniq_key = :uniq_key) AND (delivery_id IS NULL)', ['uniq_key' => 'uniq'], [])
            ->willReturn(new ArrayStatement([
                ['id' => Uuid::uuid4()->getBytes()],
            ]));

        $this->connection->insert(Argument::cetera())->shouldNotBeCalled();
        $this->transport->send(new Envelope($message));
    }

    public function testCreateTable(): void
    {
        $this->connection->connect()->willReturn();
        $this->connection->getSchemaManager()
            ->willReturn($schemaManager = $this->prophesize(AbstractSchemaManager::class));
        $schemaManager->createSchema()->willReturn(new Schema());
        $schemaManager->createTable(Argument::type(Table::class))->shouldBeCalled();

        $this->transport->createTable();
    }

    public function testReceive(): void
    {
        $this->connection->createQueryBuilder()
            ->will(function (): QueryBuilder {
                return new QueryBuilder($this->reveal());
            });

        // Remove expired messages
        $this->connection->executeUpdate(
            'DELETE FROM messenger WHERE ((time_to_live IS NOT NULL) AND (time_to_live < :now)) AND (delivery_id IS NULL)',
            Argument::any(),
            [':now' => Types::DATETIMETZ_IMMUTABLE]
        )->shouldBeCalled();

        // Re-deliver old messages
        $this->connection->executeUpdate(
            'UPDATE messenger SET delivery_id = :deliveryId WHERE (redeliver_after < :now) AND (delivery_id IS NOT NULL)',
            Argument::any(),
            [':now' => Types::DATETIMETZ_IMMUTABLE]
        )->shouldBeCalled();

        $this->connection->getDatabasePlatform()->willReturn($platform = $this->prophesize(AbstractPlatform::class));

        $codec = new OrderedTimeCodec((new UuidFactory())->getUuidBuilder());
        $messageId = $codec->encodeBinary(Uuid::uuid1());
        $this->connection->executeQuery(
            'SELECT id FROM messenger WHERE (delayed_until IS NULL OR delayed_until <= :delayedUntil) AND (delivery_id IS NULL) ORDER BY priority asc, published_at asc, id asc LIMIT 1',
            Argument::any(),
            [':delayedUntil' => Types::DATETIMETZ_IMMUTABLE]
        )
            ->shouldBeCalled()
            ->willReturn(new ArrayStatement([
                ['id' => $messageId],
            ]));

        $deliveryId = null;
        $this->connection->executeUpdate(
            'UPDATE messenger SET delivery_id = :deliveryId, redeliver_after = :redeliverAfter WHERE (id = :messageId) AND (delivery_id IS NULL)',
            Argument::that(function ($arg) use (&$deliveryId): bool {
                self::assertArrayHasKey(':messageId', $arg);
                self::assertArrayHasKey(':deliveryId', $arg);

                $deliveryId = $arg[':deliveryId'];

                return true;
            }),
            [
                ':deliveryId' => ParameterType::BINARY,
                ':redeliverAfter' => Types::DATETIMETZ_IMMUTABLE,
                ':messageId' => ParameterType::BINARY,
            ]
        )
            ->willReturn(1)
            ->shouldBeCalled();

        $this->connection->executeQuery(
            'SELECT * FROM messenger WHERE delivery_id = :deliveryId LIMIT 1',
            Argument::that(function ($arg) use (&$deliveryId): bool {
                self::assertEquals([':deliveryId' => $deliveryId], $arg);

                return true;
            }),
            [':deliveryId' => ParameterType::BINARY]
        )
            ->willReturn(new ArrayStatement([
                [
                    'id' => $messageId,
                    'published_at' => new \DateTimeImmutable(),
                    'body' => '{}',
                    'headers' => '{"type":"stdClass"}',
                ],
            ]))
            ->shouldBeCalled();

        $this->connection->delete(Argument::cetera())->willReturn();

        \iterator_to_array($this->transport->get());
    }
}
