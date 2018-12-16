<?php declare(strict_types=1);

namespace Kcs\MessengerExtra\DependencyInjection\Compiler;

use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class RegisterDoctrineEvents implements CompilerPassInterface
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
                throw new InvalidConfigurationException(\sprintf('"%s" is not a valid URL', $url));
            }

            if ('doctrine' === $urlParams['scheme']) {
                $definition->addTag('doctrine.event_listener', [
                    'event' => 'postGenerateSchema',
                    'connection' => $urlParams['host'] ?? 'default',
                ]);
            }
        }
    }
}
