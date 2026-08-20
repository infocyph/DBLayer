<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Driver\MySQL;

use Infocyph\DBLayer\Driver\MySQLFamily\AbstractMySqlCompiler;

/**
 * MySQL SQL compiler.
 */
final class MySQLCompiler extends AbstractMySqlCompiler
{
    /**
     * @param list<string> $uniqueBy
     * @param list<string> $update
     */
    #[\Override]
    protected function compileUpsert(string $insertSql, array $uniqueBy, array $update): string
    {
        if ($uniqueBy === []) {
            throw new \LogicException('MySQL UPSERT requires at least one unique key column.');
        }

        if ($update === []) {
            throw new \LogicException('MySQL UPSERT requires at least one update column.');
        }

        $assignments = array_map(function (string $column): string {
            $wrapped = $this->wrapIdentifier($column);

            return $wrapped . ' = new_row.' . $wrapped;
        }, $update);

        return $insertSql . ' AS new_row ON DUPLICATE KEY UPDATE ' . implode(', ', $assignments);
    }
}