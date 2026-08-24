<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Repository;

use Infocyph\DBLayer\Query\QueryBuilder;

/**
 * Resolve and compare deterministic one-of-many ordering criteria.
 */
final class RepositoryOneOfManyOrder
{
    /**
     * @return array<string,'asc'|'desc'>
     */
    public static function resolve(
        RelationDefinition $definition,
        RepositoryDefinition $related,
    ): array {
        $orders = [];

        if ($definition->oneOfManyOrders !== []) {
            foreach ($definition->oneOfManyOrders as $column => $aggregate) {
                $orders[$column] = $aggregate === 'min' ? 'asc' : 'desc';
            }
        } else {
            $column = $definition->oneOfManyColumn ?? $related->primaryKey;
            $orders[$column] = $definition->oneOfManyAggregate === 'min' ? 'asc' : 'desc';
        }

        if (!array_key_exists($related->primaryKey, $orders)) {
            $lastDirection = end($orders);
            $orders[$related->primaryKey] = $lastDirection === 'asc' ? 'asc' : 'desc';
        }

        return $orders;
    }

    /** @param array<string,'asc'|'desc'> $orders */
    public static function apply(QueryBuilder $query, array $orders): void
    {
        foreach ($orders as $column => $direction) {
            $query->orderBy($column, $direction);
        }
    }

    /**
     * Compare two projected rows in winner order.
     *
     * A negative result means $left should appear before $right.
     *
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     * @param array<string,'asc'|'desc'> $orders
     */
    public static function compare(array $left, array $right, array $orders): int
    {
        foreach ($orders as $column => $direction) {
            $comparison = ($left[$column] ?? null) <=> ($right[$column] ?? null);
            if ($comparison === 0) {
                continue;
            }

            return $direction === 'desc' ? -$comparison : $comparison;
        }

        return 0;
    }

    /** @param array<string,'asc'|'desc'> $orders @return list<string> */
    public static function columns(array $orders): array
    {
        return array_keys($orders);
    }

    private function __construct() {}
}
