<?php declare(strict_types=1);

namespace Kcs\MessengerExtra\Tests\Transport\Mongo;

use Kcs\MessengerExtra\Transport\Mongo\MongoTransport;
use Kcs\MessengerExtra\Transport\Mongo\MongoTransportFactory;
use PHPUnit\Framework\TestCase;
use Prophecy\Prophecy\ObjectProphecy;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

class MongoTransportFactoryTest extends TestCase
{
    /**
     * @var SerializerInterface|ObjectProphecy
     */
    private $serializer;

    /**
     * @var MongoTransportFactory
     */
    private $transportFactory;

    protected function setUp(): void
    {
        $this->serializer = $this->prophesize(SerializerInterface::class);
        $this->transportFactory = new MongoTransportFactory();
    }

    public function testSupports(): void
    {
        self::assertTrue($this->transportFactory->supports('mongodb:', []));

        self::assertFalse($this->transportFactory->supports('sqs://localhost', []));
        self::assertFalse($this->transportFactory->supports('invalid-dsn', []));
    }

    public function testCreateShouldCreateDatabaseConnection(): void
    {
        $transport = $this->transportFactory->createTransport('mongodb://localhost:27017/database/collection', [], $this->serializer->reveal());
        self::assertInstanceOf(MongoTransport::class, $transport);
    }
}
