<?php declare(strict_types=1);

namespace Kcs\MessengerExtra\Transport\Null;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Blackhole Serializer Messenger Transport.
 *
 * @author Alessandro Chitolina <alekitto@gmail.com>
 */
class NullTransport implements TransportInterface
{
    /**
     * {@inheritdoc}
     */
    public function receive(callable $handler): void
    {
        // Do nothing
    }

    /**
     * {@inheritdoc}
     */
    public function stop(): void
    {
        // Do nothing
    }

    /**
     * {@inheritdoc}
     */
    public function send(Envelope $envelope): Envelope
    {
        return $envelope;
    }
}
