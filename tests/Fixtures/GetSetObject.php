<?php declare(strict_types=1);

namespace Kcs\MessengerExtra\Tests\Fixtures;

use Kcs\Serializer\Annotation as Serializer;

class GetSetObject
{
    /**
     * @Serializer\Type("integer")
     * @Serializer\ReadOnly()
     */
    private $id = 1;

    /**
     * @Serializer\Type("string")
     */
    private $name = 'Foo';

    /**
     * @Serializer\ReadOnly()
     */
    private $readOnlyProperty = 42;

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return 'Alex';
    }

    public function setName($name): void
    {
        $this->name = $name;
    }

    public function getReadOnlyProperty(): int
    {
        return $this->readOnlyProperty;
    }
}
