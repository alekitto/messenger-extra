<?php declare(strict_types=1);

namespace Kcs\MessengerExtra\Tests\Transport\Mongo;

use Kcs\MessengerExtra\Tests\Fixtures\DummyMessage;
use Kcs\MessengerExtra\Transport\Mongo\MongoTransport;
use Kcs\MessengerExtra\Transport\Mongo\MongoTransportFactory;
use MongoDB\Client;
use MongoDB\Driver\Exception\ConnectionTimeoutException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Transport\Serialization\Serializer;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Serializer as SerializerComponent;

/**
 * @group integration
 */
class IntegrationTest extends TestCase
{
    /**
     * @var MongoTransport
     */
    private $transport;

    protected function setUp(): void
    {
        $serializer = new Serializer(
            new SerializerComponent\Serializer([
                new SerializerComponent\Normalizer\ObjectNormalizer(),
            ], [
                'json' => new SerializerComponent\Encoder\JsonEncoder(),
            ])
        );

        $factory = new MongoTransportFactory();
        $this->transport = $factory->createTransport('mongodb://localhost:27017/default/queue', [], $serializer);

        try {
            $this->dropCollection();
        } catch (ConnectionTimeoutException $e) {
            self::markTestSkipped('Mongodb not available');
        }
    }

    protected function tearDown(): void
    {
        $this->dropCollection();
    }

    public function testSendsAndReceivesMessages(): void
    {
        $this->transport->send($first = new Envelope(new DummyMessage('First')));
        $this->transport->send($second = new Envelope(new DummyMessage('Second')));

        $receivedMessages = 0;
        $worker = new Worker([$this->transport], new MessageBus(), [], $eventDispatcher = new EventDispatcher());

        $eventDispatcher->addListener(WorkerMessageReceivedEvent::class,
            static function (WorkerMessageReceivedEvent $event) use (&$receivedMessages, $first, $second, $worker) {
                $envelope = $event->getEnvelope();
                self::assertEquals(0 === $receivedMessages ? $first : $second, $envelope);

                if (2 === ++$receivedMessages) {
                    $worker->stop();
                }
            });

        $worker->run();
        self::assertEquals(2, $receivedMessages);
    }

    private function dropCollection(): void
    {
        $client = new Client('mongodb://localhost:27017/');
        $client->default->queue->drop();
    }
}
