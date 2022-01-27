<?php

declare(strict_types=1);

namespace Kcs\MessengerExtra\DependencyInjection\Compiler;

use Kcs\MessengerExtra\Adapter\Serializer\MessengerSerializer;
use Symfony\Bundle\FrameworkBundle\DependencyInjection\Configuration;
use Symfony\Bundle\FrameworkBundle\DependencyInjection\FrameworkExtension;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function assert;

class SerializerContextConfigurationPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (! $container->hasExtension('framework')) {
            return;
        }

        $framework = $container->getExtension('framework');
        assert($framework instanceof FrameworkExtension);

        $frameworkConfigs = $container->getExtensionConfig('framework');
        $frameworkConfigs = $container->resolveEnvPlaceholders($frameworkConfigs, true);

        $processor = new Processor();
        $frameworkBundleConfiguration = $framework->getConfiguration([], $container);
        assert($frameworkBundleConfiguration instanceof Configuration);

        $config = $processor->processConfiguration($frameworkBundleConfiguration, $frameworkConfigs);
        if (
            ! (($config['messenger']['serializer']['id'] ?? '') === MessengerSerializer::class) ||
            empty($config['messenger']['serializer']['context'])
        ) {
            return;
        }

        $definition = $container->getDefinition(MessengerSerializer::class);
        $definition->replaceArgument(2, $config['messenger']['serializer']['context']);
    }
}
