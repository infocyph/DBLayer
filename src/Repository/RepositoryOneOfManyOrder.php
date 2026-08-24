<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Repository;

/**
 * Resolve and compare deterministic one-of-many ordering criteria.
 */
final class RepositoryOneOfManyOrder
{
    /** @return array<string,'asc'|'desc'> */
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

    /**
     * Compare two persisted rows in winner order.
     *
     * A negative result means $left should appear before $right. Numeric strings
     * are compared as exact decimals instead of being coerced through float, so
     * large DECIMAL identifiers/amounts retain ordering precision.
     *
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     * @param array<string,'asc'|'desc'> $orders
     */
    public static function compare(array $left, array $right, array $orders): int
    {
        foreach ($orders as $column => $direction) {
            $comparison = self::compareValues(
                $left[$column] ?? null,
                $right[$column] ?? null,
            );
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

    private static function compareValues(mixed $left, mixed $right): int
    {
        if ($left === $right) {
            return 0;
        }
        if ($left === null) {
            return -1;
        }
        if ($right === null) {
            return 1;
        }

        if (self::isNumericValue($left) && self::isNumericValue($right)) {
            return self::compareNumericValues($left, $right);
        }

        if (is_bool($left) && is_bool($right)) {
            return ($left ? 1 : 0) <=> ($right ? 1 : 0);
        }

        if (is_scalar($left) && is_scalar($right)) {
            return strcmp((string) $left, (string) $right) <=> 0;
        }

        return strcmp(serialize($left), serialize($right)) <=> 0;
    }

    private static function isNumericValue(mixed $value): bool
    {
        return is_int($value)
            || is_float($value)
            || (is_string($value) && is_numeric($value));
    }

    private static function compareNumericValues(mixed $left, mixed $right): int
    {
        $leftParts = self::numericParts(self::numericString($left));
        $rightParts = self::numericParts(self::numericString($right));

        if ($leftParts === null || $rightParts === null) {
            return ((float) $left) <=> ((float) $right);
        }

        [$leftSign, $leftDigits, $leftPoint] = $leftParts;
        [$rightSign, $rightDigits, $rightPoint] = $rightParts;

        if ($leftSign !== $rightSign) {
            return $leftSign <=> $rightSign;
        }
        if ($leftSign === 0) {
            return 0;
        }

        $magnitude = $leftPoint <=> $rightPoint;
        if ($magnitude === 0) {
            $length = max(strlen($leftDigits), strlen($rightDigits));
            $magnitude = strcmp(
                str_pad($leftDigits, $length, '0'),
                str_pad($rightDigits, $length, '0'),
            ) <=> 0;
        }

        return $leftSign < 0 ? -$magnitude : $magnitude;
    }

    private static function numericString(mixed $value): string
    {
        if (is_float($value)) {
            return sprintf('%.17g', $value);
        }

        return (string) $value;
    }

    /** @return array{0:-1|0|1,1:string,2:int}|null */
    private static function numericParts(string $value): ?array
    {
        if (preg_match(
            '/^([+-]?)(\d+)(?:\.(\d*))?(?:[eE]([+-]?\d+))?$/D',
            trim($value),
            $matches,
        ) !== 1) {
            return null;
        }

        $integer = $matches[2];
        $fraction = $matches[3] ?? '';
        $exponent = isset($matches[4]) ? (int) $matches[4] : 0;
        $combined = $integer . $fraction;
        $leadingZeros = strspn($combined, '0');
        $digits = substr($combined, $leadingZeros);

        if ($digits === '') {
            return [0, '0', 0];
        }

        $sign = ($matches[1] ?? '') === '-' ? -1 : 1;
        $point = strlen($integer) - $leadingZeros + $exponent;

        return [$sign, $digits, $point];
    }

    private function __construct() {}
}
