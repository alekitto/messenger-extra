<?php declare(strict_types=1);

namespace Kcs\MessengerExtra\DependencyInjection;

use Doctrine\DBAL\Types\Type;
use Kcs\MessengerExtra\Adapter\Serializer\MessengerSerializer;
use Kcs\Serializer;
use MongoDB\Client;
use Symfony\Bundle\FrameworkBundle\DependencyInjection\FrameworkExtension;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;

class MessengerExtraExtension extends Extension
{
    /**
     * {@inheritdoc}
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new XmlFileLoader($container, new FileLocator(__DIR__.'/Resources'));
        $loader->load('services.xml');
        $loader->load('middleware.xml');

        if (\interface_exists(Serializer\SerializerInterface::class)) {
            $loader->load('serializer.xml');
        }

        if (! \class_exists(Type::class)) {
            $container->removeDefinition('kcs.messenger_extra.transport.dbal.factory');
        }

        if (! \class_exists(Client::class)) {
            $container->removeDefinition('kcs.messenger_extra.transport.mongodb.factory');
        }

        if ($container->hasExtension('framework')) {
            /** @var FrameworkExtension $framework */
            $framework = $container->getExtension('framework');
            $frameworkConfigs = $container->getExtensionConfig('framework');

            $config = $framework->processConfiguration($framework->getConfiguration([], $container), $frameworkConfigs);
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
