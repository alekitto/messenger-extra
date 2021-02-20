<?php

declare(strict_types=1);

namespace Kcs\MessengerExtra\Utils;

class UrlUtils
{
    /**
     * Build URL from parse_url array params.
     *
     * @param array<string, string> $url
     *
     * @phpstan-param array{user?:string, pass?:string, scheme?:string, host?:string, port?:string, path?:string, query?:string, fragment?:string} $url
     */
    public static function buildUrl(array $url): string
    {
        $authority = ($url['user'] ?? '') . (isset($url['pass']) ? ':' . $url['pass'] : '');

        return (isset($url['scheme']) ? $url['scheme'] . '://' : '') .
            ($authority ? $authority . '@' : '') .
            ($url['host'] ?? '') .
            (isset($url['port']) ? ':' . $url['port'] : '') .
            ($url['path'] ?? '') .
            (isset($url['query']) ? '?' . $url['query'] : '') .
            (isset($url['fragment']) ? '#' . $url['fragment'] : '');
    }
}
