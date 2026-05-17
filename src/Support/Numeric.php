<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Support;

final class Numeric
{
    private function __construct() {}

    /**
     * @param array<string,mixed> $data
     */
    public static function arrayFloat(array $data, string $key, float $default = 0.0): float
    {
        return self::toFloat($data[$key] ?? null, $default);
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function arrayInt(array $data, string $key, int $default = 0): int
    {
        return self::toInt($data[$key] ?? null, $default);
    }

    /**
     * @param list<float> $sorted
     */
    public static function percentile(array $sorted, float $p): float
    {
        $count = count($sorted);

        if ($count === 0) {
            return 0.0;
        }

        if ($count === 1 || $p <= 0.0) {
            return (float) $sorted[0];
        }

        if ($p >= 100.0) {
            return (float) $sorted[$count - 1];
        }

        $index = ($p / 100.0) * ($count - 1);
        $lower = (int) floor($index);
        $upper = (int) ceil($index);

        if ($lower === $upper) {
            return (float) $sorted[$lower];
        }

        $weight = $index - $lower;

        return (1 - $weight) * $sorted[$lower] + $weight * $sorted[$upper];
    }

    public static function toFloat(mixed $value, float $default = 0.0): float
    {
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return $default;
    }

    public static function toInt(mixed $value, int $default = 0): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }
}
