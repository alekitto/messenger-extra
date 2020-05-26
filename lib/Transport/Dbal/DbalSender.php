<?php declare(strict_types=1);

namespace Kcs\MessengerExtra\Transport\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Kcs\MessengerExtra\Message\DelayedMessageInterface;
use Kcs\MessengerExtra\Message\PriorityAwareMessageInterface;
use Kcs\MessengerExtra\Message\TTLAwareMessageInterface;
use Kcs\MessengerExtra\Message\UniqueMessageInterface;
use Ramsey\Uuid\Codec\OrderedTimeCodec;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\SentStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Transport\Serialization\Serializer;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Serializer Messenger sender to send messages through DBAL connection.
 *
 * @author Alessandro Chitolina <alekitto@gmail.com>
 */
class DbalSender implements SenderInterface
{
    /**
     * @var SerializerInterface
     */
    private $serializer;

    /**
     * @var string
     */
    private $tableName;

    /**
     * @var Connection
     */
    private $connection;

    /**
     * @var OrderedTimeCodec
     */
    private $codec;

    public function __construct(Connection $connection, string $tableName, SerializerInterface $serializer = null)
    {
        $this->connection = $connection;
        $this->tableName = $tableName;
        $this->serializer = $serializer ?? Serializer::create();

        $this->codec = new OrderedTimeCodec((new UuidFactory())->getUuidBuilder());
    }

    /**
     * {@inheritdoc}
     */
    public function send(Envelope $envelope): Envelope
    {
        $message = $envelope->getMessage();
        $delay = null;

        /** @var DelayStamp $delayStamp */
        if (null !== ($delayStamp = $envelope->last(DelayStamp::class))) {
            $delay = new \DateTimeImmutable('+ '.$delayStamp->getDelay().' milliseconds');
        }

        $encodedMessage = $this->serializer->encode($envelope
            ->withoutStampsOfType(SentStamp::class)
            ->withoutStampsOfType(TransportMessageIdStamp::class)
            ->withoutStampsOfType(DelayStamp::class)
        );

        $messageId = $this->codec->encodeBinary(Uuid::uuid1());
        $values = [
            'id' => $messageId,
            'published_at' => new \DateTimeImmutable(),
            'body' => $encodedMessage['body'],
            'headers' => $encodedMessage['headers'] ?? [],
            'properties' => [],
            'priority' => 0,
            'time_to_live' => null,
            'delayed_until' => $delay,
            'uniq_key' => null,
        ];

        if ($message instanceof TTLAwareMessageInterface) {
            $values['time_to_live'] = (new \DateTimeImmutable())->modify('+ '.$message->getTtl().' seconds');
        }

        if ($message instanceof DelayedMessageInterface) {
            $timestamp = \microtime(true) + ($message->getDelay() / 1000);
            $values['delayed_until'] = \DateTimeImmutable::createFromFormat('U.u', \sprintf('%.6f', $timestamp));
        }

        if ($message instanceof PriorityAwareMessageInterface) {
            $values['priority'] = $message->getPriority();
        }

        if ($message instanceof UniqueMessageInterface) {
            $uniqKey = $message->getUniquenessKey();
            if (\mb_strlen($uniqKey) >= 60) {
                $uniqKey = \sha1($uniqKey);
            }

            $expr = $this->connection->getExpressionBuilder();
            $result = $this->connection->createQueryBuilder()
                ->select('id')
                ->from($this->tableName)
                ->where($expr->eq('uniq_key', ':uniq_key'))
                ->andWhere($expr->isNull('delivery_id'))
                ->setParameter('uniq_key', $uniqKey)
                ->execute()->fetchColumn()
            ;

            if (false !== $result) {
                return $envelope;
            }

            $values['uniq_key'] = $uniqKey;
        }

        $this->connection->insert($this->tableName, $values, [
            'id' => ParameterType::BINARY,
            'published_at' => Types::DATETIMETZ_IMMUTABLE,
            'body' => Types::TEXT,
            'headers' => Types::JSON,
            'properties' => Types::JSON,
            'priority' => Types::INTEGER,
            'time_to_live' => Types::DATETIMETZ_IMMUTABLE,
            'delayed_until' => Types::DATETIMETZ_IMMUTABLE,
            'uniq_key' => ParameterType::STRING,
        ]);

        return $envelope
            ->with(new TransportMessageIdStamp($messageId))
        ;
    }
}
