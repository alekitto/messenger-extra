<?php

declare(strict_types=1);

namespace Kcs\MessengerExtra\Message;

/**
 * Represents a message which should be delivered after a specified delay.
 */
interface DelayedMessageInterface
{
    /**
     * Gets the delay (in milliseconds) that must elapse for this message to be
     * delivered after it was sent.
     */
    public function getDelay(): int;
}
