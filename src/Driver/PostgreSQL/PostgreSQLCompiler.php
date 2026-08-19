<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Driver\PostgreSQL;

use Infocyph\DBLayer\Driver\AbstractSqlCompiler;

/**
 * PostgreSQL SQL compiler.
 *
 * Reuses generic SELECT compilation with PostgreSQL-style quoting.
 */
final class PostgreSQLCompiler extends AbstractSqlCompiler
{
    #[\Override]
    protected function compileLock(string $lock): string
    {
        return $lock === 'update' ? 'FOR UPDATE' : 'FOR SHARE';
    }

    /** @param list<string> $returning */
    #[\Override]
    protected function compileReturning(string $sql, array $returning): string
    {
        return $sql . ' RETURNING ' . implode(', ', array_map(
            $this->wrapIdentifier(...),
            $returning,
        ));
    }

    /**
     * @param list<string> $uniqueBy
     * @param list<string> $update
     */
    #[\Override]
    protected function compileUpsert(string $insertSql, array $uniqueBy, array $update): string
    {
        if ($uniqueBy === []) {
            throw new \LogicException('PostgreSQL UPSERT requires a conflict target.');
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
        return 'TRUNCATE TABLE ' . $wrappedTable;
    }

    #[\Override]
    protected function wrapIdentifier(string $identifier): string
    {
        return $this->wrapDelimitedIdentifier($identifier, '"');
    }
}
