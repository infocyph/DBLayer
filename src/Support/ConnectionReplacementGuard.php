<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Support;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Exceptions\ConnectionException;

/**
 * Internal guard for safely replacing facade-owned connection instances.
 */
final class ConnectionReplacementGuard
{
    private function __construct() {}

    public static function disconnect(?Connection $connection, string $activeTransactionMessage): void
    {
        if (!$connection instanceof Connection) {
            return;
        }

        if ($connection->inTransaction()) {
            throw ConnectionException::invalidConfiguration($activeTransactionMessage);
        }

        $connection->disconnect();
    }
}
