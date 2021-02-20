<?php

declare(strict_types=1);

namespace Kcs\MessengerExtra\DependencyInjection\Compiler;

use Safe\Exceptions\UrlException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function Safe\parse_url;

class RegisterDoctrineEventsPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        foreach ($container->findTaggedServiceIds('messenger.receiver') as $serviceId => $tags) {
            $definition = $container->getDefinition($serviceId);
            $url = $container->resolveEnvPlaceholders($definition->getArgument(0), true);

            try {
                $urlParams = parse_url($url);
            } catch (UrlException $e) {
                continue;
            }

            if (! (($urlParams['scheme'] ?? '') === 'doctrine')) {
                continue;
            }

            $definition->addTag('doctrine.event_listener', [
                'event' => 'postGenerateSchema',
                'connection' => $urlParams['host'] ?? 'default',
            ]);
        }
    }
}
