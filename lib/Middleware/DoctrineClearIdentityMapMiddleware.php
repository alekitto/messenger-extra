<?php

declare(strict_types=1);

namespace Kcs\MessengerExtra\Middleware;

use Doctrine\Common\Persistence\ManagerRegistry as ManagerRegistryV2;
use Doctrine\Persistence\ManagerRegistry as ManagerRegistryV3;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Middleware that clears the doctrine ORM identity map after processing a message.
 */
class DoctrineClearIdentityMapMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly ManagerRegistryV2|ManagerRegistryV3|null $doctrine = null)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $return = $stack->next()->handle($envelope, $stack);
        if ($this->doctrine !== null && $envelope->all(ReceivedStamp::class)) {
            // it's a received message
            foreach ($this->doctrine->getManagers() as $manager) {
                $manager->clear();
            }
        }

        return $return;
    }
}
