<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Connection;

use PDO;
use PDOException;

/**
 * Applies driver-specific session policy for read-replica handles.
 */
final class ReadReplicaSessionPolicy
{
    /**
     * Apply best-effort read-only behavior to a read handle.
     */
    public static function apply(string $driver, ?PDO $pdo): void
    {
        if (! $pdo instanceof PDO || $driver !== 'sqlite') {
            return;
        }

        try {
            $pdo->exec('pragma query_only = on');
        } catch (PDOException) {
            // Best effort only.
        }
    }
}
