<?php declare(strict_types=1);

namespace Kcs\MessengerExtra\DependencyInjection\Compiler;

use Kcs\MessengerExtra\Transport\Dbal\DbalTransport;
use Kcs\MessengerExtra\Transport\Mongo\MongoTransport;
use Ramsey\Uuid\Doctrine\UuidBinaryType;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class CheckDependencyPass implements CompilerPassInterface
{
    /**
     * @inheritDoc
     */
    public function process(ContainerBuilder $container): void
    {
        foreach ($container->findTaggedServiceIds('messenger.receiver') as $serviceId => $tags) {
            $service = $container->get($serviceId);

            if ($service instanceof DbalTransport && ! \class_exists(UuidBinaryType::class)) {
                throw new \LogicException('Please install ramsey/uuid-doctrine package to use dbal transport');
            }

            if ($service instanceof MongoTransport && ! \interface_exists(UuidInterface::class)) {
                throw new \LogicException('Please install ramsey/uuid package to use mongodb transport');
            }
        }
    }
}
