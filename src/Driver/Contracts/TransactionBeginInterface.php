<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Driver\Contracts;

use PDO;

/**
 * Optional driver hook for engine-specific top-level transaction begin semantics.
 */
interface TransactionBeginInterface
{
    public function beginTransaction(PDO $pdo): bool;
}
