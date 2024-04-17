<?php declare(strict_types=1);

namespace Kcs\MessengerExtra\Tests\Transport\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Query\Expression\ExpressionBuilder;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\SQL\Builder\DefaultSelectSQLBuilder;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Kcs\MessengerExtra\Message\DelayedMessageInterface;
use Kcs\MessengerExtra\Message\TTLAwareMessageInterface;
use Kcs\MessengerExtra\Message\UniqueMessageInterface;
use Kcs\MessengerExtra\Transport\Dbal\DbalTransport;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Ramsey\Uuid\Codec\OrderedTimeCodec;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidFactory;
use Refugis\DoctrineExtra\DBAL\DummyResult;
use Refugis\DoctrineExtra\DBAL\DummyStatement;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

class DbalTransportTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @var EntityManagerInterface|ObjectProphecy
     */
    private ObjectProphecy $em;

    /**
     * @var Connection|ObjectProphecy
     */
    private ObjectProphecy $connection;
    private DbalTransport $transport;

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

        $platform = $this->prophesize(AbstractPlatform::class);
        $platform->createSelectSQLBuilder()->willReturn(new DefaultSelectSQLBuilder($platform->reveal(), null, null));

        $this->connection->getDatabasePlatform()->willReturn($platform);
        $this->connection->createQueryBuilder()->willReturn(new QueryBuilder($this->connection->reveal()));
        $this->connection->createExpressionBuilder()->willReturn(new ExpressionBuilder($this->connection->reveal()));

        $this->connection
            ->executeQuery('SELECT id FROM messenger WHERE (uniq_key = :uniq_key) AND (delivery_id IS NULL)', ['uniq_key' => 'uniq'], Argument::cetera())
            ->willReturn($this->createResultObject([]));

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

        $platform = $this->prophesize(AbstractPlatform::class);
        $platform->createSelectSQLBuilder()->willReturn(new DefaultSelectSQLBuilder($platform->reveal(), null, null));

        $this->connection->getDatabasePlatform()->willReturn($platform->reveal());
        $this->connection->createQueryBuilder()->willReturn(new QueryBuilder($this->connection->reveal()));
        $this->connection->createExpressionBuilder()->willReturn(new ExpressionBuilder($this->connection->reveal()));

        $this->connection
            ->executeQuery('SELECT id FROM messenger WHERE (uniq_key = :uniq_key) AND (delivery_id IS NULL)', ['uniq_key' => 'uniq'], Argument::cetera())
            ->willReturn($this->createResultObject([
                ['id' => Uuid::uuid4()->getBytes()],
            ]));

        $this->connection->insert(Argument::cetera())->shouldNotBeCalled();
        $this->transport->send(new Envelope($message));
    }

    public function testCreateTable(): void
    {
        $this->connection->connect()->willReturn();
        $this->connection->createSchemaManager()
            ->willReturn($schemaManager = $this->prophesize(AbstractSchemaManager::class));

        $schemaManager->createSchema()->willReturn(new Schema());
        $schemaManager->createTable(Argument::type(Table::class))->shouldBeCalled();

        $this->transport->createTable();
    }

    public function testFindWithHexId(): void
    {
        $connection = $this->connection->reveal();

        $platform = $this->prophesize(AbstractPlatform::class);
        $platform->createSelectSQLBuilder()->willReturn(new DefaultSelectSQLBuilder($platform->reveal(), null, null));

        $this->connection->getDatabasePlatform()->willReturn($platform->reveal());
        $this->connection->createQueryBuilder()
            ->will(function () use ($connection) { return new QueryBuilder($connection); });

        $id = '0124dfeea3f56c';
        $this->connection
            ->executeQuery(
                'SELECT body, headers, id FROM messenger WHERE id = :identifier LIMIT 1',
                ['identifier' => hex2bin($id)],
                Argument::withEntry('identifier', ParameterType::BINARY),
                Argument::cetera(),
            )
            ->willReturn($this->createResultObject([
                ['id' => hex2bin($id), 'body' => '{}', 'headers' => '{"type":"stdClass"}'],
            ]));

        $message = $this->transport->find('0124dfeea3f56c');
        self::assertNotNull($message);

        $stamp = $message->last(TransportMessageIdStamp::class);
        self::assertEquals($id, $stamp->getId());
    }

    public function testReceive(): void
    {
        $this->connection->createQueryBuilder()
            ->will(function (): QueryBuilder {
                return new QueryBuilder($this->reveal());
            });

        $method = PHP_VERSION_ID >= 70300 ? 'executeStatement' : 'executeUpdate';

        // Remove expired messages
        $this->connection->$method(
            'DELETE FROM messenger WHERE ((time_to_live IS NOT NULL) AND (time_to_live < :now)) AND (delivery_id IS NULL)',
            Argument::any(),
            ['now' => Types::DATETIMETZ_IMMUTABLE]
        )->shouldBeCalled()->willReturn(0);

        // Re-deliver old messages
        $this->connection->$method(
            'UPDATE messenger SET delivery_id = :deliveryId WHERE (redeliver_after < :now) AND (delivery_id IS NOT NULL)',
            Argument::any(),
            Argument::withEntry('now', Types::DATETIMETZ_IMMUTABLE),
        )->shouldBeCalled()->willReturn(0);

        $platform = $this->prophesize(AbstractPlatform::class);
        $platform->createSelectSQLBuilder()->willReturn(new DefaultSelectSQLBuilder($platform->reveal(), null, null));

        $this->connection->getDatabasePlatform()->willReturn($platform->reveal());

        $codec = new OrderedTimeCodec((new UuidFactory())->getUuidBuilder());
        $messageId = $codec->encodeBinary(Uuid::uuid1());
        $this->connection->executeQuery(
            'SELECT id FROM messenger WHERE (delayed_until IS NULL OR delayed_until <= :delayedUntil) AND (delivery_id IS NULL) ORDER BY priority asc, published_at asc, id asc LIMIT 1',
            Argument::any(),
            Argument::withEntry('delayedUntil', Types::DATETIMETZ_IMMUTABLE),
            Argument::cetera(),
        )
            ->shouldBeCalled()
            ->willReturn($this->createResultObject([
                ['id' => $messageId],
            ]));

        $deliveryId = null;
        $this->connection->$method(
            'UPDATE messenger SET delivery_id = :deliveryId, redeliver_after = :redeliverAfter WHERE (id = :messageId) AND (delivery_id IS NULL)',
            Argument::that(function ($arg) use (&$deliveryId): bool {
                self::assertArrayHasKey('messageId', $arg);
                self::assertArrayHasKey('deliveryId', $arg);

                $deliveryId = $arg['deliveryId'];

                return true;
            }),
            [
                'deliveryId' => ParameterType::BINARY,
                'redeliverAfter' => Types::DATETIMETZ_IMMUTABLE,
                'messageId' => ParameterType::BINARY,
            ]
        )
            ->willReturn(1)
            ->shouldBeCalled();

        $this->connection->executeQuery(
            'SELECT body, headers, id, time_to_live FROM messenger WHERE delivery_id = :deliveryId LIMIT 1',
            Argument::that(function ($arg) use (&$deliveryId): bool {
                self::assertEquals(['deliveryId' => $deliveryId], $arg);

                return true;
            }),
            Argument::withEntry('deliveryId', ParameterType::BINARY),
            Argument::cetera(),
        )
            ->willReturn($this->createResultObject([
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

    private function createResultObject(array $data): object
    {
        return new Result(new DummyResult($data), $this->connection->reveal());
    }
}
