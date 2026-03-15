<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Doctrine\TraceIgnore;

use Danilovl\OpenTelemetryBundle\Instrumentation\Doctrine\Interfaces\DoctrineTraceIgnoreInterface;
use Danilovl\OpenTelemetryBundle\Instrumentation\Doctrine\Middleware\DoctrineContextAttribute;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Throwable;

final class DefaultDoctrineTraceIgnore implements DoctrineTraceIgnoreInterface
{
    /** @var string[]|null */
    private ?array $managedTables = null;

    private ?string $managedTablesRegex = null;

    public function __construct(private readonly ?ManagerRegistry $registry = null) {}

    private const array IGNORED_SPAN_NAMES = [
        'db.connection',
        'db.begin',
        'db.prepare',
        'db.commit',
        'db.rollback',
    ];

    private const array IGNORED_SQL_QUERIES = [
        'SELECT TABLE_NAME FROM information_schema',
        'SELECT DATABASE()',
        'SELECT VERSION()',
    ];

    private const array IGNORED_DATABASE_NAMES = [
        'information_schema',
        'performance_schema',
        'mysql',
        'sys',
        'pg_catalog',
    ];

    public function shouldIgnore(string $spanName, array $context): bool
    {
        if (in_array($spanName, self::IGNORED_SPAN_NAMES, true)) {
            return true;
        }

        /** @var string|null $dbName */
        $dbName = $context[DoctrineContextAttribute::NAME->value] ?? null;
        if ($dbName !== null && in_array($dbName, self::IGNORED_DATABASE_NAMES, true)) {
            return true;
        }

        /** @var string|null $sql */
        $sql = $context[DoctrineContextAttribute::SQL->value] ?? null;
        if ($sql === null || $sql === '') {
            return false;
        }

        foreach (self::IGNORED_SQL_QUERIES as $ignoredSql) {
            if (mb_stripos($sql, $ignoredSql) !== false) {
                return true;
            }
        }

        foreach (self::IGNORED_DATABASE_NAMES as $ignoredDbName) {
            if (mb_stripos($sql, $ignoredDbName) !== false) {
                return true;
            }
        }

        $regex = $this->getManagedTablesRegex();

        return (bool) ($regex !== null && !preg_match($regex, $sql));
    }

    private function getManagedTablesRegex(): ?string
    {
        if ($this->managedTablesRegex === '') {
            return null;
        }

        if ($this->managedTablesRegex !== null) {
            return $this->managedTablesRegex;
        }

        $tables = $this->getManagedTables();
        if (empty($tables)) {
            $this->managedTablesRegex = '';

            return null;
        }

        $quotedTables = array_map(static fn (string $t) => preg_quote($t, '/'), $tables);
        $this->managedTablesRegex = '~\b(' . implode('|', $quotedTables) . ')\b~i';

        return $this->managedTablesRegex;
    }

    /**
     * @return string[]
     */
    private function getManagedTables(): array
    {
        if ($this->managedTables !== null) {
            return $this->managedTables;
        }

        $this->managedTables = [];
        if ($this->registry === null) {
            return [];
        }

        try {
            foreach ($this->registry->getManagers() as $manager) {
                if (!$manager instanceof EntityManagerInterface) {
                    continue;
                }

                foreach ($manager->getMetadataFactory()->getAllMetadata() as $metadata) {
                    $this->managedTables[] = $metadata->getTableName();

                    foreach ($metadata->getAssociationMappings() as $mapping) {
                        $joinTable = $mapping['joinTable'] ?? null;

                        if (is_array($joinTable)) {
                            $joinTableName = $joinTable['name'] ?? null;
                            if (is_string($joinTableName)) {
                                $this->managedTables[] = $joinTableName;
                            }
                        } elseif (is_object($joinTable) && isset($joinTable->name) && is_string($joinTable->name)) {
                            $this->managedTables[] = $joinTable->name;
                        }
                    }
                }
            }
        } catch (Throwable) {
            return [];
        }

        $this->managedTables = array_filter($this->managedTables);
        $this->managedTables = array_unique($this->managedTables);

        return $this->managedTables;
    }
}
