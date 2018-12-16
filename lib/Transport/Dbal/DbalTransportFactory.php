<?php declare(strict_types=1);

namespace Kcs\MessengerExtra\Transport\Dbal;

use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\DBAL\DriverManager;
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
    private const DBAL_SUPPORTED_SCHEMES = [
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

    /**
     * @var SerializerInterface|null
     */
    private $serializer;

    public function __construct(?ManagerRegistry $managerRegistry = null, ?SerializerInterface $serializer = null)
    {
        $this->managerRegistry = $managerRegistry;
        $this->serializer = $serializer;
    }

    public function createTransport(string $dsn, array $options): TransportInterface
    {
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
            [$databaseName, $tableName] = \explode($params['path'], 2) + [null, 'messenger'];
            $params['path'] = $databaseName;

            \parse_str($params['query'] ?? '', $opts);
            $options = \array_merge($opts, $options, ['table_name' => $tableName]);

            $connection = DriverManager::getConnection(['url' => self::build_url($params)]);
        }

        return new DbalTransport($connection, $this->serializer, $options);
    }

    public function supports(string $dsn, array $options): bool
    {
        $scheme = \parse_url($dsn, PHP_URL_SCHEME);

        return 'doctrine' === $scheme || \in_array($scheme, self::DBAL_SUPPORTED_SCHEMES, true);
    }

    private static function build_url(array $url): string
    {
        $authority = ($url['user'] ?? '').(isset($url['pass']) ? ':'.$url['pass'] : '');

        return
            (isset($url['scheme']) ? $url['scheme'].'://' : '').
            ($url['host'] ?? '').
            (isset($url['port']) ? ':'.$url['port'] : '').
            ($authority ? $authority.'@' : '').
            ($url['path'] ?? '').
            (isset($url['port']) ? '?'.$url['port'] : '').
            (isset($url['fragment']) ? '#'.$url['fragment'] : '')
        ;
    }
}
