<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Driver\SQLServer;

use Infocyph\DBLayer\Driver\AbstractSqlCompiler;
use Infocyph\DBLayer\Query\Core\QueryPayload;
use LogicException;

/**
 * Microsoft SQL Server (T-SQL) compiler.
 */
final class SQLServerCompiler extends AbstractSqlCompiler
{
    #[\Override]
    protected function compileCtePrefix(bool $recursive): string
    {
        return 'WITH';
    }

    #[\Override]
    protected function compileFrom(QueryPayload $payload): string
    {
        $from = parent::compileFrom($payload);

        if ($payload->lock === null || $from === '') {
            return $from;
        }

        if ($payload->sourceQuery !== null) {
            throw new LogicException('SQL Server lock hints are not supported on derived FROM sources.');
        }

        $hints = match ($payload->lock) {
            'update' => 'UPDLOCK, ROWLOCK',
            'shared' => 'HOLDLOCK, ROWLOCK',
            default => throw new LogicException("Unsupported SQL Server lock mode [{$payload->lock}]."),
        };

        return $from . ' WITH (' . $hints . ')';
    }

    #[\Override]
    protected function compileLimitOffset(QueryPayload $payload): string
    {
        if ($payload->offset === null) {
            return '';
        }

        if ($payload->orders === []) {
            throw new LogicException('SQL Server OFFSET requires an explicit deterministic ORDER BY clause.');
        }

        $sql = 'OFFSET ' . $payload->offset . ' ROWS';

        if ($payload->limit !== null) {
            $sql .= ' FETCH NEXT ' . $payload->limit . ' ROWS ONLY';
        }

        return $sql;
    }

    #[\Override]
    protected function compileLock(string $lock): string
    {
        return '';
    }

    /** @param list<string> $returning */
    #[\Override]
    protected function compileReturning(string $sql, array $returning): string
    {
        $projection = implode(', ', array_map(
            fn(string $column): string => $column === '*'
                ? 'INSERTED.*'
                : 'INSERTED.' . $this->wrapIdentifier($column),
            $returning,
        ));

        if (str_starts_with($sql, 'MERGE ')) {
            return rtrim($sql, ';') . ' OUTPUT ' . $projection . ';';
        }

        $rewritten = preg_replace(
            '/\sVALUES\s/i',
            ' OUTPUT ' . $projection . ' VALUES ',
            $sql,
            1,
        );

        if (!is_string($rewritten) || $rewritten === $sql) {
            throw new LogicException('Unable to place SQL Server OUTPUT clause in INSERT statement.');
        }

        return $rewritten;
    }

    #[\Override]
    protected function compileSelectList(QueryPayload $payload): string
    {
        $select = parent::compileSelectList($payload);

        if ($payload->limit === null || $payload->offset !== null) {
            return $select;
        }

        return preg_replace(
            '/\ASELECT\s+(DISTINCT\s+)?/i',
            'SELECT $1TOP (' . $payload->limit . ') ',
            $select,
            1,
        ) ?? $select;
    }

    /**
     * @param list<string> $uniqueBy
     * @param list<string> $update
     */
    #[\Override]
    protected function compileUpsert(string $insertSql, array $uniqueBy, array $update): string
    {
        if ($uniqueBy === []) {
            throw new LogicException('SQL Server UPSERT requires at least one unique key column.');
        }

        if (preg_match('/\AINSERT INTO (.+?) \((.+)\) VALUES (.+)\z/s', $insertSql, $matches) !== 1) {
            throw new LogicException('Unable to compile SQL Server MERGE from INSERT payload.');
        }

        $table = $matches[1];
        $columnSql = $matches[2];
        $valuesSql = $matches[3];
        $columns = array_map('trim', explode(',', $columnSql));

        $sourceValues = implode(', ', array_map(
            static fn(string $column): string => 'source.' . $column,
            $columns,
        ));
        $on = implode(' AND ', array_map(
            fn(string $column): string => 'target.' . $this->wrapIdentifier($column)
                . ' = source.' . $this->wrapIdentifier($column),
            $uniqueBy,
        ));

        $sql = 'MERGE INTO ' . $table . ' WITH (HOLDLOCK) AS target '
            . 'USING (VALUES ' . $valuesSql . ') AS source (' . $columnSql . ') '
            . 'ON ' . $on . ' ';

        if ($update !== []) {
            $assignments = implode(', ', array_map(
                fn(string $column): string => 'target.' . $this->wrapIdentifier($column)
                    . ' = source.' . $this->wrapIdentifier($column),
                $update,
            ));
            $sql .= 'WHEN MATCHED THEN UPDATE SET ' . $assignments . ' ';
        }

        return $sql
            . 'WHEN NOT MATCHED THEN INSERT (' . $columnSql . ') VALUES (' . $sourceValues . ');';
    }

    #[\Override]
    protected function truncateStatementForTable(string $wrappedTable): string
    {
        return 'DELETE FROM ' . $wrappedTable;
    }

    #[\Override]
    protected function wrapIdentifier(string $identifier): string
    {
        $identifier = trim($identifier);

        if ($identifier === '' || $identifier === '*') {
            return $identifier;
        }

        if (str_contains($identifier, '(') || str_contains($identifier, ' ')) {
            return $identifier;
        }

        return implode('.', array_map(
            static fn(string $part): string => $part === '*' ? '*' : '[' . str_replace(']', ']]', $part) . ']',
            explode('.', $identifier),
        ));
    }
}
