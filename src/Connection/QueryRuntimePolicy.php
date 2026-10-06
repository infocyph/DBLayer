<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Connection;

use Infocyph\DBLayer\Exceptions\ConnectionException;

final class QueryRuntimePolicy
{
    /**
     * @param null|callable():bool $checker
     */
    public static function assertNotCancelled(?callable $checker): void
    {
        if ($checker !== null && $checker()) {
            throw ConnectionException::queryCancelled();
        }
    }

    public static function assertWithinDeadline(?float $deadlineAt, float $startedAt): void
    {
        if ($deadlineAt === null || microtime(true) <= $deadlineAt) {
            return;
        }

        throw ConnectionException::queryTimeout(microtime(true) - $startedAt);
    }
}
