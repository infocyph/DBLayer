<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Repository\Concerns;

use Infocyph\DBLayer\Repository\TableQueryRepository;
use InvalidArgumentException;

/**
 * Explicit lifecycle hooks for set-based repository operations.
 *
 * These are operation events, not per-row model events. A bulk SQL statement
 * emits one before/after operation pair regardless of affected row count.
 */
trait RepositoryOperationLifecycle
{
    private const array OPERATION_EVENTS = [
        'beforeBulkInsert',
        'afterBulkInsert',
        'beforeUpsert',
        'afterUpsert',
        'beforeRestore',
        'afterRestore',
        'beforeForceDelete',
        'afterForceDelete',
        'beforeBulkUpdate',
        'afterBulkUpdate',
        'beforeBulkDelete',
        'afterBulkDelete',
    ];

    /** @var list<array{operation:?string,callback:callable(string,array<string,mixed>,TableQueryRepository):void}> */
    private array $afterCommitHooks = [];

    /** @var array<string,list<callable(array<string,mixed>,TableQueryRepository):void>> */
    private array $operationHooks = [];

    /** @param callable(array<string,mixed>,TableQueryRepository):void $callback */
    public function afterBulkDelete(callable $callback): static
    {
        return $this->onOperation('afterBulkDelete', $callback);
    }

    /** @param callable(array<string,mixed>,TableQueryRepository):void $callback */
    public function afterBulkInsert(callable $callback): static
    {
        return $this->onOperation('afterBulkInsert', $callback);
    }

    /** @param callable(array<string,mixed>,TableQueryRepository):void $callback */
    public function afterBulkUpdate(callable $callback): static
    {
        return $this->onOperation('afterBulkUpdate', $callback);
    }

    /**
     * Register a callback after a successful repository write reaches the
     * surrounding top-level transaction commit. Outside a transaction it runs
     * immediately after the successful operation.
     *
     * @param callable(string,array<string,mixed>,TableQueryRepository):void $callback
     */
    public function afterCommit(callable $callback, ?string $operation = null): static
    {
        $operation = $operation === null ? null : $this->normalizeOperationName($operation);
        $this->afterCommitHooks[] = [
            'operation' => $operation,
            'callback' => $callback,
        ];

        return $this;
    }

    /** @param callable(array<string,mixed>,TableQueryRepository):void $callback */
    public function afterForceDelete(callable $callback): static
    {
        return $this->onOperation('afterForceDelete', $callback);
    }

    /** @param callable(array<string,mixed>,TableQueryRepository):void $callback */
    public function afterRestore(callable $callback): static
    {
        return $this->onOperation('afterRestore', $callback);
    }

    /** @param callable(array<string,mixed>,TableQueryRepository):void $callback */
    public function afterUpsert(callable $callback): static
    {
        return $this->onOperation('afterUpsert', $callback);
    }

    /** @param callable(array<string,mixed>,TableQueryRepository):void $callback */
    public function beforeBulkDelete(callable $callback): static
    {
        return $this->onOperation('beforeBulkDelete', $callback);
    }

    /** @param callable(array<string,mixed>,TableQueryRepository):void $callback */
    public function beforeBulkInsert(callable $callback): static
    {
        return $this->onOperation('beforeBulkInsert', $callback);
    }

    /** @param callable(array<string,mixed>,TableQueryRepository):void $callback */
    public function beforeBulkUpdate(callable $callback): static
    {
        return $this->onOperation('beforeBulkUpdate', $callback);
    }

    /** @param callable(array<string,mixed>,TableQueryRepository):void $callback */
    public function beforeForceDelete(callable $callback): static
    {
        return $this->onOperation('beforeForceDelete', $callback);
    }

    /** @param callable(array<string,mixed>,TableQueryRepository):void $callback */
    public function beforeRestore(callable $callback): static
    {
        return $this->onOperation('beforeRestore', $callback);
    }

    /** @param callable(array<string,mixed>,TableQueryRepository):void $callback */
    public function beforeUpsert(callable $callback): static
    {
        return $this->onOperation('beforeUpsert', $callback);
    }

    /**
     * Register one explicit set-based operation hook.
     *
     * @param callable(array<string,mixed>,TableQueryRepository):void $callback
     */
    public function onOperation(string $event, callable $callback): static
    {
        if (!in_array($event, self::OPERATION_EVENTS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported repository operation event [%s].',
                $event,
            ));
        }

        $this->operationHooks[$event][] = $callback;

        return $this;
    }

    /** @param array<string,mixed> $context */
    private function dispatchOperationHook(string $event, array $context): void
    {
        foreach ($this->operationHooks[$event] ?? [] as $callback) {
            $callback($context, $this);
        }
    }

    private function normalizeOperationName(string $operation): string
    {
        $operation = strtolower(trim($operation));
        $operation = str_replace(['-', ' '], '_', $operation);

        if ($operation === '') {
            throw new InvalidArgumentException('Repository operation name must not be empty.');
        }

        return $operation;
    }

    /** @param array<string,mixed> $context */
    private function scheduleAfterCommit(string $operation, array $context): void
    {
        if ($this->afterCommitHooks === []) {
            return;
        }

        $operation = $this->normalizeOperationName($operation);
        $callbacks = array_values(array_filter(
            $this->afterCommitHooks,
            static fn(array $hook): bool => $hook['operation'] === null || $hook['operation'] === $operation,
        ));

        if ($callbacks === []) {
            return;
        }

        $this->connection->afterCommit(function () use ($callbacks, $operation, $context): void {
            foreach ($callbacks as $hook) {
                $hook['callback']($operation, $context, $this);
            }
        });
    }
}
