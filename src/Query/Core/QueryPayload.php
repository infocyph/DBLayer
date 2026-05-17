<?php

// src/Query/Core/QueryPayload.php

declare(strict_types=1);

namespace Infocyph\DBLayer\Query\Core;

use Infocyph\DBLayer\Query\Expression;
use Infocyph\DBLayer\Support\ArrayNormalizer;

/**
 * Immutable query payload (AST-ish).
 *
 * Everything the driver/compiler needs to turn a structured query into SQL.
 */
final readonly class QueryPayload
{
    /**
     * @param list<string|Expression> $columns
     * @param list<array<string,mixed>> $wheres
     * @param list<array<string,mixed>|object> $joins JoinClause-like arrays or objects
     * @param list<string> $groups
     * @param list<array<string,mixed>> $havings
     * @param list<array{column:string,direction:string}> $orders
 * @param list<array{query:QueryPayload,all:bool}> $unions
 * @param array{function:string,column:string}|null $aggregate
 * @param list<mixed> $bindings
 * @param list<array<string,mixed>> $insertRows
 * @param array<string,mixed> $updateValues
 */
    public function __construct(
        public QueryType $type,
        public ?string $table,
        public array $columns,
        public array $wheres,
        public array $joins,
        public array $groups,
        public array $havings,
        public array $orders,
        public ?int $limit,
        public ?int $offset,
        public array $unions,
        public ?string $lock,
        public ?array $aggregate,
        public array $bindings,
        public array $insertRows = [],
        public array $updateValues = [],
    ) {}

    /**
     * Lightweight clone with overridden pieces.
     *
     * @param array{
     *   type?:QueryType,
     *   table?:?string,
     *   columns?:list<string|Expression>,
     *   wheres?:list<array<string,mixed>>,
     *   joins?:list<array<string,mixed>|object>,
     *   groups?:list<string>,
     *   havings?:list<array<string,mixed>>,
     *   orders?:list<array{column:string,direction:string}>,
     *   limit?:?int,
     *   offset?:?int,
     *   unions?:list<array{query:QueryPayload,all:bool}>,
     *   lock?:?string,
     *   aggregate?:array{function:string,column:string}|null,
     *   bindings?:list<mixed>,
     *   insertRows?:list<array<string,mixed>>,
     *   updateValues?:array<string,mixed>
     * } $overrides
     */
    public function with(array $overrides): self
    {
        return new self(
            $overrides['type'] ?? $this->type,
            $overrides['table'] ?? $this->table,
            self::normalizeColumns($overrides['columns'] ?? $this->columns),
            self::normalizeAssocRows($overrides['wheres'] ?? $this->wheres),
            self::normalizeJoins($overrides['joins'] ?? $this->joins),
            self::normalizeStringList($overrides['groups'] ?? $this->groups),
            self::normalizeAssocRows($overrides['havings'] ?? $this->havings),
            self::normalizeOrders($overrides['orders'] ?? $this->orders),
            $overrides['limit'] ?? $this->limit,
            $overrides['offset'] ?? $this->offset,
            self::normalizeUnions($overrides['unions'] ?? $this->unions),
            $overrides['lock'] ?? $this->lock,
            self::normalizeAggregate($overrides['aggregate'] ?? $this->aggregate),
            self::normalizeMixedList($overrides['bindings'] ?? $this->bindings),
            self::normalizeInsertRows($overrides['insertRows'] ?? $this->insertRows),
            self::normalizeStringKeyMap($overrides['updateValues'] ?? $this->updateValues),
        );
    }

    /**
     * @return array{function:string,column:string}|null
     */
    private static function normalizeAggregate(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $function = $value['function'] ?? null;
        $column = $value['column'] ?? null;

        if (!is_string($function) || !is_string($column)) {
            return null;
        }

        return [
            'function' => $function,
            'column' => $column,
        ];
    }

    /**
     * @param array<int|string,mixed> $values
     * @return list<array<string,mixed>>
     */
    private static function normalizeAssocRows(array $values): array
    {
        $normalized = [];

        foreach ($values as $value) {
            if (!is_array($value)) {
                continue;
            }

            $normalized[] = ArrayNormalizer::stringKeyArray($value);
        }

        return $normalized;
    }

    /**
     * @param array<int|string,mixed> $values
     * @return list<string|Expression>
     */
    private static function normalizeColumns(array $values): array
    {
        $normalized = [];

        foreach ($values as $value) {
            if (!is_string($value) && !$value instanceof Expression) {
                continue;
            }

            $normalized[] = $value;
        }

        return $normalized;
    }

    /**
     * @param array<int|string,mixed> $values
     * @return list<array<string,mixed>>
     */
    private static function normalizeInsertRows(array $values): array
    {
        $normalized = [];

        foreach ($values as $value) {
            if (!is_array($value)) {
                continue;
            }

            $normalized[] = ArrayNormalizer::stringKeyArray($value);
        }

        return $normalized;
    }

    /**
     * @param array<int|string,mixed> $values
     * @return list<array<string,mixed>|object>
     */
    private static function normalizeJoins(array $values): array
    {
        $normalized = [];

        foreach ($values as $value) {
            if (is_object($value)) {
                $normalized[] = $value;

                continue;
            }

            if (!is_array($value)) {
                continue;
            }

            $normalized[] = ArrayNormalizer::stringKeyArray($value);
        }

        return $normalized;
    }

    /**
     * @param array<int|string,mixed> $values
     * @return list<mixed>
     */
    private static function normalizeMixedList(array $values): array
    {
        $normalized = [];

        foreach ($values as $value) {
            $normalized[] = $value;
        }

        return $normalized;
    }

    /**
     * @param array<int|string,mixed> $values
     * @return list<array{column:string,direction:string}>
     */
    private static function normalizeOrders(array $values): array
    {
        $normalized = [];

        foreach ($values as $value) {
            if (!is_array($value)) {
                continue;
            }

            $column = $value['column'] ?? null;
            $direction = $value['direction'] ?? null;

            if (!is_string($column) || !is_string($direction)) {
                continue;
            }

            $normalized[] = [
                'column' => $column,
                'direction' => $direction,
            ];
        }

        return $normalized;
    }

    /**
     * @param array<int|string,mixed> $values
     * @return array<string,mixed>
     */
    private static function normalizeStringKeyMap(array $values): array
    {
        return ArrayNormalizer::stringKeyArray($values);
    }

    /**
     * @param array<int|string,mixed> $values
     * @return list<string>
     */
    private static function normalizeStringList(array $values): array
    {
        $normalized = [];

        foreach ($values as $value) {
            if (!is_string($value)) {
                continue;
            }

            $normalized[] = $value;
        }

        return $normalized;
    }

    /**
     * @param array<int|string,mixed> $values
     * @return list<array{query:QueryPayload,all:bool}>
     */
    private static function normalizeUnions(array $values): array
    {
        $normalized = [];

        foreach ($values as $value) {
            if (!is_array($value)) {
                continue;
            }

            $query = $value['query'] ?? null;
            $all = $value['all'] ?? false;

            if (!$query instanceof self || !is_bool($all)) {
                continue;
            }

            $normalized[] = [
                'query' => $query,
                'all' => $all,
            ];
        }

        return $normalized;
    }
}
