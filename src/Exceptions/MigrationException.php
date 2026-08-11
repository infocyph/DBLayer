<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Exceptions;

use RuntimeException;
use Throwable;

final class MigrationException extends RuntimeException
{
    public static function cleanupAlsoFailed(Throwable $primary, Throwable $cleanup): self
    {
        return new self(
            sprintf('%s Cleanup also failed: %s', $primary->getMessage(), $cleanup->getMessage()),
            (int) $primary->getCode(),
            $primary,
        );
    }

    public static function destructiveDenied(string $operation): self
    {
        return new self(sprintf('Destructive migration operation "%s" requires explicit authorization.', $operation));
    }

    public static function duplicate(string $id): self
    {
        return new self(sprintf('Duplicate migration identifier "%s".', $id));
    }

    public static function failed(string $id, Throwable $error): self
    {
        return new self(
            sprintf('Migration "%s" failed: %s', $id, $error->getMessage()),
            (int) $error->getCode(),
            $error,
        );
    }

    public static function leaseLost(string $key): self
    {
        return new self(sprintf('Migration lease "%s" was lost; execution stopped.', $key));
    }

    public static function lockUnavailable(string $key): self
    {
        return new self(sprintf('Unable to acquire migration lease "%s".', $key));
    }
}
