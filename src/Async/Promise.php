<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Async;

use Throwable;

/**
 * Promise Implementation
 *
 * Lightweight promise abstraction for async operations.
 * This is intentionally minimal and primarily used as a
 * compositional wrapper around runtime-native async APIs.
 */
final class Promise
{
    private const STATE_PENDING   = 'pending';
    private const STATE_FULFILLED = 'fulfilled';
    private const STATE_REJECTED  = 'rejected';

    /**
     * @var callable[]
     */
    private array $onFulfilled = [];

    /**
     * @var callable[]
     */
    private array $onRejected = [];

    /**
     * @var callable[]
     */
    private array $onFinally = [];

    private string $state = self::STATE_PENDING;

    private mixed $value = null;

    private ?Throwable $reason = null;

    /**
     * @param callable(callable(mixed):void, callable(Throwable):void):void|null $executor
     */
    public function __construct(?callable $executor = null)
    {
        if ($executor !== null) {
            try {
                $executor(
                  fn (mixed $value) => $this->doResolve($value),
                  fn (Throwable $reason) => $this->doReject($reason)
                );
            } catch (Throwable $e) {
                $this->doReject($e);
            }
        }
    }

    /**
     * Create a promise that resolves when all promises resolve.
     *
     * @param array<int, Promise|mixed> $promises
     */
    public static function all(array $promises): self
    {
        return new self(function (callable $resolve, callable $reject) use ($promises): void {
            if ($promises === []) {
                $resolve([]);
                return;
            }

            $results   = [];
            $remaining = count($promises);

            foreach ($promises as $index => $promise) {
                if (!$promise instanceof self) {
                    $promise = self::resolve($promise);
                }

                $promise->then(
                  function (mixed $value) use ($index, &$results, &$remaining, $resolve): void {
                      $results[$index] = $value;
                      $remaining--;

                      if ($remaining === 0) {
                          ksort($results);
                          $resolve($results);
                      }
                  },
                  static function (Throwable $reason) use ($reject): void {
                      $reject($reason);
                  }
                );
            }
        });
    }

