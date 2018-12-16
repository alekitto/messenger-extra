<?php declare(strict_types=1);

namespace Kcs\MessengerExtra\Tests\Adapter\Serializer;

use Kcs\MessengerExtra\Adapter\Serializer\Serializer;
use Kcs\MessengerExtra\Tests\Fixtures\GetSetObject;
use Kcs\Serializer\DeserializationContext;
use Kcs\Serializer\SerializationContext;
use Kcs\Serializer\SerializerInterface;
use Kcs\Serializer\Type\Type;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;

class SerializerTest extends TestCase
{
    /**
     * @var SerializerInterface|ObjectProphecy
     */
    private $serializer;

    /**
     * @var Serializer
     */
    private $adapter;

    /**
     * {@inheritdoc}
     */
    protected function setUp(): void
    {
        $this->serializer = $this->prophesize(SerializerInterface::class);
        $this->adapter = new Serializer($this->serializer->reveal());
    }

    public function testSerializeShouldCallSerialize(): void
    {
        $obj = new \stdClass();

        $this->serializer->serialize($obj, 'json', Argument::type(SerializationContext::class))
            ->shouldBeCalled()
            ->willReturn('{}')
        ;

        self::assertEquals('{}', $this->adapter->serialize($obj, 'json'));
    }

    public function testSerializeShouldForwardSerializationGroups(): void
    {
        $obj = new \stdClass();

        $this->serializer
            ->serialize($obj, 'json', Argument::that(function (SerializationContext $context): bool {
                self::assertEquals(['group1', 'group2'], $context->attributes->get('groups'));

                return true;
            }))
            ->shouldBeCalled()
            ->willReturn('{}')
        ;

        $this->adapter->serialize($obj, 'json', ['groups' => ['group1', 'group2']]);
    }

    public function testDeserializeShouldCallDeserialize(): void
    {
        $obj = new \stdClass();

        $this->serializer
            ->deserialize(
                '{}',
                new Type('stdClass'),
                'json',
                Argument::type(DeserializationContext::class)
            )
            ->shouldBeCalled()
            ->willReturn($obj)
        ;

        self::assertEquals($obj, $this->adapter->deserialize('{}', \stdClass::class, 'json'));
    }

    public function testDeserializeShouldForwardDeserializationGroups(): void
    {
        $obj = new \stdClass();

        $this->serializer
            ->deserialize(
                '{}',
                new Type('stdClass'),
                'json',
                Argument::that(function (DeserializationContext $context): bool {
                    self::assertEquals(['group1', 'group2'], $context->attributes->get('groups'));

                    return true;
                })
            )
            ->shouldBeCalled()
            ->willReturn($obj)
        ;

        self::assertEquals($obj, $this->adapter->deserialize(
            '{}',
            \stdClass::class,
            'json',
            ['groups' => ['group1', 'group2']]
        ));
    }

    public function testDeserializeShouldForwardTargetObject(): void
    {
        $obj = new GetSetObject();

        $this->serializer
            ->deserialize(
                '{}',
                new Type(GetSetObject::class),
                'json',
                Argument::that(function (DeserializationContext $context) use ($obj): bool {
                    self::assertSame($obj, $context->attributes->get('target'));

                    return true;
                })
            )
            ->shouldBeCalled()
            ->willReturn($obj)
        ;

        self::assertEquals($obj, $this->adapter->deserialize(
            '{}',
            GetSetObject::class,
            'json',
            ['object_to_populate' => $obj]
        ));
    }
}
