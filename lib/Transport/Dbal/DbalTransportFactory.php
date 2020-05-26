<?php declare(strict_types=1);

namespace Kcs\MessengerExtra\Transport\Dbal;

use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\DBAL\DriverManager;
use Kcs\MessengerExtra\Utils\UrlUtils;
use Symfony\Component\Messenger\Exception\InvalidArgumentException;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Serializer Messenger Transport Factory to use DBAL connection as message storage.
 *
 * @author Alessandro Chitolina <alekitto@gmail.com>
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

    /**
     * @var ManagerRegistry|null
     */
    private $managerRegistry;

    public function __construct(?ManagerRegistry $managerRegistry = null)
    {
        $this->managerRegistry = $managerRegistry;
    }

    public function createTransport(string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        $dsn = \preg_replace('#^(sqlite3?):///#', '$1://localhost/', $dsn);
        $params = \parse_url($dsn);
        if ('doctrine' === $params['scheme']) {
            if (null === $this->managerRegistry) {
                throw new InvalidArgumentException('Cannot use an existing connection without a ManagerRegistry');
            }

            $connectionName = $params['host'] ?? 'default';
            $tableName = $params['path'] ?? 'messenger';
            if (0 === \strpos($tableName, '/')) {
                $tableName = \substr($tableName, 1);
            }

            \parse_str($params['query'] ?? '', $opts);
            $options = \array_merge($opts, $options, ['table_name' => $tableName]);

            $connection = $this->managerRegistry->getConnection($connectionName);
        } else {
            $path = $params['path'];
            if (0 === \strpos($path, '/')) {
                $path = \substr($path, 1);
            }

            if ('sqlite' === $params['scheme'] || 'sqlite3' === $params['scheme']) {
                // SQLite has a little different handling. First we should determine the filename.
                $databaseName = $path;
                $tableName = 'messenger';

                $reverse = \strrev($path);
                $count = \substr_count($path, '/');
                for ($i = 1; $i < $count; ++$i) {
                    $chunks = \explode('/', $reverse, $i);
                    $tmp = \strrev($chunks[$i - 1]);
                    $ext = \pathinfo($tmp, PATHINFO_EXTENSION);

                    if ('' !== $ext || \is_file($ext)) {
                        $databaseName = $tmp;
                        $tableName = isset($chunks[$i - 2]) ? \strrev($chunks[$i - 2]) : 'messenger';
                        break;
                    }
                }

                if (0 === \strpos($databaseName, '/')) {
                    $databaseName = '/'.$databaseName;
                }
            } else {
                [$databaseName, $tableName] = \explode('/', $path, 2) + [null, 'messenger'];
            }

            $params['path'] = '/'.$databaseName;

            \parse_str($params['query'] ?? '', $opts);
            $options = \array_merge($opts, $options, ['table_name' => $tableName]);

            $connection = DriverManager::getConnection(['url' => UrlUtils::buildUrl($params)]);
        }

        return new DbalTransport($connection, $serializer, $options);
    }

    public function supports(string $dsn, array $options): bool
    {
        $scheme = \parse_url($dsn, PHP_URL_SCHEME);

        return 'doctrine' === $scheme || \in_array($scheme, self::DBAL_SUPPORTED_SCHEMES, true);
    }
}
