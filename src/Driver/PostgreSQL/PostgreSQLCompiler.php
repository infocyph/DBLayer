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
    protected function wrapIdentifier(string $identifier): string
    {
        $identifier = trim($identifier);

        if ($identifier === '' || $identifier === '*') {
            return $identifier;
        }

        if (str_contains($identifier, '(') || str_contains($identifier, ' ')) {
            return $identifier;
        }

        $parts = explode('.', $identifier);

        $wrapped = array_map(
            static fn(string $part): string => $part === '*' ? '*' : sprintf('"%s"', $part),
            $parts,
        );

        return implode('.', $wrapped);
    }
}
