<?php declare(strict_types=1);

namespace Kcs\MessengerExtra\Tests\Transport\Dbal;

use Doctrine\DBAL\DriverManager;
use Kcs\MessengerExtra\Tests\Fixtures\DummyMessage;
use Kcs\MessengerExtra\Transport\Dbal\DbalTransport;
use Kcs\MessengerExtra\Transport\Dbal\DbalTransportFactory;
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
    /**
     * @var DbalTransport
     */
    private $transport;

    /**
     * @var string
     */
    private $dsn;

    protected function setUp(): void
    {
        @\unlink(__DIR__.'/messenger.db');

        $serializer = new Serializer(
            new SerializerComponent\Serializer([
                new SerializerComponent\Normalizer\ObjectNormalizer(),
            ], [
                'json' => new SerializerComponent\Encoder\JsonEncoder(),
            ])
        );

        $factory = new DbalTransportFactory(null, $serializer);
        $db = \getenv('DB') ?? 'sqlite';

        switch ($db) {
            case 'mysql':
            case 'mariadb':
                $connection = DriverManager::getConnection([ 'url' => 'mysql://root@localhost' ]);
                $this->transport = $factory->createTransport($this->dsn = 'mysql://root@localhost/messenger', []);
                break;

            case 'postgresql':
                $connection = DriverManager::getConnection([ 'url' => 'pgsql://postgres@localhost' ]);
                $this->transport = $factory->createTransport($this->dsn = 'pgsql://postgres@localhost/messenger', []);
                break;

            case 'sqlite':
            default:
                $this->transport = $factory->createTransport($this->dsn = 'sqlite:///'.__DIR__.'/messenger.db', []);
                break;
        }

        if (isset($connection)) {
            $connection->getSchemaManager()->dropAndCreateDatabase('messenger');
        }

        $this->transport->createTable();
    }

    protected function tearDown(): void
    {
        @\unlink(__DIR__.'/messenger.db');
    }

    public function testSendsAndReceivesMessages(): void
    {
        $this->transport->send($first = new Envelope(new DummyMessage('First')));
        $this->transport->send($second = new Envelope(new DummyMessage('Second')));

        $receivedMessages = 0;
        $this->transport->receive(function (?Envelope $envelope) use (&$receivedMessages, $first, $second) {
            self::assertEquals(0 === $receivedMessages ? $first : $second, $envelope);

            if (2 === ++$receivedMessages) {
                $this->transport->stop();
            }

            return $envelope;
        });

        self::assertEquals(2, $receivedMessages);
    }

    public function testItReceivesSignals(): void
    {
        $this->transport->send(new Envelope(new DummyMessage('Hello')));

        $amqpReadTimeout = 30;
        $process = new PhpProcess(\file_get_contents(__DIR__.'/long_receiver.php'), null, [
            'ROOT' => __DIR__.'/../../../',
            'DSN' => $this->dsn,
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
        $receivedMessages = 0;
        $this->transport->receive(function (?Envelope $envelope) use (&$receivedMessages) {
            self::assertNull($envelope);

            if (2 === ++$receivedMessages) {
                $this->transport->stop();
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
}
