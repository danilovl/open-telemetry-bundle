<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\OpenTelemetry\Helper;

readonly class SqlHelper
{
    public static function buildSpanName(string $sql): string
    {
        $simplified = self::simplifySql($sql);

        foreach (['select', 'insert', 'update', 'delete', 'drop', 'alter', 'truncate'] as $op) {
            if (str_starts_with($simplified, $op . ' from ')) {
                $table = mb_substr($simplified, mb_strlen($op . ' from '));

                return sprintf('db.%s.%s', $table, $op);
            }
        }

        $sql = (string) preg_replace('~\s+~', ' ', mb_trim($sql));
        $op = mb_strtolower(explode(' ', $sql)[0] ?: 'sql');

        return sprintf('db.%s', $op);
    }

    public static function simplifySql(string $sql): string
    {
        $sql = (string) preg_replace('~\s+~', ' ', mb_trim($sql));

        $patterns = [
            'select' => '~^SELECT\s+.*?FROM\s+([a-zA-Z0-9_.]+)~i',
            'insert' => '~^INSERT\s+INTO\s+([a-zA-Z0-9_.]+)~i',
            'update' => '~^UPDATE\s+([a-zA-Z0-9_.]+)~i',
            'delete' => '~^DELETE\s+FROM\s+([a-zA-Z0-9_.]+)~i',
            'drop' => '~^DROP\s+TABLE\s+([a-zA-Z0-9_.]+)~i',
            'alter' => '~^ALTER\s+TABLE\s+([a-zA-Z0-9_.]+)~i',
            'truncate' => '~^TRUNCATE\s+(?:TABLE\s+)?([a-zA-Z0-9_.]+)~i',
        ];

        foreach ($patterns as $command => $pattern) {
            if (preg_match($pattern, $sql, $matches)) {
                return $command . ' from ' . mb_strtolower($matches[1]);
            }
        }

        return mb_strtolower($sql);
    }
}
