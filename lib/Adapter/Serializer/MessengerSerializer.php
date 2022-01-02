<?php

declare(strict_types=1);

namespace Kcs\MessengerExtra\Adapter\Serializer;

use Generator;
use InvalidArgumentException;
use Kcs\Serializer\SerializerInterface;
use Kcs\Serializer\Type\Type;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;
use Symfony\Component\Messenger\Stamp\SerializerStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface as MessengerSerializerInterface;

use function assert;
use function get_class;
use function is_subclass_of;
use function iterator_to_array;
use function serialize;
use function unserialize;

class MessengerSerializer implements MessengerSerializerInterface
{
    private SerializerInterface $serializer;
    private string $format;

    /**
     * @var array<string, mixed> $context
     * @phpstan-var array{groups?: string[], object_to_populate?: object} $context
     */
    private array $context;

    /**
     * @param array<string, mixed> $context
     * @phpstan-param array{groups?: string[], object_to_populate?: object} $context
     */
    public function __construct(SerializerInterface $serializer, string $format = 'json', array $context = [])
    {
        $this->serializer = $serializer;
        $this->format = $format;
        $this->context = $context;
    }

    /**
     * {@inheritdoc}
     */
    public function decode(array $encodedEnvelope): Envelope
    {
        if (empty($encodedEnvelope['body']) || empty($encodedEnvelope['headers'])) {
            throw new InvalidArgumentException('Encoded envelope should have at least a `body` and some `headers`.');
        }

        if (empty($encodedEnvelope['headers']['type'])) {
            throw new InvalidArgumentException('Encoded envelope does not have a `type` header.');
        }

        $envelopeItems = isset($encodedEnvelope['headers']['X-Message-Envelope-Items']) ? unserialize($encodedEnvelope['headers']['X-Message-Envelope-Items']) : [];
        $context = $this->context;

        $serializerConfig = $envelopeItems[SerializerStamp::class][0] ?? null;
        assert($serializerConfig instanceof SerializerStamp || $serializerConfig === null);

        if ($serializerConfig !== null) {
            $context = $serializerConfig->getContext() + $context;
        }

        $message = $this->serializer->deserialize(
            $encodedEnvelope['body'],
            Type::parse($encodedEnvelope['headers']['type']),
            $this->format,
            ContextConverter::toDeserializationContext($context)
        );

        return new Envelope($message, iterator_to_array((static function () use ($envelopeItems): Generator {
            foreach ($envelopeItems as $items) {
                yield from $items;
            }
        })(), false));
    }

    /**
     * {@inheritdoc}
     */
    public function encode(Envelope $envelope): array
    {
        $context = $this->context;

        $serializerConfig = $envelope->all(SerializerStamp::class)[0] ?? null;
        assert($serializerConfig instanceof SerializerStamp || $serializerConfig === null);

        if ($serializerConfig !== null) {
            $context = $serializerConfig->getContext() + $context;
        }

        $headers = ['type' => get_class($envelope->getMessage())];
        $configurations = [];

        foreach ($envelope->all() as $stampClass => $stamps) {
            if (is_subclass_of($stampClass, NonSendableStampInterface::class, true)) {
                continue;
            }

            $configurations[$stampClass] = $stamps;
        }

        if (! empty($configurations)) {
            $headers['X-Message-Envelope-Items'] = serialize($configurations);
        }

        return [
            'body' => $this->serializer->serialize($envelope->getMessage(), $this->format, ContextConverter::toSerializationContext($context)),
            'headers' => $headers,
        ];
    }
}
