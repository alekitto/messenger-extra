<?php declare(strict_types=1);

namespace Kcs\MessengerExtra\Message;

/**
 * Represents a message that should expired after a TTL period has been passed.
 *
 * @author Alessandro Chitolina <alekitto@gmail.com>
 */
interface TTLAwareMessageInterface
{
    /**
     * Gets the time-to-live (in seconds) for this message.
     */
    public function getTtl(): int;
}
