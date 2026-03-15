<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\OpenTelemetry\Helper;

readonly class UrlHelper
{
    public static function sanitize(string $url): string
    {
        if (!str_contains($url, '@')) {
            return $url;
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['user'])) {
            return $url;
        }

        $user = 'xxx';
        $pass = isset($parts['pass']) ? ':xxx' : '';
        $userInfo = $user . $pass . '@';

        $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        return $scheme . $userInfo . $host . $port . $path . $query . $fragment;
    }
}
