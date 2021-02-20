<?php

declare(strict_types=1);

namespace Kcs\MessengerExtra\DependencyInjection\Compiler;

use Kcs\MessengerExtra\Transport\Dbal\DbalTransportFactory;
use LogicException;
use Ramsey\Uuid\Doctrine\UuidBinaryType;
use Ramsey\Uuid\UuidInterface;
use Safe\Exceptions\UrlException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function class_exists;
use function in_array;
use function interface_exists;
use function Safe\parse_url;

class CheckDependencyPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        foreach ($container->findTaggedServiceIds('messenger.receiver') as $serviceId => $tags) {
            $service = $container->getDefinition($serviceId);
            $url = $container->resolveEnvPlaceholders($service->getArgument(0), true);

            try {
                $urlParams = parse_url($url);
            } catch (UrlException $e) {
                continue;
            }

            $scheme = $urlParams['scheme'] ?? null;
            if ($scheme === null) {
                return;
            }

            if (
                ($scheme === 'doctrine' || in_array($scheme, DbalTransportFactory::DBAL_SUPPORTED_SCHEMES, true))
                && ! class_exists(UuidBinaryType::class)
            ) {
                throw new LogicException('Please install ramsey/uuid-doctrine package to use dbal transport');
            }

            if ($scheme === 'mongodb' && ! interface_exists(UuidInterface::class)) {
                throw new LogicException('Please install ramsey/uuid package to use mongodb transport');
            }
        }
    }
}
