<?php declare(strict_types=1);

namespace Kcs\MessengerExtra\Middleware;

use Doctrine\Common\Persistence\ManagerRegistry;
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
     * @var ManagerRegistry
     */
    private $doctrine;

    public function __construct(?ManagerRegistry $doctrine)
    {
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
