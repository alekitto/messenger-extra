<?php declare(strict_types=1);

namespace Kcs\MessengerExtra\DependencyInjection;

use Kcs\Serializer;
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
    }
}
