<?php declare(strict_types=1);

namespace Kcs\MessengerExtra\DependencyInjection\Compiler;

use Kcs\MessengerExtra\Adapter\Serializer\MessengerSerializer;
use Symfony\Bundle\FrameworkBundle\DependencyInjection\FrameworkExtension;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class SerializerContextConfigurationPass implements CompilerPassInterface
{
    /**
     * {@inheritdoc}
     */
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasExtension('framework')) {
            /** @var FrameworkExtension $framework */
            $framework = $container->getExtension('framework');
            $resolvingBag = $container->getParameterBag();

            $frameworkConfigs = $container->getExtensionConfig('framework');
            $frameworkConfigs = $resolvingBag->resolveValue($frameworkConfigs);

            $processor = new Processor();
            $config = $processor->processConfiguration($framework->getConfiguration([], $container), $frameworkConfigs);
            if (
                MessengerSerializer::class === ($config['messenger']['serializer']['id'] ?? '') &&
                ! empty($config['messenger']['serializer']['context'])
            ) {
                $definition = $container->getDefinition(MessengerSerializer::class);
                $definition->replaceArgument(2, $config['messenger']['serializer']['context']);
            }
        }
    }
}
