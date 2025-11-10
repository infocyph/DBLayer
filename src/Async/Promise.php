<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Async;

use Throwable;

/**
 * Promise
 * 
 * Simple Promise implementation for async operations.
 * Provides then/catch/finally chaining.
 * 
 * @package Infocyph\DBLayer\Async
 * @author Hasan
 */
class Promise
{
    /**
     * Promise states
     */
    private const STATE_PENDING = 'pending';
    private const STATE_FULFILLED = 'fulfilled';
    private const STATE_REJECTED = 'rejected';

    /**
     * Current state
     */
    protected string $state = self::STATE_PENDING;

    /**
     * Resolved value
     */
    protected mixed $value = null;

    /**
     * Success handlers
     */
    protected array $thenCallbacks = [];

    /**
     * Error handlers
     */
    protected array $catchCallbacks = [];

    /**
     * Finally handlers
     */
    protected array $finallyCallbacks = [];

    /**
     * Create a new promise
     */
    public function __construct(?callable $executor = null)
    {
        if ($executor) {
            try {
                $executor(
                    fn($value) => $this->resolve($value),
                    fn($reason) => $this->reject($reason)
                );
            } catch (Throwable $e) {
                $this->reject($e);
            }
        }
    }

    /**
     * Create a resolved promise
     */
    public static function resolve(mixed $value): static
    {
        $promise = new static();
        $promise->doResolve($value);
        return $promise;
    }

    /**
     * Create a rejected promise
     */
    public static function reject(Throwable $reason): static
    {
        $promise = new static();
        $promise->doReject($reason);
        return $promise;
    }

    /**
     * Wait for all promises to resolve
     */
    public static function all(array $promises): static
    {
        return new static(function ($resolve, $reject) use ($promises) {
            $results = [];
            $remaining = count($promises);

            if ($remaining === 0) {
                $resolve([]);
                return;
            }

            foreach ($promises as $key => $promise) {
                if (!$promise instanceof Promise) {
                    $promise = static::resolve($promise);
                }

                $promise->then(
                    function ($value) use ($key, &$results, &$remaining, $resolve) {
                        $results[$key] = $value;
                        $remaining--;

                        if ($remaining === 0) {
                            $resolve($results);
                        }
                    },
                    $reject
                );
            }
        });
    }

    /**
     * Race multiple promises
     */
    public static function race(array $promises): static
    {
        return new static(function ($resolve, $reject) use ($promises) {
            foreach ($promises as $promise) {
                if (!$promise instanceof Promise) {
                    $promise = static::resolve($promise);
                }

                $promise->then($resolve, $reject);
            }
        });
    }

    /**
     * Add success handler
     */
    public function then(?callable $onFulfilled = null, ?callable $onRejected = null): static
    {
        $promise = new static();

        $this->thenCallbacks[] = [
            'fulfilled' => $onFulfilled,
            'rejected' => $onRejected,
            'promise' => $promise,
        ];

        if ($this->state === self::STATE_FULFILLED && $onFulfilled) {
            $this->handleThen($onFulfilled, $promise);
        } elseif ($this->state === self::STATE_REJECTED && $onRejected) {
            $this->handleCatch($onRejected, $promise);
        }

        return $promise;
    }

    /**
     * Add error handler
     */
    public function catch(callable $onRejected): static
    {
        return $this->then(null, $onRejected);
    }

    /**
     * Add finally handler
     */
    public function finally(callable $onFinally): static
    {
        $this->finallyCallbacks[] = $onFinally;

        if ($this->state !== self::STATE_PENDING) {
            $onFinally();
        }

        return $this;
    }

    /**
     * Resolve the promise
     */
    protected function doResolve(mixed $value): void
    {
        if ($this->state !== self::STATE_PENDING) {
            return;
        }

        $this->state = self::STATE_FULFILLED;
        $this->value = $value;

        foreach ($this->thenCallbacks as $callback) {
            if ($callback['fulfilled']) {
                $this->handleThen($callback['fulfilled'], $callback['promise']);
            } else {
                $callback['promise']->doResolve($value);
            }
        }

        $this->runFinallyCallbacks();
    }

    /**
     * Reject the promise
     */
    protected function doReject(Throwable $reason): void
    {
        if ($this->state !== self::STATE_PENDING) {
            return;
        }

        $this->state = self::STATE_REJECTED;
        $this->value = $reason;

        foreach ($this->thenCallbacks as $callback) {
            if ($callback['rejected']) {
                $this->handleCatch($callback['rejected'], $callback['promise']);
            } else {
                $callback['promise']->doReject($reason);
            }
        }

        $this->runFinallyCallbacks();
    }

    /**
     * Handle then callback
     */
    protected function handleThen(callable $callback, Promise $promise): void
    {
        try {
            $result = $callback($this->value);

            if ($result instanceof Promise) {
                $result->then(
                    fn($value) => $promise->doResolve($value),
                    fn($reason) => $promise->doReject($reason)
                );
            } else {
                $promise->doResolve($result);
            }
        } catch (Throwable $e) {
            $promise->doReject($e);
        }
    }

    /**
     * Handle catch callback
     */
    protected function handleCatch(callable $callback, Promise $promise): void
    {
        try {
            $result = $callback($this->value);

            if ($result instanceof Promise) {
                $result->then(
                    fn($value) => $promise->doResolve($value),
                    fn($reason) => $promise->doReject($reason)
                );
            } else {
                $promise->doResolve($result);
            }
        } catch (Throwable $e) {
            $promise->doReject($e);
        }
    }

    /**
     * Run finally callbacks
     */
    protected function runFinallyCallbacks(): void
    {
        foreach ($this->finallyCallbacks as $callback) {
            try {
                $callback();
            } catch (Throwable $e) {
                // Ignore errors in finally callbacks
            }
        }
    }

    /**
     * Get promise state
     */
    public function getState(): string
    {
        return $this->state;
    }

    /**
     * Check if promise is pending
     */
    public function isPending(): bool
    {
        return $this->state === self::STATE_PENDING;
    }

    /**
     * Check if promise is fulfilled
     */
    public function isFulfilled(): bool
    {
        return $this->state === self::STATE_FULFILLED;
    }

    /**
     * Check if promise is rejected
     */
    public function isRejected(): bool
    {
        return $this->state === self::STATE_REJECTED;
    }

    /**
     * Wait for promise to settle (blocking)
     */
    public function wait(): mixed
    {
        while ($this->state === self::STATE_PENDING) {
            usleep(1000); // Sleep 1ms
        }

        if ($this->state === self::STATE_REJECTED) {
            throw $this->value;
        }

        return $this->value;
    }
}
