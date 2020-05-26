<?php declare(strict_types=1);

namespace Kcs\MessengerExtra\Tests\Transport\Dbal;

use Doctrine\DBAL\DriverManager;
use Kcs\MessengerExtra\Tests\Fixtures\DummyMessage;
use Kcs\MessengerExtra\Tests\Fixtures\UniqueDummyMessage;
use Kcs\MessengerExtra\Transport\Dbal\DbalTransport;
use Kcs\MessengerExtra\Transport\Dbal\DbalTransportFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Transport\Serialization\Serializer;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Serializer as SerializerComponent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

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

        $factory = new DbalTransportFactory(null);
        $db = \getenv('DB') ?? 'sqlite';

        switch ($db) {
            case 'mysql':
            case 'mariadb':
                $connection = DriverManager::getConnection(['url' => 'mysql://root@localhost']);
                $this->transport = $factory->createTransport($this->dsn = 'mysql://root@localhost/messenger', [], $serializer);
                break;

            case 'postgresql':
                $connection = DriverManager::getConnection(['url' => 'pgsql://postgres@localhost']);
                $this->transport = $factory->createTransport($this->dsn = 'pgsql://postgres@localhost/messenger', [], $serializer);
                break;

            case 'sqlite':
            default:
                $this->transport = $factory->createTransport($this->dsn = 'sqlite:///'.__DIR__.'/messenger.db', [], $serializer);
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

    /**
     * @medium
     */
    public function testSendsAndReceivesMessages(): void
    {
        $this->transport->send(new Envelope($first = new DummyMessage('First')));
        $this->transport->send(new Envelope($second = new DummyMessage('Second')));

        self::assertCount(2, $this->transport->all());
        self::assertEquals(2, $this->transport->getMessageCount());

        $receivedMessages = 0;
        $workerClass = new \ReflectionClass(Worker::class);
        $thirdArgument = $workerClass->getConstructor()->getParameters()[2];

        $type = $thirdArgument->getType();
        if ($type instanceof \ReflectionNamedType && EventDispatcherInterface::class === $type->getName()) {
            $worker = new Worker([$this->transport], new MessageBus(), $eventDispatcher = new EventDispatcher());
        } else {
            $worker = new Worker([$this->transport], new MessageBus(), [], $eventDispatcher = new EventDispatcher());
        }

        $eventDispatcher->addListener(WorkerMessageReceivedEvent::class,
            static function (WorkerMessageReceivedEvent $event) use (&$receivedMessages, $first, $second, $worker) {
                $envelope = $event->getEnvelope();
                self::assertEquals(0 === $receivedMessages ? $first : $second, $envelope->getMessage());

                if (2 === ++$receivedMessages) {
                    $worker->stop();
                }
            });

        $worker->run();

        self::assertEquals(2, $receivedMessages);
    }

    public function testRespectsUniqueMessages(): void
    {
        $this->transport->send(new Envelope($first = new UniqueDummyMessage('First')));
        $this->transport->send(new Envelope($second = new UniqueDummyMessage('Second')));

        $firstGet = \iterator_to_array($this->transport->get(), false);
        $secondGet = \iterator_to_array($this->transport->get(), false);

        self::assertCount(1, $firstGet);
        self::assertEquals($first, $firstGet[0]->getMessage());

        self::assertCount(0, $secondGet);
    }
}
