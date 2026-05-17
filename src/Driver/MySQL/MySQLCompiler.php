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
    protected function wrapIdentifier(string $identifier): string
    {
        return $this->wrapDelimitedIdentifier($identifier, '`');
    }
}
