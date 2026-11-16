<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\OpenTelemetry\Helper;

readonly class UrlHelper
{
    public const string REDACTED = 'REDACTED';

    /**
     * The first four come from the semantic conventions.
     *
     * @var list<string>
     */
    public const array DEFAULT_SENSITIVE_QUERY_PARAMETERS = [
        'AWSAccessKeyId',
        'Signature',
        'sig',
        'X-Goog-Signature',
        'X-Amz-Signature',
        'X-Amz-Credential',
        'X-Amz-Security-Token',
        'token',
        'access_token',
        'refresh_token',
        'id_token',
        'api_key',
        'apikey',
        'api-key',
        'key',
        'secret',
        'client_secret',
        'password',
        'passwd',
        'pwd',
        'auth',
        'authorization',
        'code',
        'jwt',
        'session',
        'sessionid',
        'signature',
    ];

    /**
     * @param list<string> $sensitiveQueryParameters
     */
    public static function sanitize(
        string $url,
        array $sensitiveQueryParameters = self::DEFAULT_SENSITIVE_QUERY_PARAMETERS,
    ): string {
        $url = self::redactUserInfo($url);

        $queryStart = mb_strpos($url, '?');
        if ($queryStart === false || $sensitiveQueryParameters === []) {
            return $url;
        }

        $fragment = '';
        $fragmentStart = mb_strpos($url, '#', $queryStart);
        if ($fragmentStart !== false) {
            $fragment = mb_substr($url, $fragmentStart);
            $url = mb_substr($url, 0, $fragmentStart);
        }

        $base = mb_substr($url, 0, $queryStart);
        $query = mb_substr($url, $queryStart + 1);

        return $base . '?' . self::redactQuery($query, $sensitiveQueryParameters) . $fragment;
    }

    /**
     * @param list<string> $sensitiveQueryParameters
     */
    public static function redactQuery(
        string $query,
        array $sensitiveQueryParameters = self::DEFAULT_SENSITIVE_QUERY_PARAMETERS,
    ): string {
        if ($query === '') {
            return $query;
        }

        $sensitive = array_flip(array_map(mb_strtolower(...), $sensitiveQueryParameters));
        $pairs = explode('&', $query);

        foreach ($pairs as $index => $pair) {
            $separator = mb_strpos($pair, '=');
            if ($separator === false) {
                continue;
            }

            $name = mb_strtolower(urldecode(mb_substr($pair, 0, $separator)));
            // token[] and auth[token] match too
            $segments = preg_split('~[\[\]]+~', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [$name];

            if (isset($sensitive[$name]) || array_any($segments, static fn (string $segment): bool => isset($sensitive[$segment]))) {
                $pairs[$index] = mb_substr($pair, 0, $separator) . '=' . self::REDACTED;
            }
        }

        return implode('&', $pairs);
    }

    private static function redactUserInfo(string $url): string
    {
        if (!str_contains($url, '@')) {
            return $url;
        }

        // scheme://user:pass@host or //user:pass@host; up to the last "@" (unencoded "@" in the password)
        $result = preg_replace_callback(
            '~^((?:[a-z][a-z0-9+.\-]*:)?//)([^/?#]*)@~i',
            static function (array $matches): string {
                $userInfo = $matches[2];
                $redacted = str_contains($userInfo, ':')
                    ? self::REDACTED . ':' . self::REDACTED
                    : self::REDACTED;

                return $matches[1] . $redacted . '@';
            },
            $url
        );

        return $result ?? $url;
    }
}
