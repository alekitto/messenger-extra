<?php declare(strict_types=1);

namespace Kcs\MessengerExtra;

use Kcs\MessengerExtra\DependencyInjection\Compiler;
use Kcs\MessengerExtra\DependencyInjection\MessengerExtraExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class MessengerExtraBundle extends Bundle
{
    /**
     * {@inheritdoc}
     */
    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new Compiler\RegisterDoctrineEvents());
    }

    /**
     * {@inheritdoc}
     */
    protected function getContainerExtensionClass(): string
    {
        return MessengerExtraExtension::class;
    }
}
