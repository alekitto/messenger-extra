<?php declare(strict_types=1);

namespace Kcs\MessengerExtra\Middleware;

use Doctrine\Common\Persistence\ManagerRegistry as ManagerRegistryV2;
use Doctrine\Persistence\ManagerRegistry as ManagerRegistryV3;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Middleware that clears the doctrine ORM identity map after processing a message.
 *
 * @author Alessandro Chitolina <alekitto@gmail.com>
 */
class DoctrineClearIdentityMapMiddleware implements MiddlewareInterface
{
    /**
     * @var ManagerRegistryV2|ManagerRegistryV3|null
     */
    private $doctrine;

    public function __construct($doctrine)
    {
        if (null !== $doctrine && ! $doctrine instanceof ManagerRegistryV2 && ! $doctrine instanceof ManagerRegistryV3) {
            throw new \TypeError(\sprintf('Argument 1 passed to %s must be an instance of Doctrine\Persistence\ManagerRegistry, Doctrine\Common\Persistence\ManagerRegistry or null, %s given', __METHOD__, \is_object($doctrine) ? 'instance of '.\get_class($doctrine) : \gettype($doctrine)));
        }

        $this->doctrine = $doctrine;
    }

    /**
     * {@inheritdoc}
     */
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $return = $stack->next()->handle($envelope, $stack);
        if (null !== $this->doctrine && $envelope->all(ReceivedStamp::class)) {
            // it's a received message
            foreach ($this->doctrine->getManagers() as $manager) {
                $manager->clear();
            }
        }

        return $return;
    }
}
