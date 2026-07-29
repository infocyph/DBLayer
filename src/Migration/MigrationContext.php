<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Migration;

use Closure;
use Infocyph\DBLayer\Exceptions\MigrationException;

final readonly class MigrationContext
{
    /** @param Closure():bool $heartbeat */
    public function __construct(
        public bool $pretending,
        private Closure $heartbeat,
        private string $lockKey,
    ) {}

    /**
     * Refresh and verify migration ownership during a long, chunked migration.
     */
    public function checkpoint(): void
    {
        if (!(($this->heartbeat)())) {
            throw MigrationException::leaseLost($this->lockKey);
        }
    }
}
