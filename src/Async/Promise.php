<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Async;

use Throwable;

/**
 * Promise Implementation
 *
 * Lightweight promise implementation for async operations:
 * - Deferred resolution/rejection
 * - Chaining with then/catch/finally
 * - State management (pending/fulfilled/rejected)
 * - Error propagation
 *
 * @package Infocyph\DBLayer\Async
 * @author Hasan
 */
class Promise
{
    private const STATE_FULFILLED = 'fulfilled';
    /**
     * Promise states
     */
    private const STATE_PENDING = 'pending';
    private const STATE_REJECTED = 'rejected';

    /**
     * Finally callbacks
     */
    private array $onFinally = [];

    /**
     * Success callbacks
     */
    private array $onFulfilled = [];

    /**
     * Error callbacks
     */
    private array $onRejected = [];

    /**
     * Rejection reason
     */
    private ?Throwable $reason = null;

    /**
     * Current promise state
     */
    private string $state = self::STATE_PENDING;

    /**
     * Resolved value
     */
    private mixed $value = null;

    /**
     * Create a new promise instance
     */
    public function __construct(?callable $executor = null)
    {
        if ($executor !== null) {
            try {
                $executor(
                    fn ($value) => $this->resolve($value),
                    fn ($reason) => $this->reject($reason)
                );
            } catch (Throwable $e) {
                $this->reject($e);
            }
        }
    }

    /**
     * Wait for all promises to resolve
     */
    public static function all(array $promises): self
    {
        return new self(function ($resolve, $reject) use ($promises) {
            if (empty($promises)) {
                $resolve([]);
                return;
            }

            $results = [];
            $remaining = count($promises);

            foreach ($promises as $index => $promise) {
                if (!$promise instanceof self) {
                    $promise = self::resolve($promise);
                }

                $promise->then(
                    function ($value) use ($index, &$results, &$remaining, $resolve) {
                        $results[$index] = $value;
                        $remaining--;

                        if ($remaining === 0) {
                            ksort($results);
                            $resolve($results);
                        }
                    },
                    fn ($reason) => $reject($reason)
                );
            }
        });
    }

    /**
     * Wait for all promises to settle
     */
    public static function allSettled(array $promises): self
    {
        return new self(function ($resolve) use ($promises) {
            if (empty($promises)) {
                $resolve([]);
                return;
            }

            $results = [];
            $remaining = count($promises);

            foreach ($promises as $index => $promise) {
                if (!$promise instanceof self) {
                    $promise = self::resolve($promise);
                }

                $promise
                    ->then(
                        function ($value) use ($index, &$results, &$remaining, $resolve) {
                            $results[$index] = ['status' => 'fulfilled', 'value' => $value];
                            $remaining--;
                            if ($remaining === 0) {
                                ksort($results);
                                $resolve($results);
                            }
                        }
                    )
                    ->catch(
                        function ($reason) use ($index, &$results, &$remaining, $resolve) {
                            $results[$index] = ['status' => 'rejected', 'reason' => $reason];
                            $remaining--;
                            if ($remaining === 0) {
                                ksort($results);
                                $resolve($results);
                            }
                        }
                    );
            }
        });
    }

    /**
     * Wait for first promise to resolve
     */
    public static function race(array $promises): self
    {
        return new self(function ($resolve, $reject) use ($promises) {
            foreach ($promises as $promise) {
                if (!$promise instanceof self) {
                    $promise = self::resolve($promise);
                }

                $promise->then($resolve, $reject);
            }
        });
    }

    /**
     * Create a rejected promise
     */
    public static function reject(Throwable $reason): self
    {
        $promise = new self();
        $promise->doReject($reason);
        return $promise;
    }

    /**
     * Create a resolved promise
     */
    public static function resolve(mixed $value): self
    {
        $promise = new self();
        $promise->doResolve($value);
        return $promise;
    }

    /**
     * Add rejection handler
     */
    public function catch(callable $onRejected): self
    {
        return $this->then(null, $onRejected);
    }

    /**
     * Add finally handler
     */
    public function finally(callable $onFinally): self
    {
        $this->onFinally[] = $onFinally;

        if ($this->state !== self::STATE_PENDING) {
            $this->callHandlers($this->onFinally);
        }

        return $this;
    }

    /**
     * Get promise state
     */
    public function getState(): string
    {
        return $this->state;
    }

    /**
     * Check if promise is fulfilled
     */
    public function isFulfilled(): bool
    {
        return $this->state === self::STATE_FULFILLED;
    }

    /**
     * Check if promise is pending
     */
    public function isPending(): bool
    {
        return $this->state === self::STATE_PENDING;
    }

    /**
     * Check if promise is rejected
     */
    public function isRejected(): bool
    {
        return $this->state === self::STATE_REJECTED;
    }

    /**
     * Add fulfillment handler
     */
    public function then(?callable $onFulfilled = null, ?callable $onRejected = null): self
    {
        $promise = new self();

        $this->onFulfilled[] = function ($value) use ($promise, $onFulfilled) {
            if ($onFulfilled === null) {
                $promise->resolve($value);
                return;
            }

            try {
                $result = $onFulfilled($value);
                $promise->resolve($result);
            } catch (Throwable $e) {
                $promise->reject($e);
            }
        };

        if ($onRejected !== null) {
            $this->onRejected[] = function ($reason) use ($promise, $onRejected) {
                try {
                    $result = $onRejected($reason);
                    $promise->resolve($result);
                } catch (Throwable $e) {
                    $promise->reject($e);
                }
            };
        } else {
            $this->onRejected[] = fn ($reason) => $promise->reject($reason);
        }

        if ($this->state === self::STATE_FULFILLED) {
            $this->callHandlers($this->onFulfilled, $this->value);
        } elseif ($this->state === self::STATE_REJECTED) {
            $this->callHandlers($this->onRejected, $this->reason);
        }

        return $promise;
    }

    /**
     * Wait for promise to settle and return value
     */
    public function wait(): mixed
    {
        while ($this->state === self::STATE_PENDING) {
            usleep(1000); // 1ms
        }

        if ($this->state === self::STATE_REJECTED) {
            throw $this->reason;
        }

        return $this->value;
    }

    /**
     * Call handlers
     */
    private function callHandlers(array &$handlers, mixed $arg = null): void
    {
        foreach ($handlers as $handler) {
            try {
                if ($arg !== null) {
                    $handler($arg);
                } else {
                    $handler();
                }
            } catch (Throwable $e) {
                // Silently catch to prevent unhandled exceptions
            }
        }

        $handlers = [];
    }

    /**
     * Reject the promise
     */
    private function doReject(Throwable $reason): void
    {
        if ($this->state !== self::STATE_PENDING) {
            return;
        }

        $this->state = self::STATE_REJECTED;
        $this->reason = $reason;

        $this->callHandlers($this->onRejected, $reason);
        $this->callHandlers($this->onFinally);
    }

    /**
     * Resolve the promise
     */
    private function doResolve(mixed $value): void
    {
        if ($this->state !== self::STATE_PENDING) {
            return;
        }

        if ($value instanceof self) {
            $value->then(
                fn ($v) => $this->doResolve($v),
                fn ($r) => $this->doReject($r)
            );
            return;
        }

        $this->state = self::STATE_FULFILLED;
        $this->value = $value;

        $this->callHandlers($this->onFulfilled, $value);
        $this->callHandlers($this->onFinally);
    }
}