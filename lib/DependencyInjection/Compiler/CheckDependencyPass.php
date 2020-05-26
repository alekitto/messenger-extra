<?php declare(strict_types=1);

namespace Kcs\MessengerExtra\DependencyInjection\Compiler;

use Kcs\MessengerExtra\Transport\Dbal\DbalTransportFactory;
use Ramsey\Uuid\Doctrine\UuidBinaryType;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class CheckDependencyPass implements CompilerPassInterface
{
    /**
     * {@inheritdoc}
     */
    public function process(ContainerBuilder $container): void
    {
        foreach ($container->findTaggedServiceIds('messenger.receiver') as $serviceId => $tags) {
            $service = $container->getDefinition($serviceId);
            $url = $container->resolveEnvPlaceholders($service->getArgument(0), true);
            $urlParams = \parse_url($url);

            if (false === $urlParams) {
                continue;
            }

            $scheme = $urlParams['scheme'] ?? null;
            if (null === $scheme) {
                return;
            }

            if (('doctrine' === $scheme || \in_array($scheme, DbalTransportFactory::DBAL_SUPPORTED_SCHEMES, true))
                && ! \class_exists(UuidBinaryType::class)) {
                throw new \LogicException('Please install ramsey/uuid-doctrine package to use dbal transport');
            }

            if ('mongodb' === $scheme && ! \interface_exists(UuidInterface::class)) {
                throw new \LogicException('Please install ramsey/uuid package to use mongodb transport');
            }
        }
    }
}
