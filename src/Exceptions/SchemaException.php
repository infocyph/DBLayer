<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Exceptions;

use RuntimeException;

final class SchemaException extends RuntimeException
{
    public static function invalid(string $message): self
    {
        return new self($message);
    }

    public static function unsupported(string $driver, string $operation): self
    {
        return new self(sprintf('Schema operation "%s" is not supported by the %s driver.', $operation, $driver));
    }
}
