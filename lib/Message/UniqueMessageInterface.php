<?php declare(strict_types=1);

namespace Kcs\MessengerExtra\Message;

/**
 * Represents a message which should be delivered only once if added to the queue multiple times.
 *
 * @author Alessandro Chitolina <alekitto@gmail.com>
 */
interface UniqueMessageInterface
{
    /**
     * The uniqueness key of this message.
     * Will be used to determine if another message is present in the queue.
     */
    public function getUniquenessKey(): string;
}
