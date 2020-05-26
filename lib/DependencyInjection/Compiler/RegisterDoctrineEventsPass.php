<?php declare(strict_types=1);

namespace Kcs\MessengerExtra\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class RegisterDoctrineEventsPass implements CompilerPassInterface
{
    /**
     * {@inheritdoc}
     */
    public function process(ContainerBuilder $container): void
    {
        foreach ($container->findTaggedServiceIds('messenger.receiver') as $serviceId => $tags) {
            $definition = $container->getDefinition($serviceId);
            $url = $container->resolveEnvPlaceholders($definition->getArgument(0), true);
            $urlParams = \parse_url($url);

            if (false === $urlParams) {
                continue;
            }

            if ('doctrine' === ($urlParams['scheme'] ?? '')) {
                $definition->addTag('doctrine.event_listener', [
                    'event' => 'postGenerateSchema',
                    'connection' => $urlParams['host'] ?? 'default',
                ]);
            }
        }
    }
}
