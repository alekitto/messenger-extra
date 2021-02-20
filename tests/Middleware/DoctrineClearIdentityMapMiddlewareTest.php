<?php declare(strict_types=1);

namespace Kcs\MessengerExtra\Tests\Middleware;

use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Kcs\MessengerExtra\Middleware\DoctrineClearIdentityMapMiddleware;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

class DoctrineClearIdentityMapMiddlewareTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @var ManagerRegistry|ObjectProphecy
     */
    private ObjectProphecy $doctrine;
    private DoctrineClearIdentityMapMiddleware $middleware;

    protected function setUp(): void
    {
        $this->doctrine = $this->prophesize(ManagerRegistry::class);
        $this->middleware = new DoctrineClearIdentityMapMiddleware($this->doctrine->reveal());
    }

    public function testHandleShouldClearManagersAfterProcessingReceivedMessage(): void
    {
        $envelope = new Envelope(new \stdClass(), [new ReceivedStamp('test')]);

        $stack = $this->prophesize(StackInterface::class);
        $stack->next()->willReturn($handler = $this->prophesize(MiddlewareInterface::class));
        $handler->handle($envelope, $stack)
            ->shouldBeCalled()
            ->will(function () use (&$handleCalled, $envelope): Envelope {
                $handleCalled = true;

                return $envelope;
            });

        $this->doctrine->getManagers()
            ->willReturn([$manager = $this->prophesize(ObjectManager::class)]);
        $manager->clear()->shouldBeCalled();

        $this->middleware->handle($envelope, $stack->reveal());
    }
}
