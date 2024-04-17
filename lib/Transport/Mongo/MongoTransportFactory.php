<?php

declare(strict_types=1);

namespace Kcs\MessengerExtra\Transport\Mongo;

use Kcs\MessengerExtra\Utils\UrlUtils;
use MongoDB\Client;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

use function array_merge;
use function assert;
use function explode;
use function is_array;
use function parse_str;
use function Safe\parse_url;
use function Safe\substr;
use function strpos;

use const PHP_URL_SCHEME;

/**
 * Serializer Messenger Transport Factory using MongoDB as storage.
 */
class MongoTransportFactory implements TransportFactoryInterface
{
    /** @param array<string, mixed> $options */
    public function createTransport(string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        $params = parse_url($dsn);
        assert(is_array($params));

        $path = $params['path'];
        if (strpos($path, '/') === 0) {
            $path = substr($path, 1);
        }

        [$databaseName, $tableName] = explode('/', $path, 2) + [null, null];
        $databaseName = $databaseName ?: 'default';
        $tableName = $tableName ?: 'messenger';

        $params['path'] = '/';
        parse_str($params['query'] ?? '', $opts);
        $auth = isset($params['user']) ? ['authSource' => $opts['authSource'] ?? $databaseName] : [];

        $options = array_merge($opts, $options, [
            'database_name' => $databaseName,
            'collection_name' => $tableName,
        ]);

        /** @phpstan-ignore-next-line */
        return new MongoTransport(new Client(UrlUtils::buildUrl($params), $auth), $serializer, $options);
    }

    /** @param array<string, mixed> $options */
    public function supports(string $dsn, array $options): bool
    {
        $scheme = parse_url($dsn, PHP_URL_SCHEME);

        return $scheme === 'mongodb' || $scheme === 'mongodb+srv';
    }
}
