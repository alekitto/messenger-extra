<?php declare(strict_types=1);

namespace Kcs\MessengerExtra\Tests\Transport\Null;

use Kcs\MessengerExtra\Transport\Null\NullTransport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;

class NullTransportTest extends TestCase
{
    /**
     * @var NullTransport
     */
    private $transport;

    protected function setUp(): void
    {
        $this->transport = new NullTransport();
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testReceiveShouldNotCallHandler(): void
    {
        $this->transport->receive(function (): void {
            throw new \LogicException('Should not be called');
        });
    }

    public function testSendShouldReturnTheSameEnvelope(): void
    {
        $envelope = new Envelope(new \stdClass());
        self::assertSame($envelope, $this->transport->send($envelope));
    }
}
