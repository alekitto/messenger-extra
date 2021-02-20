<?php

declare(strict_types=1);

namespace Kcs\MessengerExtra\Middleware;

use Doctrine\Common\Persistence\ManagerRegistry as ManagerRegistryV2;
use Doctrine\Persistence\ManagerRegistry as ManagerRegistryV3;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use TypeError;

use function get_class;
use function gettype;
use function is_object;
use function Safe\sprintf;

/**
 * Middleware that clears the doctrine ORM identity map after processing a message.
 */
class DoctrineClearIdentityMapMiddleware implements MiddlewareInterface
{
    /** @var ManagerRegistryV2|ManagerRegistryV3|null */
    private $doctrine;

    /** @param  ManagerRegistryV2|ManagerRegistryV3|null $doctrine */
    public function __construct($doctrine)
    {
        if ($doctrine !== null && ! $doctrine instanceof ManagerRegistryV2 && ! $doctrine instanceof ManagerRegistryV3) {
            throw new TypeError(sprintf('Argument 1 passed to %s must be an instance of Doctrine\Persistence\ManagerRegistry, Doctrine\Common\Persistence\ManagerRegistry or null, %s given', __METHOD__, is_object($doctrine) ? 'instance of ' . get_class($doctrine) : gettype($doctrine)));
        }

        $this->doctrine = $doctrine;
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
