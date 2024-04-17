<?php

declare(strict_types=1);

namespace Kcs\MessengerExtra\Transport\Null;

use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

use function strpos;

/**
 * Serializer Messenger Transport Factory to create blackhole transport.
 */
class NullTransportFactory implements TransportFactoryInterface
{
    /** @param array<string, mixed> $options */
    public function createTransport(string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        return new NullTransport();
    }

    /** @param array<string, mixed> $options */
    public function supports(string $dsn, array $options): bool
    {
        return strpos($dsn, 'null:') === 0;
    }
}
