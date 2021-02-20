<?php

declare(strict_types=1);

namespace Kcs\MessengerExtra;

use Kcs\MessengerExtra\DependencyInjection\Compiler;
use Kcs\MessengerExtra\DependencyInjection\MessengerExtraExtension;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class MessengerExtraBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        $container
            ->addCompilerPass(new Compiler\AddSerializerAliasPass())
            ->addCompilerPass(new Compiler\RegisterDoctrineEventsPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 50)
            ->addCompilerPass(new Compiler\SerializerContextConfigurationPass())
            ->addCompilerPass(new Compiler\RemoveDefaultDoctrineTransportPass());

        if (! $container->getParameter('kernel.debug')) {
            return;
        }

        $container->addCompilerPass(new Compiler\CheckDependencyPass(), PassConfig::TYPE_AFTER_REMOVING);
    }

    protected function getContainerExtensionClass(): string
    {
        return MessengerExtraExtension::class;
    }
}
