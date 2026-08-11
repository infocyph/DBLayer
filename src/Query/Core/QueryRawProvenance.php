<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Query\Core;

use Infocyph\DBLayer\Query\Expression;
use Stringable;

/**
 * Recursively derives raw-SQL provenance from a structured query graph.
 *
 * @internal
 */
final class QueryRawProvenance
{
    /**
     * @param list<string|Expression|WindowExpression> $columns
     * @param list<array<string,mixed>> $wheres
     * @param list<array<string,mixed>|object> $joins
     * @param list<array<string,mixed>> $havings
     * @param list<array{query:QueryPayload,all:bool}> $unions
     * @param list<array{name:string,query:string|QueryPayload,recursive:bool}> $ctes
     */
    public static function contains(
        array $columns,
        array $wheres,
        array $joins,
        array $havings,
        array $unions,
        array $ctes,
        ?QueryPayload $sourceQuery,
    ): bool {
        return $sourceQuery?->containsRawFragments === true
            || self::columnsContainRaw($columns)
            || self::payloadListContainsRaw($unions)
            || self::ctesContainRaw($ctes)
            || self::joinsContainRaw($joins)
            || self::componentsContainRaw($wheres)
            || self::componentsContainRaw($havings);
    }

    /**
     * @param list<string|Expression|WindowExpression> $columns
     */
    private static function columnsContainRaw(array $columns): bool
    {
        return array_any($columns, fn($column) => $column instanceof Expression
            || ($column instanceof WindowExpression && $column->function instanceof Expression));
    }

    private static function componentIsRaw(mixed $component): bool
    {
        if ($component instanceof Expression || $component instanceof QueryPayload) {
            return $component instanceof Expression || $component->containsRawFragments;
        }

        if ($component instanceof WindowExpression) {
            return $component->function instanceof Expression;
        }

        return is_array($component)
            && (($component['type'] ?? null) === 'raw' || self::componentsContainRaw($component));
    }

    /**
     * @param array<int|string,mixed> $components
     */
    private static function componentsContainRaw(array $components): bool
    {
        return array_any($components, fn($component) => self::componentIsRaw($component));
    }

    /**
     * @param list<array{name:string,query:string|QueryPayload,recursive:bool}> $ctes
     */
    private static function ctesContainRaw(array $ctes): bool
    {
        return array_any($ctes, fn($cte) => is_string($cte['query']) || $cte['query']->containsRawFragments);
    }

    /**
     * @param list<array<string,mixed>|object> $joins
     */
    private static function joinsContainRaw(array $joins): bool
    {
        foreach ($joins as $join) {
            if ($join instanceof Stringable || self::rawArrayJoin($join)) {
                return true;
            }
            if (is_array($join) && self::componentsContainRaw($join)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{query:QueryPayload,all:bool}> $payloads
     */
    private static function payloadListContainsRaw(array $payloads): bool
    {
        return array_any($payloads, fn($payload) => $payload['query']->containsRawFragments);
    }

    private static function rawArrayJoin(mixed $join): bool
    {
        return is_array($join)
            && ($join['subquery'] ?? false) === true
            && !($join['query'] ?? null) instanceof QueryPayload;
    }
}
