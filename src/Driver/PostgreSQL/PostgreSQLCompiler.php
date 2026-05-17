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
    protected function wrapIdentifier(string $identifier): string
    {
        return $this->wrapDelimitedIdentifier($identifier, '"');
    }
}
