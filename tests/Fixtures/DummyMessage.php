<?php declare(strict_types=1);

namespace Kcs\MessengerExtra\Tests\Fixtures;

use Kcs\Serializer\Annotation as Serializer;

/**
 * @Serializer\AccessType("property")
 */
class DummyMessage implements DummyMessageInterface
{
    /**
     * @var string
     *
     * @Serializer\Type("string")
     * @Serializer\Groups({"foo"})
     */
    private $message;

    public function __construct(string $message)
    {
        $this->message = $message;
    }

    public function getMessage(): string
    {
        return $this->message;
    }
}
