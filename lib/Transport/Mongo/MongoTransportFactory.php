<?php declare(strict_types=1);

namespace Kcs\MessengerExtra\Transport\Mongo;

use Kcs\MessengerExtra\Utils\UrlUtils;
use MongoDB\Client;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Serializer Messenger Transport Factory using MongoDB as storage.
 *
 * @author Alessandro Chitolina <alekitto@gmail.com>
 */
class MongoTransportFactory implements TransportFactoryInterface
{
    public function createTransport(string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        $params = \parse_url($dsn);
        $path = $params['path'];
        if (0 === \strpos($path, '/')) {
            $path = \substr($path, 1);
        }

        [$databaseName, $tableName] = \explode('/', $path, 2) + ['default', 'messenger'];
        $params['path'] = '/';

        \parse_str($params['query'] ?? '', $opts);
        $options = \array_merge($opts, $options, [
            'database_name' => $databaseName,
            'collection_name' => $tableName,
        ]);

        return new MongoTransport(new Client(UrlUtils::buildUrl($params)), $serializer, $options);
    }

    public function supports(string $dsn, array $options): bool
    {
        $scheme = \parse_url($dsn, PHP_URL_SCHEME);

        return 'mongodb' === $scheme;
    }
}
