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
    protected function wrapIdentifier(string $identifier): string
    {
        $identifier = trim($identifier);

        if ($identifier === '' || $identifier === '*') {
            return $identifier;
        }

        // Avoid wrapping obvious expressions / functions.
        if (str_contains($identifier, '(') || str_contains($identifier, ' ')) {
            return $identifier;
        }

        $parts = explode('.', $identifier);

        $wrapped = array_map(
            static fn(string $part): string => $part === '*' ? '*' : sprintf('`%s`', $part),
            $parts,
        );

        return implode('.', $wrapped);
    }
}
