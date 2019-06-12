<?php declare(strict_types=1);

namespace Kcs\MessengerExtra\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class RemoveDefaultDoctrineTransportPass implements CompilerPassInterface
{
    /**
     * {@inheritdoc}
     */
    public function process(ContainerBuilder $container): void
    {
        $container->removeDefinition('messenger.transport.doctrine.factory');
    }
}
