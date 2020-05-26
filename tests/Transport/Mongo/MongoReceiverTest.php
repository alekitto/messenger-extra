<?php declare(strict_types=1);

namespace Kcs\MessengerExtra\Tests\Transport\Mongo;

use Kcs\MessengerExtra\Tests\Fixtures\DummyMessage;
use Kcs\MessengerExtra\Transport\Mongo\MongoReceiver;
use MongoDB\BSON\ObjectId;
use MongoDB\Collection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Transport\Serialization\Serializer;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;

class MongoReceiverTest extends TestCase
{
    public function testAll()
    {
        $serializer = $this->createSerializer();

        $envelope1 = $this->createEnvelope();
        $envelope2 = $this->createEnvelope();
        $mongoCollection = $this->createMock(Collection::class);
        $mongoCollection->method('find')->with([], ['limit' => 20])->willReturn([$envelope1, $envelope2]);

        $receiver = new MongoReceiver($mongoCollection, $serializer);
        $actualEnvelopes = \iterator_to_array($receiver->all(20));
        self::assertCount(2, $actualEnvelopes);
        self::assertEquals(new DummyMessage('Hi'), $actualEnvelopes[0]->getMessage());
    }

    public function testFind()
    {
        $serializer = $this->createSerializer();

        $envelope = $this->createEnvelope();
        $mongoCollection = $this->createMock(Collection::class);
        $mongoCollection->method('findOne')->with(['_id' => new ObjectId('5a2493c33c95a1281836eb6a')])->willReturn($envelope);

        $receiver = new MongoReceiver($mongoCollection, $serializer);
        $actualEnvelope = $receiver->find('5a2493c33c95a1281836eb6a');
        self::assertEquals(new DummyMessage('Hi'), $actualEnvelope->getMessage());
    }

    private function createEnvelope()
    {
        return [
            '_id' => '5a2493c33c95a1281836eb6a',
            'body' => '{"message": "Hi"}',
            'headers' => [
                'type' => DummyMessage::class,
            ],
        ];
    }

    private function createSerializer()
    {
        return new Serializer(
            new \Symfony\Component\Serializer\Serializer([new ObjectNormalizer()], ['json' => new JsonEncoder()])
        );
    }
}
