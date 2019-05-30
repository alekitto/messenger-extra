<?php declare(strict_types=1);

namespace Kcs\MessengerExtra\Tests\Adapter\Serializer;

use Kcs\MessengerExtra\Adapter\Serializer\MessengerSerializer;
use Kcs\MessengerExtra\Tests\Fixtures\DummyMessage;
use Kcs\Serializer\DeserializationContext;
use Kcs\Serializer\SerializationContext;
use Kcs\Serializer\Serializer;
use Kcs\Serializer\SerializerBuilder;
use Kcs\Serializer\SerializerInterface;
use Kcs\Serializer\Type\Type;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\SerializerStamp;
use Symfony\Component\Messenger\Stamp\ValidationStamp;

class MessengerSerializerTest extends TestCase
{
    /**
     * @var Serializer
     */
    private $serializer;

    /**
     * @var MessengerSerializer
     */
    private $messengerSerializer;

    /**
     * {@inheritdoc}
     */
    protected function setUp(): void
    {
        if (! \class_exists(SerializerBuilder::class)) {
            self::markTestSkipped('Kcs serializer is not installed.');
        }

        $this->serializer = SerializerBuilder::create()->build();
        $this->messengerSerializer = new MessengerSerializer($this->serializer);
    }

    private function wrap($message): Envelope
    {
        return new Envelope($message);
    }

    public function testEncodedIsDecodable(): void
    {
        $envelope = $this->wrap(new DummyMessage('Hello'));

        self::assertEquals($envelope, $this->messengerSerializer->decode($this->messengerSerializer->encode($envelope)));
    }

    public function testEncodedWithConfigurationIsDecodable(): void
    {
        $envelope = $this
            ->wrap(new DummyMessage('Hello'))
            ->with(new SerializerStamp(['groups' => ['foo']]), new ValidationStamp(['foo', 'bar']))
        ;

        self::assertEquals($envelope, $this->messengerSerializer->decode($this->messengerSerializer->encode($envelope)));
    }

    public function testEncodedIsHavingTheBodyAndTypeHeader(): void
    {
        $encoded = $this->messengerSerializer->encode($this->wrap(new DummyMessage('Hello')));

        self::assertArrayHasKey('body', $encoded);
        self::assertArrayHasKey('headers', $encoded);
        self::assertArrayHasKey('type', $encoded['headers']);
        self::assertArrayNotHasKey('X-Message-Envelope-Items', $encoded['headers']);
        self::assertEquals(DummyMessage::class, $encoded['headers']['type']);
    }

    public function testUsesTheCustomFormatAndContext(): void
    {
        $message = new DummyMessage('Foo');

        $serializer = $this->prophesize(SerializerInterface::class);
        $serializer->serialize($message, 'csv', Argument::type(SerializationContext::class))->willReturn('Yay');
        $serializer->deserialize('Yay', new Type(DummyMessage::class), 'csv', Argument::type(DeserializationContext::class))->willReturn($message);

        $encoder = new MessengerSerializer($serializer->reveal(), 'csv', ['foo' => 'bar']);

        $encoded = $encoder->encode($this->wrap($message));
        $decoded = $encoder->decode($encoded);

        self::assertSame('Yay', $encoded['body']);
        self::assertSame($message, $decoded->getMessage());
    }

    public function testEncodedWithSerializationConfiguration(): void
    {
        $envelope = $this->wrap(new DummyMessage('Hello'))
            ->with(new SerializerStamp(['groups' => ['foo']]), new ValidationStamp(['foo', 'bar']))
        ;

        $encoded = $this->messengerSerializer->encode($envelope);

        self::assertArrayHasKey('body', $encoded);
        self::assertArrayHasKey('headers', $encoded);
        self::assertArrayHasKey('type', $encoded['headers']);
        self::assertEquals(DummyMessage::class, $encoded['headers']['type']);
        self::assertArrayHasKey('X-Message-Envelope-Items', $encoded['headers']);

        self::assertEquals(\serialize([
            SerializerStamp::class => [
                new SerializerStamp(['groups' => ['foo']]),
            ],
            ValidationStamp::class => [
                new ValidationStamp(['foo', 'bar']),
            ],
        ]), $encoded['headers']['X-Message-Envelope-Items']);
    }
}
