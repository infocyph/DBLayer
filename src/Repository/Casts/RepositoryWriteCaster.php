<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Repository\Casts;

use BackedEnum;
use DateTimeInterface;
use Infocyph\DBLayer\Connection\Connection;
use InvalidArgumentException;

/**
 * Apply the repository cast contract to set-based mutation payloads that are
 * executed directly by QueryBuilder rather than the base Repository pipeline.
 */
final class RepositoryWriteCaster
{
    private function __construct() {}

    /**
     * @param array<string,mixed> $attributes
     * @param array<string,string|callable(mixed):mixed|AttributeCast> $casts
     * @return array<string,mixed>
     */
    public static function cast(array $attributes, array $casts, Connection $connection): array
    {
        foreach ($casts as $column => $cast) {
            if (!array_key_exists($column, $attributes)) {
                continue;
            }

            $attributes[$column] = self::value(
                $attributes[$column],
                $cast,
                $attributes,
                $connection,
            );
        }

        return $attributes;
    }

    private static function named(mixed $value, string $cast, Connection $connection): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($cast) {
            'int', 'integer' => self::toInt($value),
            'float', 'double', 'real' => self::toFloat($value),
            'bool', 'boolean' => self::toBool($value),
            'string' => self::toString($value),
            'json', 'array' => is_array($value) || is_object($value)
                ? json_encode($value, JSON_THROW_ON_ERROR)
                : $value,
            'date', 'immutable_date', 'date_immutable' => $value instanceof DateTimeInterface
                ? $value->format('Y-m-d')
                : $value,
            'datetime', 'immutable_datetime', 'datetime_immutable' => $value instanceof DateTimeInterface
                ? $value->format($connection->getDriver()->dateFormat())
                : $value,
            default => $value,
        };
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['', '0', 'f', 'false', 'no', 'off'], true)) {
                return false;
            }
            if (in_array($normalized, ['1', 't', 'true', 'yes', 'on'], true)) {
                return true;
            }
        }

        return (bool) $value;
    }

    private static function toFloat(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function toString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param string|callable(mixed):mixed|AttributeCast $cast
     * @param array<string,mixed> $context
     */
    private static function value(
        mixed $value,
        string|callable|AttributeCast $cast,
        array $context,
        Connection $connection,
    ): mixed {
        if ($cast instanceof AttributeCast) {
            return $cast->set($value, $context);
        }

        if (is_string($cast) && enum_exists($cast) && is_subclass_of($cast, BackedEnum::class)) {
            if ($value === null) {
                return null;
            }
            if ($value instanceof $cast) {
                return $value->value;
            }
            if (!is_int($value) && !is_string($value)) {
                throw new InvalidArgumentException(sprintf(
                    'Backed enum [%s] expects an int or string value, %s given.',
                    $cast,
                    get_debug_type($value),
                ));
            }

            /** @var class-string<BackedEnum> $cast */
            return $cast::from($value)->value;
        }

        if (is_callable($cast)) {
            return $cast($value);
        }

        return self::named($value, strtolower($cast), $connection);
    }
}
