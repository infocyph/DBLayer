<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Concerns;

use InvalidArgumentException;

trait DBBatchOperations
{
    /**
     * Execute explicitly typed raw operations in order.
     *
     * @param list<array<string,mixed>> $queries
     * @return list<mixed>
     */
    public static function batch(array $queries, ?string $connection = null): array
    {
        $results = [];

        foreach ($queries as $query) {
            $operation = $query['operation'] ?? null;
            $sql = $query['sql'] ?? null;
            if (!is_string($operation) || !in_array($operation, self::batchOperations(), true) || !is_string($sql)) {
                throw new InvalidArgumentException('Each batch item requires a supported operation and SQL string.');
            }

            $rawBindings = $query['bindings'] ?? [];
            if (!is_array($rawBindings)) {
                throw new InvalidArgumentException('Batch bindings must be an array.');
            }

            $results[] = self::executeBatchOperation(
                $operation,
                $sql,
                self::normalizeBatchBindings($rawBindings),
                $connection,
            );
        }

        return $results;
    }

    /** @return list<string> */
    private static function batchOperations(): array
    {
        return [
            'select',
            'select_one',
            'select_result_sets',
            'scalar',
            'statement',
            'insert',
            'update',
            'delete',
            'unprepared',
        ];
    }

    /** @param list<mixed> $bindings */
    private static function executeBatchOperation(
        string $operation,
        string $sql,
        array $bindings,
        ?string $connection,
    ): mixed {
        return match ($operation) {
            'select' => static::select($sql, $bindings, $connection),
            'select_one' => static::selectOne($sql, $bindings, $connection),
            'select_result_sets' => static::selectResultSets($sql, $bindings, $connection),
            'scalar' => static::scalar($sql, $bindings, $connection),
            'statement' => static::statement($sql, $bindings, $connection),
            'insert' => static::insert($sql, $bindings, $connection),
            'update' => static::update($sql, $bindings, $connection),
            'delete' => static::delete($sql, $bindings, $connection),
            'unprepared' => static::unprepared($sql, $connection),
            default => throw new InvalidArgumentException("Unsupported batch operation [{$operation}]."),
        };
    }
}
