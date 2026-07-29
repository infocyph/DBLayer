<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Driver\Support;

use Infocyph\DBLayer\Query\Core\QueryPayload;
use Infocyph\DBLayer\Query\JoinClause;
use Infocyph\DBLayer\Query\QueryBuilder;

/**
 * Maps logical query table names to their prefixed physical identifiers.
 */
final class TablePrefixMapper
{
    /**
     * @return array<string,true>
     */
    public static function logicalTables(QueryPayload $payload, string $prefix): array
    {
        return self::collectTables($payload->table, $payload->joins, $prefix);
    }

    /**
     * @return array<string,true>
     */
    public static function logicalTablesForQuery(QueryBuilder $query, string $prefix): array
    {
        $components = $query->getComponents();

        return self::collectTables(
            $components['from'] ?? null,
            $components['joins'],
            $prefix,
        );
    }

    public static function physicalTable(string $table, string $prefix): string
    {
        if (
            $prefix === ''
            || str_contains($table, '.')
            || str_starts_with($table, $prefix)
        ) {
            return $table;
        }

        return $prefix . $table;
    }

    /** @param array<string,true> $tables */
    private static function collect(array &$tables, mixed $table, string $prefix): void
    {
        if (!is_string($table)) {
            return;
        }

        $table = trim($table);
        if (
            $table === ''
            || str_contains($table, '(')
            || str_contains($table, '.')
            || str_contains($table, ' ')
            || str_starts_with($table, $prefix)
        ) {
            return;
        }

        $tables[$table] = true;
    }

    /**
     * @param iterable<mixed> $joins
     * @return array<string,true>
     */
    private static function collectTables(mixed $table, iterable $joins, string $prefix): array
    {
        if ($prefix === '') {
            return [];
        }

        $tables = [];
        self::collect($tables, $table, $prefix);

        foreach ($joins as $join) {
            if ($join instanceof JoinClause) {
                self::collect($tables, $join->getTable(), $prefix);
            } elseif (is_array($join) && ($join['subquery'] ?? false) !== true) {
                self::collect($tables, $join['table'] ?? null, $prefix);
            }
        }

        return $tables;
    }
}
