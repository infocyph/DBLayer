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
    protected function wrapIdentifier(string $identifier): string
    {
        $identifier = trim($identifier);

        if ($identifier === '*' || $identifier === '') {
            return $identifier;
        }

        if (str_contains($identifier, '(') || str_contains($identifier, ' ')) {
            return $identifier;
        }

        $parts = explode('.', $identifier);

        $wrapped = array_map(
            static fn (string $part): string => $part === '*' ? '*' : sprintf('"%s"', $part),
            $parts
        );

        return implode('.', $wrapped);
    }
}
