<?php declare(strict_types=1);

namespace Kcs\MessengerExtra\Tests\Transport\Mongo;

use Composer\InstalledVersions;
use Kcs\MessengerExtra\Tests\Fixtures\DummyMessage;
use Kcs\MessengerExtra\Tests\Fixtures\DummyMessageHandler;
use Kcs\MessengerExtra\Tests\Fixtures\UniqueDummyMessage;
use Kcs\MessengerExtra\Transport\Mongo\MongoTransport;
use Kcs\MessengerExtra\Transport\Mongo\MongoTransportFactory;
use Kcs\MessengerExtra\Utils\UrlUtils;
use MongoDB\Client;
use MongoDB\Driver\Exception\ConnectionTimeoutException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\EventListener\SendFailedMessageToFailureTransportListener;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\AddBusNameStampMiddleware;
use Symfony\Component\Messenger\Middleware\DispatchAfterCurrentBusMiddleware;
use Symfony\Component\Messenger\Middleware\FailedMessageProcessingMiddleware;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Retry\RetryStrategyInterface;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Transport\Serialization\Normalizer\FlattenExceptionNormalizer;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\Serialization\Serializer;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Serializer as SerializerComponent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * @group integration
 */
class IntegrationTest extends TestCase
{
    private MongoTransport $transport;
    private string $mongoUri;
    private MongoTransport $failureTransport;

    protected function setUp(): void
    {
        if (version_compare(InstalledVersions::getVersion('symfony/messenger'), '5.2.0', '>=')) {
            $serializer = new Serializer(
                new SerializerComponent\Serializer([
                    new SerializerComponent\Normalizer\DateTimeZoneNormalizer(),
                    new SerializerComponent\Normalizer\DateTimeNormalizer(),
                    new FlattenExceptionNormalizer(),
                    new SerializerComponent\Normalizer\ArrayDenormalizer(),
                    new SerializerComponent\Normalizer\ObjectNormalizer(),
                ], [
                    'json' => new SerializerComponent\Encoder\JsonEncoder(),
                ])
            );
        } else {
            $serializer = new PhpSerializer();
        }

        $this->mongoUri = \getenv('MONGODB_URI') ?: 'mongodb://localhost:27017/';

        $params = \parse_url($this->mongoUri);
        $params['path'] = '/';

        $factory = new MongoTransportFactory();
        $this->transport = $factory->createTransport(UrlUtils::buildUrl(['path' => '/default/queue'] + $params), [], $serializer);
        $this->failureTransport = $factory->createTransport(UrlUtils::buildUrl(['path' => '/default/failed'] + $params), [], $serializer);

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

    /**
     * @medium
     */
    public function testCorrectlyHandlesRejections(): void
    {
        DummyMessageHandler::$count = 0;
        $container = new ServiceLocator([
            'dummy_transport' => fn () => $this->transport,
        ]);

        $messageBus = new MessageBus([
            new AddBusNameStampMiddleware('dummy'),
            new DispatchAfterCurrentBusMiddleware(),
            new FailedMessageProcessingMiddleware(),
            new SendMessageMiddleware(new SendersLocator([
                DummyMessage::class => ['dummy_transport'],
            ], $container)),
            new HandleMessageMiddleware(new HandlersLocator([
                DummyMessage::class => [new DummyMessageHandler()],
            ]))
        ]);

        $messageBus->dispatch(new DummyMessage('First'));
        $messageBus->dispatch(new DummyMessage('Second'));

        self::assertCount(2, $this->transport->all());
        self::assertEquals(2, $this->transport->getMessageCount());

        $receivedMessages = 0;
        $workerClass = new \ReflectionClass(Worker::class);
        $thirdArgument = $workerClass->getConstructor()->getParameters()[2];

        $type = $thirdArgument->getType();
        if ($type instanceof \ReflectionNamedType && EventDispatcherInterface::class === $type->getName()) {
            $worker = new Worker(['dummy_transport' => $this->transport], $messageBus, $eventDispatcher = new EventDispatcher());
        } else {
            $worker = new Worker(['dummy_transport' => $this->transport], $messageBus, [], $eventDispatcher = new EventDispatcher());
        }

        $retryStrategy = new class implements RetryStrategyInterface {
            private int $retry = 0;

            public function isRetryable(Envelope $message): bool
            {
                return $this->retry++ < 2;
            }

            public function getWaitingTime(Envelope $message): int
            {
                return 1;
            }
        };

        if (version_compare(InstalledVersions::getVersion('symfony/messenger'), '5.3.0', '<')) {
            $eventDispatcher->addSubscriber(new SendFailedMessageToFailureTransportListener($this->failureTransport));
        } else {
            $eventDispatcher->addSubscriber(new SendFailedMessageToFailureTransportListener(new ServiceLocator([
                'dummy_transport' => fn() => $this->failureTransport,
            ])));
        }

        $retryStrategyLocator = new ServiceLocator([
            'dummy_transport' => fn () => $retryStrategy,
        ]);

        $eventDispatcher->addSubscriber(new SendFailedMessageForRetryListener($container, $retryStrategyLocator));
        $eventDispatcher->addListener(WorkerMessageReceivedEvent::class,
            static function () use (&$receivedMessages, $worker) {
                if (4 === ++$receivedMessages) {
                    $worker->stop();
                }
            });

        $worker->run();

        self::assertCount(0, $this->transport->all());
        self::assertCount(1, $this->failureTransport->all());
        self::assertEquals(4, $receivedMessages);
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
        $argumentType = $thirdArgument->getType();
        if (EventDispatcherInterface::class === ($argumentType ? $argumentType->getName() : null)) {
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

    private function dropCollection(): void
    {
        $params = \parse_url($this->mongoUri);
        $path = $params['path'];
        if (\strpos($path, '/') === 0) {
            $path = \substr($path, 1);
        }

        [$databaseName] = \explode('/', $path, 2) + [null, null];
        $databaseName = $databaseName ?: 'default';

        $params['path'] = '/';
        parse_str($params['query'] ?? '', $opts);
        $auth = isset($params['user']) ? ['authSource' => $opts['authSource'] ?? $databaseName] : [];

        $client = new Client(UrlUtils::buildUrl($params), $auth);
        $client->default->queue->drop();
        $client->default->failed->drop();
    }
}
