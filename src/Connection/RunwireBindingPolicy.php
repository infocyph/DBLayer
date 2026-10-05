<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Connection;

use Infocyph\DBLayer\Exceptions\ConnectionException;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\RuntimeContext;

final class RunwireBindingPolicy
{
    public static function assertAllowed(
        RuntimeContext $runtime,
        ?RequestContext $request,
        ?CoroutineScope $scope,
        ?RuntimeContext $currentRuntime,
        ?RequestContext $currentRequest,
        ?CoroutineScope $currentScope,
    ): void {
        self::assertProcess($runtime);
        self::assertRequest($runtime, $request);
        self::assertNestedOwner(
            $currentRuntime,
            $runtime,
            'DBLayer connection is already borrowing a different Runwire runtime.',
        );
        self::assertNestedOwner(
            $currentRequest,
            $request,
            'DBLayer connection cannot switch Runwire request ownership inside a nested binding.',
        );
        self::assertNestedOwner(
            $currentScope,
            $scope,
            'DBLayer connection cannot switch Runwire coroutine scope inside a nested binding.',
        );
    }

    public static function remainingSeconds(
        ?RequestContext $request,
        ?CoroutineScope $scope,
    ): ?float {
        $requestRemaining = $request?->deadline()->remainingSeconds();
        $scopeRemaining = $scope?->cancellation()->deadline()->remainingSeconds();

        return match (true) {
            $requestRemaining === null => $scopeRemaining,
            $scopeRemaining === null => $requestRemaining,
            default => min($requestRemaining, $scopeRemaining),
        };
    }

    private static function assertNestedOwner(
        ?object $current,
        ?object $next,
        string $message,
    ): void {
        if ($current === null || $next === null || $current === $next) {
            return;
        }

        throw ConnectionException::invalidConfiguration($message);
    }

    private static function assertProcess(RuntimeContext $runtime): void
    {
        $pid = getmypid();
        $currentPid = is_int($pid) ? $pid : 0;

        if ($runtime->pid !== $currentPid) {
            throw ConnectionException::invalidConfiguration(
                'Runwire runtime PID does not match the current DBLayer process.',
            );
        }
    }

    private static function assertRequest(RuntimeContext $runtime, ?RequestContext $request): void
    {
        if ($request !== null && $request->runtime() !== $runtime) {
            throw ConnectionException::invalidConfiguration(
                'Runwire request context belongs to a different runtime.',
            );
        }

        if ($request?->completed() === true) {
            throw ConnectionException::invalidConfiguration(
                'Completed Runwire request context cannot be bound to DBLayer.',
            );
        }
    }
}
