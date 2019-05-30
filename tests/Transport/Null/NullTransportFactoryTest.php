<?php declare(strict_types=1);

namespace Kcs\MessengerExtra\Tests\Transport\Null;

use Kcs\MessengerExtra\Transport\Null\NullTransport;
use Kcs\MessengerExtra\Transport\Null\NullTransportFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

class NullTransportFactoryTest extends TestCase
{
    public function testShouldSupportOnlyNullScheme(): void
    {
        $factory = new NullTransportFactory();

        self::assertTrue($factory->supports('null://localhost', []));
        self::assertTrue($factory->supports('null:', []));
        self::assertFalse($factory->supports('sqs://localhost', []));
        self::assertFalse($factory->supports('invalid-dsn', []));
    }

    public function testCreateTransport(): void
    {
        $factory = new NullTransportFactory();
        $transport = $factory->createTransport('null:', [], $this->prophesize(SerializerInterface::class)->reveal());

        self::assertInstanceOf(NullTransport::class, $transport);
    }
}
