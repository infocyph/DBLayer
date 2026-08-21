<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Connection;

use PDO;
use PDOException;

final class ReadReplicaSessionPolicy
{
    public static function apply(string $driver, ?PDO $pdo, bool $enforceReadOnly = false): void
    {
        if (!$pdo instanceof PDO) {
            return;
        }

        if ($driver === 'sqlite') {
            try {
                $pdo->exec('pragma query_only = on');
            } catch (PDOException) {
            }

            return;
        }

        if (!$enforceReadOnly) {
            return;
        }

        try {
            if ($driver === 'pgsql') {
                $pdo->exec('set default_transaction_read_only = on');
            } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
                $pdo->exec('set session transaction read only');
            }
        } catch (PDOException) {
        }
    }
}
