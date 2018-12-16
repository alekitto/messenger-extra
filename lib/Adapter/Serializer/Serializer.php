<?php declare(strict_types=1);

namespace Kcs\MessengerExtra\Adapter\Serializer;

use Kcs\Serializer\SerializerInterface;
use Kcs\Serializer\Type\Type;
use Symfony\Component\Serializer\SerializerInterface as SymfonySerializerInterface;

class Serializer implements SymfonySerializerInterface
{
    /**
     * @var SerializerInterface
     */
    private $serializer;

    public function __construct(SerializerInterface $serializer)
    {
        $this->serializer = $serializer;
    }

    /**
     * {@inheritdoc}
     */
    public function serialize($data, $format, array $context = [])
    {
        return $this->serializer->serialize($data, $format, ContextConverter::toSerializationContext($context));
    }

    /**
     * {@inheritdoc}
     */
    public function deserialize($data, $type, $format, array $context = [])
    {
        return $this->serializer->deserialize($data, Type::parse($type), $format, ContextConverter::toDeserializationContext($context));
    }
}
