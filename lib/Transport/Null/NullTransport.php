<?php

declare(strict_types=1);

namespace Kcs\MessengerExtra\Transport\Null;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Blackhole Serializer Messenger Transport.
 */
class NullTransport implements TransportInterface
{
    /**
     * {@inheritDoc}
     */
    public function get(): iterable
    {
        return [];
    }

    public function ack(Envelope $envelope): void
    {
        // Do nothing.
    }

    public function reject(Envelope $envelope): void
    {
        // Do nothing.
    }

    public function send(Envelope $envelope): Envelope
    {
        return $envelope;
    }
}
