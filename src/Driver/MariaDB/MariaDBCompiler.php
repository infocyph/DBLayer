<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Driver\MariaDB;

use Infocyph\DBLayer\Driver\MySQLFamily\AbstractMySqlCompiler;

/**
 * MariaDB SQL compiler.
 */
final class MariaDBCompiler extends AbstractMySqlCompiler
{
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
        if ($update === []) {
            throw new \LogicException('MariaDB UPSERT requires at least one update column.');
        }

        $assignments = array_map(function (string $column): string {
            $wrapped = $this->wrapIdentifier($column);

            return $wrapped . ' = VALUES(' . $wrapped . ')';
        }, $update);

        return $insertSql . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $assignments);
    }
}
