<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Connection\Concerns;

use Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration as CacheRunwireIntegration;
use Infocyph\DBLayer\Connection\RunwireBindingPolicy;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\Enum\RuntimeCapability;
use Infocyph\Runwire\RuntimeContext;

trait ConnectionRunwire
{
    /**
     * Host-owned Runwire request currently borrowed by this connection.
     */
    private ?RequestContext $runwireRequest = null;

    /**
     * Host-owned Runwire runtime currently borrowed by this connection.
     */
    private ?RuntimeContext $runwireRuntime = null;

    /**
     * Host-owned Runwire coroutine scope currently borrowed by this connection.
     */
    private ?CoroutineScope $runwireScope = null;

    /**
     * Sleep during bounded retry/backoff using a borrowed Runwire scope when available.
     *
     * @internal
     */
    public function cooperativeSleep(float $seconds): void
    {
        if ($seconds <= 0.0) {
            return;
        }

        $this->assertQueryCheckpoint(microtime(true));

        if (
            $this->runwireRuntime?->supports(RuntimeCapability::RUNWIRE_COROUTINES) === true
            && $this->runwireScope !== null
        ) {
            $this->runwireScope->sleep($seconds);
            $this->assertQueryCheckpoint(microtime(true));

            return;
        }

        usleep((int) min(PHP_INT_MAX, ceil($seconds * 1_000_000)));
        $this->assertQueryCheckpoint(microtime(true));
    }

    /**
     * Return the active host-owned Runwire binding for lazy DBLayer paths.
     *
     * @return array{runtime:RuntimeContext,request:?RequestContext,scope:?CoroutineScope}|null
     *
     * @internal
     */
    public function runwireBinding(): ?array
    {
        if ($this->runwireRuntime === null) {
            return null;
        }

        return [
            'runtime' => $this->runwireRuntime,
            'request' => $this->runwireRequest,
            'scope' => $this->runwireScope,
        ];
    }

    /**
     * Execute work while borrowing host-owned Runwire execution context.
     *
     * DBLayer never starts, globally binds, stops, or releases the host runtime.
     */
    public function withRunwire(
        RuntimeContext $runtime,
        callable $callback,
        ?RequestContext $request = null,
        ?CoroutineScope $scope = null,
    ): mixed {
        RunwireBindingPolicy::assertAllowed(
            $runtime,
            $request,
            $scope,
            $this->runwireRuntime,
            $this->runwireRequest,
            $this->runwireScope,
        );

        $previousRuntime = $this->runwireRuntime;
        $previousRequest = $this->runwireRequest;
        $previousScope = $this->runwireScope;
        $effectiveRequest = $request ?? $previousRequest;
        $effectiveScope = $scope ?? $previousScope;

        $this->runwireRuntime = $runtime;
        $this->runwireRequest = $effectiveRequest;
        $this->runwireScope = $effectiveScope;

        $checker = static fn(): bool => ($effectiveRequest?->completed() ?? false)
            || ($effectiveRequest?->cancelled() ?? false)
            || ($effectiveScope?->cancellation()->isCancelled() ?? false);
        $remainingSeconds = RunwireBindingPolicy::remainingSeconds($effectiveRequest, $effectiveScope);
        $operation = static fn(): mixed => $callback();
        $withCancellation = fn(): mixed => $this->withQueryCancellation($checker, $operation);
        $withBudget = $remainingSeconds === null
            ? $withCancellation
            : fn(): mixed => $this->withQueryDeadline($remainingSeconds, $withCancellation);

        try {
            return $this->executeRunwireBinding(
                $runtime,
                $effectiveRequest,
                $effectiveScope,
                $withBudget,
            );
        } finally {
            $this->runwireRuntime = $previousRuntime;
            $this->runwireRequest = $previousRequest;
            $this->runwireScope = $previousScope;
        }
    }

    private function executeRunwireBinding(
        RuntimeContext $runtime,
        ?RequestContext $request,
        ?CoroutineScope $scope,
        callable $callback,
    ): mixed {
        if (CacheRunwireIntegration::runtime() === $runtime) {
            return CacheRunwireIntegration::share($request, $scope, $callback);
        }

        return $callback();
    }

}
