<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Driver\MariaDB;

use Infocyph\DBLayer\Driver\MySQLFamily\AbstractMySqlCompiler;

/**
 * MariaDB SQL compiler.
 */
final class MariaDBCompiler extends AbstractMySqlCompiler
{
    /** @param list<string> $returning */
    #[\Override]
    protected function compileReturning(string $sql, array $returning): string
    {
        return $sql.' RETURNING '.implode(', ', array_map(
            $this->wrapIdentifier(...),
            $returning,
        ));
    }

    /**
     * @param  list<string>  $uniqueBy
     * @param  list<string>  $update
     */
    #[\Override]
    protected function compileUpsert(string $insertSql, array $uniqueBy, array $update): string
    {
        if ($uniqueBy === []) {
            throw new \LogicException('MariaDB UPSERT requires at least one unique key column.');
        }

        if ($update === []) {
            throw new \LogicException('MariaDB UPSERT requires at least one update column.');
        }

        $assignments = array_map(function (string $column): string {
            $wrapped = $this->wrapIdentifier($column);

            return $wrapped.' = VALUES('.$wrapped.')';
        }, $update);

        return $insertSql.' ON DUPLICATE KEY UPDATE '.implode(', ', $assignments);
    }
}
