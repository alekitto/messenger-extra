<?php declare(strict_types=1);

namespace Kcs\MessengerExtra\Transport\Null;

use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Serializer Messenger Transport Factory to create blackhole transport.
 *
 * @author Alessandro Chitolina <alekitto@gmail.com>
 */
class NullTransportFactory implements TransportFactoryInterface
{
    public function createTransport(string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        return new NullTransport();
    }

    public function supports(string $dsn, array $options): bool
    {
        return 0 === \strpos($dsn, 'null:');
    }
}
