<?php declare(strict_types=1);

namespace Kcs\MessengerExtra\Tests\Transport\Dbal;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\Persistence\ManagerRegistry;
use Kcs\MessengerExtra\Transport\Dbal\DbalTransport;
use Kcs\MessengerExtra\Transport\Dbal\DbalTransportFactory;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

class DbalTransportFactoryTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @var ManagerRegistry|ObjectProphecy
     */
    private ObjectProphecy $managerRegistry;

    /**
     * @var SerializerInterface|ObjectProphecy
     */
    private ObjectProphecy $serializer;
    private DbalTransportFactory $transportFactory;

    protected function setUp(): void
    {
        $this->managerRegistry = $this->prophesize(ManagerRegistry::class);
        $this->serializer = $this->prophesize(SerializerInterface::class);
        $this->transportFactory = new DbalTransportFactory($this->managerRegistry->reveal());
    }

    public function testSupports(): void
    {
        self::assertTrue($this->transportFactory->supports('doctrine://default', []));
        self::assertTrue($this->transportFactory->supports('doctrine:', []));
        self::assertTrue($this->transportFactory->supports('db2:', []));
        self::assertTrue($this->transportFactory->supports('mssql:', []));
        self::assertTrue($this->transportFactory->supports('mysql:', []));
        self::assertTrue($this->transportFactory->supports('mysql2:', []));
        self::assertTrue($this->transportFactory->supports('postgres:', []));
        self::assertTrue($this->transportFactory->supports('postgresql:', []));
        self::assertTrue($this->transportFactory->supports('pgsql:', []));
        self::assertTrue($this->transportFactory->supports('sqlite:', []));
        self::assertTrue($this->transportFactory->supports('sqlite3:', []));

        self::assertFalse($this->transportFactory->supports('sqs://localhost', []));
        self::assertFalse($this->transportFactory->supports('invalid-dsn', []));
    }

    public function testCreateTransportShouldNotUseExistentConnection(): void
    {
        $this->managerRegistry->getConnection('connection_name')
            ->shouldBeCalled()
            ->willReturn($connection = $this->prophesize(Connection::class));
        $connection->getParams()->willReturn(['url' => 'sqlite:///'.__DIR__.'/queue.db']);
        $connection->getConfiguration()->willReturn(new Configuration());
        $connection->getEventManager()->willReturn(new EventManager());

        $transport = $this->transportFactory->createTransport('doctrine://connection_name', [], $this->serializer->reveal());

        self::assertInstanceOf(DbalTransport::class, $transport);
    }

    public function testCreateShouldThrowIfNoRegistryIsPassed(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $transportFactory = new DbalTransportFactory(null);
        $transportFactory->createTransport('doctrine://connection_name', [], $this->serializer->reveal());
    }

    public function testCreateShouldCreateDatabaseConnection(): void
    {
        $this->managerRegistry->getConnection(Argument::any())
            ->shouldNotBeCalled();

        $transport = $this->transportFactory->createTransport('mysql://localhost/table_name', [], $this->serializer->reveal());
        self::assertInstanceOf(DbalTransport::class, $transport);
    }

    public function testCreateShouldHandleSqlitePathsCorrectly(): void
    {
        @\unlink(__DIR__.'/queue.db');

        $this->managerRegistry->getConnection(Argument::any())
            ->shouldNotBeCalled();

        $transport = $this->transportFactory->createTransport('sqlite:///'.__DIR__.'/queue.db/table_name', [], $this->serializer->reveal());
        self::assertInstanceOf(DbalTransport::class, $transport);

        $transport->createTable();

        $connection = DriverManager::getConnection(['url' => 'sqlite:///'.__DIR__.'/queue.db']);
        $schema = $connection->getSchemaManager()->createSchema();
        self::assertTrue($schema->hasTable('table_name'));

        $connection->close();
        unset($connection, $transport);

        @\unlink(__DIR__.'/queue.db');
    }
}
