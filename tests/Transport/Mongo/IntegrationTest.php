<?php declare(strict_types=1);

namespace Kcs\MessengerExtra\Tests\Transport\Mongo;

use Kcs\MessengerExtra\Tests\Fixtures\DummyMessage;
use Kcs\MessengerExtra\Transport\Mongo\MongoTransportFactory;
use MongoDB\Client;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\Serializer;
use Symfony\Component\Process\PhpProcess;
use Symfony\Component\Process\Process;
use Symfony\Component\Serializer as SerializerComponent;

/**
 * @group integration
 */
class IntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        $this->dropCollection();
    }

    protected function tearDown(): void
    {
        $this->dropCollection();
    }

    public function testSendsAndReceivesMessages(): void
    {
        $serializer = new Serializer(
            new SerializerComponent\Serializer([
                new SerializerComponent\Normalizer\ObjectNormalizer(),
            ], [
                'json' => new SerializerComponent\Encoder\JsonEncoder(),
            ])
        );

        $factory = new MongoTransportFactory($serializer);
        $transport = $factory->createTransport('mongodb://localhost:27017/default/queue', []);

        $transport->send($first = new Envelope(new DummyMessage('First')));
        $transport->send($second = new Envelope(new DummyMessage('Second')));

        $receivedMessages = 0;
        $transport->receive(function (?Envelope $envelope) use ($transport, &$receivedMessages, $first, $second) {
            self::assertEquals(0 === $receivedMessages ? $first : $second, $envelope);

            if (2 === ++$receivedMessages) {
                $transport->stop();
            }

            return $envelope;
        });

        self::assertEquals(2, $receivedMessages);
    }

    public function testItReceivesSignals(): void
    {
        $serializer = new Serializer(
            new SerializerComponent\Serializer([
                new SerializerComponent\Normalizer\ObjectNormalizer(),
            ], [
                'json' => new SerializerComponent\Encoder\JsonEncoder(),
            ])
        );

        $factory = new MongoTransportFactory($serializer);
        $transport = $factory->createTransport('mongodb://localhost:27017/default/queue', []);

        $transport->send(new Envelope(new DummyMessage('Hello')));

        $amqpReadTimeout = 30;
        $process = new PhpProcess(\file_get_contents(__DIR__.'/long_receiver.php'), null, [
            'ROOT' => __DIR__.'/../../../',
            'DSN' => 'mongodb://localhost:27017',
        ]);

        $process->start();

        $this->waitForOutput($process, $expectedOutput = "Receiving messages...\n");

        $signalTime = \microtime(true);
        $timedOutTime = \time() + 10;

        $process->signal(15);

        while ($process->isRunning() && \time() < $timedOutTime) {
            \usleep(100 * 1000); // 100ms
        }

        self::assertFalse($process->isRunning());
        self::assertLessThan($amqpReadTimeout, \microtime(true) - $signalTime);
        self::assertSame($expectedOutput.<<<'TXT'
Get envelope with message: Kcs\MessengerExtra\Tests\Fixtures\DummyMessage
with stamps: [
    "Symfony\\Component\\Messenger\\Stamp\\ReceivedStamp"
]
Done.

TXT
            , $process->getOutput());
    }

    /**
     * @runInSeparateProcess
     */
    public function testItSupportsTimeoutAndTicksNullMessagesToTheHandler(): void
    {
        $serializer = new Serializer(
            new SerializerComponent\Serializer([
                new SerializerComponent\Normalizer\ObjectNormalizer(),
            ], [
                'json' => new SerializerComponent\Encoder\JsonEncoder(),
            ])
        );

        $factory = new MongoTransportFactory($serializer);
        $transport = $factory->createTransport('mongodb://localhost:27017/default/queue', []);

        $receivedMessages = 0;
        $transport->receive(function (?Envelope $envelope) use ($transport, &$receivedMessages) {
            self::assertNull($envelope);

            if (2 === ++$receivedMessages) {
                $transport->stop();
            }
        });

        self::assertEquals(2, $receivedMessages);
    }

    private function waitForOutput(Process $process, string $output, $timeoutInSeconds = 10): void
    {
        $timedOutTime = \time() + $timeoutInSeconds;

        while (\time() < $timedOutTime) {
            if (0 === \strpos($process->getOutput(), $output)) {
                return;
            }

            \usleep(100 * 1000); // 100ms
        }

        throw new \RuntimeException('Expected output never arrived. Got "'.$process->getOutput().'" instead.');
    }

    private function dropCollection(): void
    {
        $client = new Client('mongodb://localhost:27017/');
        $client->default->queue->drop();
    }
}
