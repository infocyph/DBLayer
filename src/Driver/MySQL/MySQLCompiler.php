<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Driver\MySQL;

use Infocyph\DBLayer\Driver\AbstractSqlCompiler;

/**
 * MySQL/MariaDB SQL compiler.
 *
 * Inherits generic SELECT compilation and customises
 * identifier quoting (`schema`.`table`).
 */
final class MySQLCompiler extends AbstractSqlCompiler
{
    #[\Override]
    protected function compileInsertIgnore(string $insertSql): string
    {
        return preg_replace('/\AINSERT\s+/i', 'INSERT IGNORE ', $insertSql, 1) ?? $insertSql;
    }

    #[\Override]
    protected function compileUpsert(string $insertSql, array $uniqueBy, array $update): string
    {
        unset($uniqueBy);

        if ($update === []) {
            throw new \LogicException('MySQL UPSERT requires at least one update column.');
        }

        $assignments = array_map(function (string $column): string {
            $wrapped = $this->wrapIdentifier($column);

            return $wrapped . ' = VALUES(' . $wrapped . ')';
        }, $update);

        return $insertSql . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $assignments);
    }

    #[\Override]
    protected function truncateStatementForTable(string $wrappedTable): string
    {
        return 'DELETE FROM ' . $wrappedTable;
    }

    #[\Override]
    protected function wrapIdentifier(string $identifier): string
    {
        return $this->wrapDelimitedIdentifier($identifier, '`');
    }
}
