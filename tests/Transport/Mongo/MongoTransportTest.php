<?php declare(strict_types=1);

namespace Kcs\MessengerExtra\Tests\Transport\Mongo;

use Kcs\MessengerExtra\Message\DelayedMessageInterface;
use Kcs\MessengerExtra\Message\PriorityAwareMessageInterface;
use Kcs\MessengerExtra\Message\TTLAwareMessageInterface;
use Kcs\MessengerExtra\Message\UniqueMessageInterface;
use Kcs\MessengerExtra\Transport\Mongo\MongoTransport;
use MongoDB\BSON\ObjectId;
use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Database;
use MongoDB\InsertOneResult;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

class MongoTransportTest extends TestCase
{
    /**
     * @var Collection|ObjectProphecy
     */
    private $collection;

    /**
     * @var MongoTransport
     */
    private $transport;

    protected function setUp(): void
    {
        $client = $this->prophesize(Client::class);
        $client->selectDatabase('default')
            ->willReturn($database = $this->prophesize(Database::class));
        $database->selectCollection('messenger')
            ->willReturn($this->collection = $this->prophesize(Collection::class));

        $this->transport = new MongoTransport($client->reveal(), null, [
            'database_name' => 'default',
            'collection_name' => 'messenger',
        ]);
    }

    public function testSend(): void
    {
        $message = new class() implements DelayedMessageInterface, TTLAwareMessageInterface, PriorityAwareMessageInterface, UniqueMessageInterface {
            public function getDelay(): int
            {
                return 5000;
            }

            public function getTtl(): int
            {
                return 10;
            }

            public function getPriority(): int
            {
                return 0;
            }

            public function getUniquenessKey(): string
            {
                return 'uniq';
            }
        };

        $this->collection->findOne([
            '$and' => [
                ['uniq_key' => 'uniq'],
                [
                    '$or' => [
                        ['delivery_id' => ['$exists' => false]],
                        ['delivery_id' => null],
                    ],
                ],
            ],
        ])->willReturn(null);

        $this->collection->insertOne(Argument::allOf(
            Argument::withEntry('published_at', Argument::type('int')),
            Argument::withEntry('body', '{"delay":5000,"ttl":10,"priority":0,"uniquenessKey":"uniq"}'),
            Argument::withEntry('headers', Argument::allOf(
                Argument::withEntry('type', \get_class($message)),
                Argument::withKey('X-Message-Stamp-Symfony\Component\Messenger\Stamp\RedeliveryStamp')
            )),
            Argument::withEntry('properties', []),
            Argument::withEntry('priority', Argument::allOf(Argument::type('int'), 0)),
            Argument::withEntry('time_to_live', Argument::type('int')),
            Argument::withEntry('delayed_until', Argument::type('int')),
            Argument::withEntry('uniq_key', 'uniq')
        ))
            ->shouldBeCalled()
            ->willReturn($result = $this->prophesize(InsertOneResult::class))
        ;

        $result->getInsertedId()->willReturn(new ObjectId());

        $envelope = $this->transport->send((new Envelope($message))->with(
            new RedeliveryStamp(2)
        ));

        self::assertNotEmpty($envelope->last(TransportMessageIdStamp::class)->getId());
    }

    public function testSendWithSymfonyDelayStamp(): void
    {
        $delay = 5000;
        $message = new class() {
        };

        $this->collection->insertOne(Argument::allOf(
            Argument::withEntry('delayed_until', Argument::type('int'))
        ))
            ->shouldBeCalled()
            ->willReturn($result = $this->prophesize(InsertOneResult::class))
        ;

        $result->getInsertedId()->willReturn(new ObjectId());

        $this->transport->send(new Envelope($message, [new DelayStamp($delay)]));
    }

    public function testSendShouldNotSendIfUniqueMessageIsInQueue(): void
    {
        $message = new class() implements UniqueMessageInterface {
            public function getUniquenessKey(): string
            {
                return 'uniq';
            }
        };

        $this->collection->findOne([
            '$and' => [
                ['uniq_key' => 'uniq'],
                [
                    '$or' => [
                        ['delivery_id' => ['$exists' => false]],
                        ['delivery_id' => null],
                    ],
                ],
            ],
        ])->willReturn([
            'id' => '50000foobar',
        ]);

        $this->collection->insertOne(Argument::cetera())->shouldNotBeCalled();
        $this->transport->send(new Envelope($message));
    }

    public function testReceive(): void
    {
        // Delete expired messages
        $this->collection->deleteMany(Argument::type('array'))->willReturn();

        $this->collection->findOneAndUpdate(
            Argument::withEntry('$and',
                Argument::withEntry(0,
                    Argument::withEntry('$or', Argument::allOf(
                        Argument::withEntry(0, ['delayed_until' => ['$exists' => false]]),
                        Argument::withEntry(1, ['delayed_until' => null]),
                        Argument::withEntry(2, Argument::type('array'))
                    ))
                ),
                Argument::withEntry(1,
                    Argument::withEntry('$or', Argument::allOf(
                        Argument::withEntry(0, ['delivery_id' => ['$exists' => false]]),
                        Argument::withEntry(1, ['delivery_id' => null]),
                        Argument::withEntry(2, Argument::type('array'))
                    ))
                )
            ),
            Argument::withEntry('$set', Argument::allOf(
                Argument::withEntry('delivery_id', Argument::type('string')),
                Argument::withEntry('redeliver_at', Argument::type('int'))
            )),
            [
                'sort' => ['priority' => -1, 'published_at' => 1],
                'typeMap' => ['root' => 'array', 'document' => 'array'],
            ]
        )
            ->shouldBeCalled()
            ->willReturn($document = [
                '_id' => 'document_id',
                'published_at' => \time(),
                'body' => '{}',
                'headers' => ['type' => 'stdClass'],
                'time_to_live' => null,
            ]);

        $this->collection->deleteOne(['_id' => 'document_id'])->willReturn();

        \iterator_to_array($this->transport->get());
    }
}
