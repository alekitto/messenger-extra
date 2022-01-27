<?php

declare(strict_types=1);

namespace Kcs\MessengerExtra\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class AddSerializerAliasPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $alias = new Alias('messenger.transport.symfony_serializer');
        if ($container->hasDefinition('messenger.transport.serializer') || $container->hasAlias('messenger.transport.serializer')) {
            $alias = new Alias('messenger.transport.serializer');
        }

        $container->setAlias('kcs.messenger_extra.serializer', $alias);
    }
}
