<?php declare(strict_types=1);

namespace Kcs\MessengerExtra\Message;

interface PriorityAwareMessageInterface
{
    /**
     * Gets the priority of the message.
     * Higher value means more priority (and would be picked up first from the queue).
     *
     * All the messages that are not implementing this interface are
     * published with priority = 0
     */
    public function getPriority(): int;
}
