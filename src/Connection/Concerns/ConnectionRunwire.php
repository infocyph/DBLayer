<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Connection\Concerns;

use Fiber;
use Infocyph\CacheLayer\Integration\Runwire\RunwireExecutionContext;

use Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration as CacheRunwireIntegration;
use Infocyph\DBLayer\Connection\RunwireBindingPolicy;
use Infocyph\DBLayer\Exceptions\ConnectionException;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\Enum\RuntimeCapability;
use Infocyph\Runwire\RuntimeContext;

trait ConnectionRunwire
{
    /** @var Fiber<mixed, mixed, mixed, mixed>|null */
    private ?Fiber $runwireCarrier = null;

    /** @var null|callable():?RunwireExecutionContext */
    private $runwireContextProvider;

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
        $this->assertRunwireCarrier();
        if ($this->runwireRuntime === null) {
            $context = $this->runwireContextProvider === null ? null : ($this->runwireContextProvider)();

            return $context === null ? null : [
                'runtime' => $context->runtime,
                'request' => $context->request,
                'scope' => $context->scope,
            ];
        }

        return [
            'runtime' => $this->runwireRuntime,
            'request' => $this->runwireRequest,
            'scope' => $this->runwireScope,
        ];
    }

    /**
     * Execute one deferred iterator operation with a previously captured binding.
     *
     * @template TResult
     * @param array{
     *   runtime:RuntimeContext,
     *   request:?RequestContext,
     *   scope:?CoroutineScope
     * }|null $binding
     * @param callable():TResult $callback
     * @return TResult
     *
     * @internal
     */
    public function runWithRunwireBinding(?array $binding, callable $callback): mixed
    {
        if ($binding === null) {
            return $callback();
        }

        return $this->withRunwire(
            $binding['runtime'],
            $callback,
            $binding['request'],
            $binding['scope'],
        );
    }

    /**
     * Borrow the caller's current context at each native operation boundary.
     * The provider must resolve task-local state, not retain a request.
     *
     * @param null|callable():?RunwireExecutionContext $provider
     */
    public function setRunwireContextProvider(?callable $provider): void
    {
        $this->runwireContextProvider = $provider;
    }

    /**
     * Execute work while borrowing host-owned Runwire execution context.
     *
     * DBLayer never starts, globally binds, stops, or releases the host runtime.
     *
     * @template TResult
     * @param callable():TResult $callback
     * @return TResult
     */
    public function withRunwire(
        RuntimeContext $runtime,
        callable $callback,
        ?RequestContext $request = null,
        ?CoroutineScope $scope = null,
    ): mixed {
        $this->assertRunwireCarrier();
        RunwireBindingPolicy::assertAllowed(
            $runtime,
            $request,
            $scope,
            $this->runwireRuntime,
            $this->runwireRequest,
            $this->runwireScope,
        );

        $previousCarrier = $this->runwireCarrier;
        $previousRuntime = $this->runwireRuntime;
        $previousRequest = $this->runwireRequest;
        $previousScope = $this->runwireScope;
        $effectiveRequest = $request ?? $previousRequest;
        $effectiveScope = $scope ?? $previousScope;

        $this->runwireCarrier = Fiber::getCurrent();
        $this->runwireRuntime = $runtime;
        $this->runwireRequest = $effectiveRequest;
        $this->runwireScope = $effectiveScope;

        $checker = static fn(): bool => ($effectiveRequest?->completed() ?? false)
            || ($effectiveRequest?->cancelled() ?? false)
            || ($effectiveScope?->cancellation()->isCancelled() ?? false);
        $remainingSeconds = RunwireBindingPolicy::remainingSeconds($effectiveRequest, $effectiveScope);
        $operation = function () use ($callback): mixed {
            $this->assertQueryCheckpoint(microtime(true));

            return $callback();
        };
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
            $this->runwireCarrier = $previousCarrier;
            $this->runwireRuntime = $previousRuntime;
            $this->runwireRequest = $previousRequest;
            $this->runwireScope = $previousScope;
        }
    }

    private function assertRunwireCarrier(): void
    {
        if ($this->runwireRuntime !== null && $this->runwireCarrier !== Fiber::getCurrent()) {
            throw ConnectionException::invalidConfiguration('DBLayer connection is already borrowed by another execution carrier.');
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

    /** @return array{runtime:RuntimeContext,request:?RequestContext,scope:?CoroutineScope}|null */
    private function runwireOperationBinding(): ?array
    {
        if ($this->runwireRuntime !== null) {
            $this->assertRunwireCarrier();

            return null;
        }

        return $this->runwireContextProvider === null ? null : $this->runwireBinding();
    }
}
