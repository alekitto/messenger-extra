<?php

declare(strict_types=1);

namespace Kcs\MessengerExtra\Transport\Dbal;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\ResultStatement;
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

use function assert;
use function bin2hex;
use function mb_strlen;
use function method_exists;
use function microtime;
use function Safe\sprintf;
use function sha1;

/**
 * Serializer Messenger sender to send messages through DBAL connection.
 */
class DbalSender implements SenderInterface
{
    private SerializerInterface $serializer;
    private string $tableName;
    private Connection $connection;
    private OrderedTimeCodec $codec;

    public function __construct(Connection $connection, string $tableName, ?SerializerInterface $serializer = null)
    {
        $this->connection = $connection;
        $this->tableName = $tableName;
        $this->serializer = $serializer ?? Serializer::create();

        $this->codec = new OrderedTimeCodec((new UuidFactory())->getUuidBuilder());
    }

    public function send(Envelope $envelope): Envelope
    {
        $message = $envelope->getMessage();
        $delay = null;

        $delayStamp = $envelope->last(DelayStamp::class);
        assert($delayStamp instanceof DelayStamp || $delayStamp === null);
        if ($delayStamp !== null) {
            /** @phpstan-ignore-next-line */
            $delay = new DateTimeImmutable('+ ' . $delayStamp->getDelay() . ' milliseconds');
        }

        $encodedMessage = $this->serializer->encode($envelope
            ->withoutStampsOfType(SentStamp::class)
            ->withoutStampsOfType(TransportMessageIdStamp::class)
            ->withoutStampsOfType(DelayStamp::class));

        $messageId = $this->codec->encodeBinary(Uuid::uuid1());
        $values = [
            'id' => $messageId,
            'published_at' => new DateTimeImmutable(), /** @phpstan-ignore-line */
            'body' => $encodedMessage['body'],
            'headers' => $encodedMessage['headers'] ?? [],
            'properties' => [],
            'priority' => 0,
            'time_to_live' => null,
            'delayed_until' => $delay,
            'uniq_key' => null,
        ];

        if ($message instanceof TTLAwareMessageInterface) {
            /** @phpstan-ignore-next-line */
            $values['time_to_live'] = (new DateTimeImmutable())->modify('+ ' . $message->getTtl() . ' seconds');
        }

        if ($message instanceof DelayedMessageInterface) {
            $timestamp = microtime(true) + ($message->getDelay() / 1000);
            $values['delayed_until'] = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6f', $timestamp));
        }

        if ($message instanceof PriorityAwareMessageInterface) {
            $values['priority'] = $message->getPriority();
        }

        if ($message instanceof UniqueMessageInterface) {
            $uniqKey = $message->getUniquenessKey();
            if (mb_strlen($uniqKey) >= 60) {
                $uniqKey = sha1($uniqKey);
            }

            if (method_exists($this->connection, 'createExpressionBuilder')) {
                $expr = $this->connection->createExpressionBuilder();
            } else {
                $expr = $this->connection->getExpressionBuilder();
            }

            $queryBuilder = $this->connection->createQueryBuilder()
                ->select('id')
                ->from($this->tableName)
                ->where($expr->eq('uniq_key', ':uniq_key'))
                ->andWhere($expr->isNull('delivery_id'))
                ->setParameter('uniq_key', $uniqKey);

            if (method_exists($queryBuilder, 'executeQuery')) {
                $result = $queryBuilder->executeQuery()->fetchOne();
            } else {
                $statement = $queryBuilder->execute();

                assert($statement instanceof ResultStatement);
                if (method_exists($statement, 'fetchOne')) {
                    $result = $statement->fetchOne();
                } else {
                    $result = $statement->fetchColumn();
                }
            }

            if ($result !== false) {
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
            ->with(new TransportMessageIdStamp(bin2hex($messageId)));
    }
}
