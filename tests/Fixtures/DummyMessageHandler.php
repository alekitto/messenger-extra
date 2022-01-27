<?php declare(strict_types=1);

namespace Kcs\MessengerExtra\Tests\Fixtures;

use Symfony\Component\Messenger\Handler\MessageHandlerInterface;

class DummyMessageHandler implements MessageHandlerInterface
{
    public static int $count = 0;

    public function __invoke(DummyMessage $message)
    {
        if (self::$count++ === 1) {
            return;
        }

        throw new \Exception();
    }
}
