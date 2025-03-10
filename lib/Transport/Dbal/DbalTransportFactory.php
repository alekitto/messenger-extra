<?php

declare(strict_types=1);

namespace Kcs\MessengerExtra\Transport\Dbal;

use Doctrine\Common\Persistence\ManagerRegistry as ManagerRegistryV2;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\Persistence\ManagerRegistry as ManagerRegistryV3;
use Kcs\MessengerExtra\Utils\UrlUtils;
use Symfony\Component\Messenger\Exception\InvalidArgumentException;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

use function array_merge;
use function assert;
use function explode;
use function in_array;
use function is_array;
use function is_file;
use function is_string;
use function parse_str;
use function pathinfo;
use function Safe\parse_url;
use function Safe\preg_replace;
use function str_starts_with;
use function strrev;
use function substr;
use function substr_count;

use const PATHINFO_EXTENSION;
use const PHP_URL_SCHEME;

/**
 * Serializer Messenger Transport Factory to use DBAL connection as message storage.
 */
class DbalTransportFactory implements TransportFactoryInterface
{
    public const DBAL_SUPPORTED_SCHEMES = [
        'db2',
        'mssql',
        'mysql',
        'mysql2',
        'postgres',
        'postgresql',
        'pgsql',
        'sqlite',
        'sqlite3',
    ];

    public function __construct(private ManagerRegistryV2|ManagerRegistryV3|null $managerRegistry = null)
    {
    }

    /** @param array<string, mixed> $options */
    public function createTransport(string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        $dsn = preg_replace('#^((?:pdo-)?sqlite3?):///#', '$1://localhost/', $dsn);
        assert(is_string($dsn));

        $params = parse_url($dsn);
        assert(is_array($params));

        if ($params['scheme'] === 'doctrine') {
            if ($this->managerRegistry === null) {
                throw new InvalidArgumentException('Cannot use an existing connection without a ManagerRegistry');
            }

            $connectionName = $params['host'] ?? 'default';
            $tableName = $params['path'] ?? 'messenger';
            if (str_starts_with($tableName, '/')) {
                $tableName = substr($tableName, 1);
            }

            parse_str($params['query'] ?? '', $opts);
            $options = array_merge($opts, $options, ['table_name' => $tableName]);

            $connection = $this->managerRegistry->getConnection($connectionName);
            assert($connection instanceof Connection);

            $connection = DriverManager::getConnection($connection->getParams(), $connection->getConfiguration(), $connection->getEventManager());
        } else {
            $path = $params['path'];
            if (str_starts_with($path, '/')) {
                $path = substr($path, 1);
            }

            if (
                $params['scheme'] === 'sqlite' ||
                $params['scheme'] === 'sqlite3' ||
                $params['scheme'] === 'pdo-sqlite' ||
                $params['scheme'] === 'pdo-sqlite3'
            ) {
                // SQLite has a little different handling. First we should determine the filename.
                $databaseName = $path;
                $tableName = 'messenger';

                $reverse = strrev($path);
                $count = substr_count($path, '/');
                for ($i = 1; $i < $count; ++$i) {
                    $chunks = explode('/', $reverse, $i);
                    $tmp = strrev($chunks[$i - 1]);
                    $ext = pathinfo($tmp, PATHINFO_EXTENSION);

                    if ($ext !== '' || is_file($ext)) {
                        $databaseName = $tmp;
                        $tableName = isset($chunks[$i - 2]) ? strrev($chunks[$i - 2]) : 'messenger';
                        break;
                    }
                }

                if (str_starts_with($databaseName, '/')) {
                    $databaseName = '/' . $databaseName;
                }
            } else {
                [$databaseName, $tableName] = explode('/', $path, 2) + [null, 'messenger'];
            }

            $params['path'] = '/' . $databaseName;

            parse_str($params['query'] ?? '', $opts);
            $options = array_merge($opts, $options, ['table_name' => $tableName]);

            $connection = DriverManager::getConnection(['url' => UrlUtils::buildUrl($params)]);
        }

        assert($connection instanceof Connection);

        /** @phpstan-ignore-next-line */
        return new DbalTransport($connection, $serializer, $options);
    }

    /** @param array<string, mixed> $options */
    public function supports(string $dsn, array $options): bool
    {
        $scheme = parse_url($dsn, PHP_URL_SCHEME);

        return $scheme === 'doctrine' || in_array($scheme, self::DBAL_SUPPORTED_SCHEMES, true);
    }
}
