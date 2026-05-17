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
    public static function apply(string $driver, ?PDO $pdo, bool $enforceReadOnly = false): void
    {
        if (!$pdo instanceof PDO) {
            return;
        }

        if ($driver === 'sqlite') {
            try {
                $pdo->exec('pragma query_only = on');
            } catch (PDOException) {
                // Best effort only.
            }

            return;
        }

        if (!$enforceReadOnly) {
            return;
        }

        try {
            if ($driver === 'pgsql') {
                $pdo->exec('set default_transaction_read_only = on');
            } elseif ($driver === 'mysql') {
                $pdo->exec('set session transaction read only');
            }
        } catch (PDOException) {
            // Best effort only.
        }
    }
}
