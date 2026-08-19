<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Driver\MySQLFamily;

use Infocyph\DBLayer\Driver\AbstractSqlCompiler;

/**
 * Shared SQL syntax for the MySQL protocol family.
 *
 * Vendor-specific UPSERT/RETURNING/EXPLAIN behavior stays in the concrete
 * MySQL and MariaDB compilers/drivers.
 */
abstract class AbstractMySqlCompiler extends AbstractSqlCompiler
{
    #[\Override]
    protected function compileInsertIgnore(string $insertSql): string
    {
        return preg_replace('/\AINSERT\s+/i', 'INSERT IGNORE ', $insertSql, 1) ?? $insertSql;
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
