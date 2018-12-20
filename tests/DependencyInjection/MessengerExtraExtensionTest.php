<?php declare(strict_types=1);

namespace Kcs\MessengerExtra\Tests\DependencyInjection;

use Kcs\MessengerExtra\DependencyInjection\MessengerExtraExtension;
use PHPUnit\Framework\TestCase;
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
}
