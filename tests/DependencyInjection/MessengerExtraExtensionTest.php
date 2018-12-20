<?php declare(strict_types=1);

namespace Kcs\MessengerExtra\Tests\DependencyInjection;

use Kcs\MessengerExtra\Adapter\Serializer\MessengerSerializer;
use Kcs\MessengerExtra\DependencyInjection\MessengerExtraExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\DependencyInjection\FrameworkExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class MessengerExtraExtensionTest extends TestCase
{
    public function testShouldRegisterServices(): void
    {
        $extension = new MessengerExtraExtension();
        $extension->load([], $container = new ContainerBuilder());

        self::assertTrue($container->hasDefinition('kcs.messenger_extra.transport.null.factory'));
        self::assertTrue($container->hasDefinition('kcs.messenger_extra.transport.dbal.factory'));
        self::assertTrue($container->hasDefinition('kcs.messenger_extra.transport.mongodb.factory'));
    }

    public function testShouldRegisterShouldReadMessengerSerializerConfig(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        $container->registerExtension(new FrameworkExtension());
        $container->loadFromExtension('framework', [
            'messenger' => [
                'serializer' => [
                    'id' => MessengerSerializer::class,
                    'context' => [
                        'groups' => [ 'messenger' ],
                    ],
                ],
            ],
        ]);

        $extension = new MessengerExtraExtension();
        $extension->load([], $container);

        $def = $container->getDefinition(MessengerSerializer::class);
        self::assertEquals(['groups' => ['messenger']], $def->getArgument(2));
    }
}
