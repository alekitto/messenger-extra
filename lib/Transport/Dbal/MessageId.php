<?php

declare(strict_types=1);

namespace Kcs\MessengerExtra\Transport\Dbal;

use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use RuntimeException;
use Symfony\Component\Uid\Uuid as SymfonyUuid;

use function class_exists;
use function substr;

final class MessageId
{
    public static function generate(): string
    {
        if (class_exists(SymfonyUuid::class)) {
            $uuid = SymfonyUuid::v1();

            return $uuid->toBinary();
        }

        if (class_exists(UuidInterface::class)) {
            $uuid = Uuid::uuid1();
            $bytes = $uuid->getFields()->getBytes();

            return $bytes[6] . $bytes[7]
                . $bytes[4] . $bytes[5]
                . $bytes[0] . $bytes[1] . $bytes[2] . $bytes[3]
                . substr($bytes, 8);
        }

        throw new RuntimeException('No Uuid class found. Please install ramsey/uuid package or symfony/uid package.');
    }
}
