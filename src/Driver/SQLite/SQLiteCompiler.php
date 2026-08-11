<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Driver\SQLite;

use Infocyph\DBLayer\Driver\AbstractSqlCompiler;

/**
 * SQLite SQL compiler.
 *
 * Uses generic SELECT compilation with ANSI-style quoting.
 */
final class SQLiteCompiler extends AbstractSqlCompiler
{
    #[\Override]
    protected function compileInsertIgnore(string $insertSql): string
    {
        return preg_replace('/\AINSERT\s+/i', 'INSERT OR IGNORE ', $insertSql, 1) ?? $insertSql;
    }

    #[\Override]
    protected function compileLock(string $lock): string
    {
        return match ($lock) {
            'update', 'shared' => '',
            default => throw new \LogicException("Unsupported SQLite lock mode [{$lock}]."),
        };
    }

    #[\Override]
    protected function compileReturning(string $sql, array $returning): string
    {
        return $sql . ' RETURNING ' . implode(', ', array_map(
            $this->wrapIdentifier(...),
            $returning,
        ));
    }

    #[\Override]
    protected function compileUpsert(string $insertSql, array $uniqueBy, array $update): string
    {
        if ($uniqueBy === []) {
            throw new \LogicException('SQLite UPSERT requires a conflict target.');
        }

        $conflict = implode(', ', array_map(
            $this->wrapIdentifier(...),
            $uniqueBy,
        ));
        if ($update === []) {
            return $insertSql . ' ON CONFLICT (' . $conflict . ') DO NOTHING';
        }

        $assignments = array_map(
            fn(string $column): string => $this->wrapIdentifier($column)
                . ' = excluded.' . $this->wrapIdentifier($column),
            $update,
        );

        return $insertSql . ' ON CONFLICT (' . $conflict . ') DO UPDATE SET '
            . implode(', ', $assignments);
    }

    #[\Override]
    protected function truncateStatementForTable(string $wrappedTable): string
    {
        return 'DELETE FROM ' . $wrappedTable;
    }

    #[\Override]
    protected function wrapIdentifier(string $identifier): string
    {
        return $this->wrapDelimitedIdentifier($identifier, '"');
    }
}
