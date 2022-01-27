<?php declare(strict_types=1);

namespace Kcs\MessengerExtra\Tests\Transport\Mongo;

use Kcs\MessengerExtra\Transport\Mongo\MongoTransport;
use Kcs\MessengerExtra\Transport\Mongo\MongoTransportFactory;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

class MongoTransportFactoryTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @var SerializerInterface|ObjectProphecy
     */
    private ObjectProphecy $serializer;
    private MongoTransportFactory $transportFactory;

    protected function setUp(): void
    {
        $this->serializer = $this->prophesize(SerializerInterface::class);
        $this->transportFactory = new MongoTransportFactory();
    }

    public function testSupports(): void
    {
        self::assertTrue($this->transportFactory->supports('mongodb:', []));
        self::assertTrue($this->transportFactory->supports('mongodb://mongodb1.example.com:27317,mongodb2.example.com:27017/?connectTimeoutMS=300000&replicaSet=mySet&authSource=aDifferentAuthDB', []));
        self::assertTrue($this->transportFactory->supports('mongodb+srv:', []));
        self::assertTrue($this->transportFactory->supports('mongodb+srv://server.example.com/?connectTimeoutMS=300000&authSource=aDifferentAuthDB', []));

        self::assertFalse($this->transportFactory->supports('sqs://localhost', []));
        self::assertFalse($this->transportFactory->supports('invalid-dsn', []));
    }

    public function testCreateShouldCreateDatabaseConnection(): void
    {
        $transport = $this->transportFactory->createTransport('mongodb://localhost:27017/database/collection', [], $this->serializer->reveal());
        self::assertInstanceOf(MongoTransport::class, $transport);
    }
}
