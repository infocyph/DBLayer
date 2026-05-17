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