    /**
     * Create a promise that resolves when all promises settle.
     *
     * @param array<int, Promise|mixed> $promises
     */
    public static function allSettled(array $promises): self
    {
        return new self(function (callable $resolve) use ($promises): void {
            if ($promises === []) {
                $resolve([]);
                return;
            }

            $results   = [];
            $remaining = count($promises);

            foreach ($promises as $index => $promise) {
                if (!$promise instanceof self) {
                    $promise = self::resolve($promise);
                }

                $promise
                  ->then(
                    function (mixed $value) use ($index, &$results, &$remaining, $resolve): void {
                        $results[$index] = [
                          'status' => 'fulfilled',
                          'value'  => $value,
                        ];
                        $remaining--;

                        if ($remaining === 0) {
                            ksort($results);
                            $resolve($results);
                        }
                    }
                  )
                  ->catch(
                    function (Throwable $reason) use ($index, &$results, &$remaining, $resolve): void {
                        $results[$index] = [
                          'status' => 'rejected',
                          'reason' => $reason,
                        ];
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
     * Return a promise that resolves or rejects with the first settled promise.
     *
     * @param array<int, Promise|mixed> $promises
     */
    public static function race(array $promises): self
    {
        return new self(function (callable $resolve, callable $reject) use ($promises): void {
            foreach ($promises as $promise) {
                if (!$promise instanceof self) {
                    $promise = self::resolve($promise);
                }

                $promise->then($resolve, $reject);
            }
        });
    }

    /**
     * Create a resolved promise.
     */
    public static function resolve(mixed $value): self
    {
        $promise = new self();
        $promise->doResolve($value);

        return $promise;
    }

    /**
     * Create a rejected promise.
     */
    public static function reject(Throwable $reason): self
    {
        $promise = new self();
        $promise->doReject($reason);

        return $promise;
    }

    /**
     * Add a fulfillment / rejection handler.
     *
     * @param callable(mixed):mixed|null     $onFulfilled
     * @param callable(Throwable):mixed|null $onRejected
     */
    public function then(?callable $onFulfilled = null, ?callable $onRejected = null): self
    {
        $next = new self();

        $this->onFulfilled[] = function (mixed $value) use ($next, $onFulfilled): void {
            if ($onFulfilled === null) {
                $next->doResolve($value);
                return;
            }

            try {
                $result = $onFulfilled($value);
                $next->doResolve($result);
            } catch (Throwable $e) {
                $next->doReject($e);
            }
        };

        if ($onRejected !== null) {
            $this->onRejected[] = function (Throwable $reason) use ($next, $onRejected): void {
                try {
                    $result = $onRejected($reason);
                    $next->doResolve($result);
                } catch (Throwable $e) {
                    $next->doReject($e);
                }
            };
        } else {
            $this->onRejected[] = static function (Throwable $reason) use ($next): void {
                $next->doReject($reason);
            };
        }

        if ($this->state === self::STATE_FULFILLED) {
            $this->callHandlers($this->onFulfilled, true, $this->value);
        } elseif ($this->state === self::STATE_REJECTED) {
            $this->callHandlers($this->onRejected, true, $this->reason);
        }

        return $next;
    }

    /**
     * Add a rejection handler.
     *
     * @param callable(Throwable):mixed $onRejected
     */
    public function catch(callable $onRejected): self
    {
        return $this->then(null, $onRejected);
    }

    /**
     * Add a finally handler (called on both resolve and reject).
     *
     * @param callable():void $onFinally
     */
    public function finally(callable $onFinally): self
    {
        $this->onFinally[] = $onFinally;

        if ($this->state !== self::STATE_PENDING) {
            $this->callHandlers($this->onFinally, false);
        }

        return $this;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function isPending(): bool
    {
        return $this->state === self::STATE_PENDING;
    }

    public function isFulfilled(): bool
    {
        return $this->state === self::STATE_FULFILLED;
    }

    public function isRejected(): bool
    {
        return $this->state === self::STATE_REJECTED;
    }

    /**
     * Block until the promise settles and return its value or throw its reason.
     *
     * WARNING: This is a busy-wait and should be used sparingly,
     * mainly for tests or CLI tools – not in hot paths.
     */
    public function wait(): mixed
    {
        while ($this->state === self::STATE_PENDING) {
            usleep(1_000); // 1ms
        }

        if ($this->state === self::STATE_REJECTED) {
            throw $this->reason;
        }

        return $this->value;
    }

    /**
     * @param callable[]               $handlers
     * @param bool                     $withArg  true = pass $arg, false = call with no args
     * @param mixed|Throwable|null     $arg
     */
    private function callHandlers(array &$handlers, bool $withArg, mixed $arg = null): void
    {
        foreach ($handlers as $handler) {
            try {
                if ($withArg) {
                    $handler($arg);
                } else {
                    $handler();
                }
            } catch (Throwable) {
                // Swallow handler exceptions to avoid breaking the chain
            }
        }

        $handlers = [];
    }

    private function doResolve(mixed $value): void
    {
        if ($this->state !== self::STATE_PENDING) {
            return;
        }

        if ($value instanceof self) {
            $value->then(
              function (mixed $inner): void {
                  $this->doResolve($inner);
              },
              function (Throwable $reason): void {
                  $this->doReject($reason);
              }
            );
            return;
        }

        $this->state = self::STATE_FULFILLED;
        $this->value = $value;

        $this->callHandlers($this->onFulfilled, true, $value);
        $this->callHandlers($this->onFinally, false);
    }

    private function doReject(Throwable $reason): void
    {
        if ($this->state !== self::STATE_PENDING) {
            return;
        }

        $this->state  = self::STATE_REJECTED;
        $this->reason = $reason;

        $this->callHandlers($this->onRejected, true, $reason);
        $this->callHandlers($this->onFinally, false);
    }
}
