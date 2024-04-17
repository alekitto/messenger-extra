<?php

declare(strict_types=1);

namespace Kcs\MessengerExtra\DependencyInjection\Compiler;

use Kcs\MessengerExtra\Transport\Dbal\DbalTransportFactory;
use LogicException;
use Ramsey\Uuid\UuidInterface;
use Safe\Exceptions\UrlException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Uid\Uuid as SymfonyUuid;

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
                && ! class_exists(UuidInterface::class)
                && ! class_exists(SymfonyUuid::class)
            ) {
                throw new LogicException('Please install symfony/uid or ramsey/uuid package to use dbal transport');
            }

            if ($scheme === 'mongodb' && ! class_exists(SymfonyUuid::class) && ! interface_exists(UuidInterface::class)) {
                throw new LogicException('Please install symfony/uid or ramsey/uuid package to use mongodb transport');
            }
        }
    }
}
