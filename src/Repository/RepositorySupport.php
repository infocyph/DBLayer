<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Repository;

use InvalidArgumentException;

/** Internal typed helpers shared by repository relation infrastructure. */
final class RepositorySupport
{
    private function __construct() {}

    /** @return non-empty-string */
    public static function column(string $column): string
    {
        $column = trim($column);
        if ($column === '') {
            throw new InvalidArgumentException('Repository column names must not be empty.');
        }

        return $column;
    }

    public static function compare(mixed $left, mixed $right): int
    {
        if (is_numeric($left) && is_numeric($right)) {
            return (float) $left <=> (float) $right;
        }

        if (is_scalar($left) && is_scalar($right)) {
            return (string) $left <=> (string) $right;
        }

        return 0;
    }

    public static function key(mixed $value): string
    {
        return match (true) {
            is_int($value), is_string($value) => 'scalar:' . $value,
            is_float($value) => 'float:' . serialize($value),
            is_bool($value) => 'bool:' . ($value ? '1' : '0'),
            $value === null => 'null:',
            default => throw new InvalidArgumentException('Relation keys must be scalar or null.'),
        };
    }

    /**
     * Retain a mapping key for relation matching and identify it for later removal.
     *
     * @param list<string> $requested
     * @return array{0:list<string>,1:list<string>}
     */
    public static function projection(array $requested, string $key): array
    {
        if (in_array('*', $requested, true) || in_array($key, $requested, true)) {
            return [$requested, []];
        }

        return [[...$requested, $key], [$key]];
    }

    /**
     * @param string|array<int|string,string|callable(\Infocyph\DBLayer\Query\QueryBuilder):void> $relations
     * @return list<array{0:string,1:(callable(\Infocyph\DBLayer\Query\QueryBuilder):void)|null}>
     */
    public static function relationConstraints(string|array $relations): array
    {
        $entries = is_string($relations) ? [$relations] : $relations;
        $normalized = [];
        foreach ($entries as $name => $constraint) {
            if (is_int($name)) {
                if (!is_string($constraint)) {
                    throw new InvalidArgumentException('Numeric relation entries must contain relation names.');
                }
                $normalized[] = [$constraint, null];

                continue;
            }
            if (!is_callable($constraint)) {
                throw new InvalidArgumentException(sprintf('Relation constraint for [%s] must be callable.', $name));
            }
            $normalized[] = [$name, $constraint];
        }

        return $normalized;
    }

    /** @return array<string,mixed>|null */
    public static function row(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        foreach (array_keys($value) as $key) {
            if (!is_string($key)) {
                return null;
            }
        }

        /** @var array<string,mixed> $value */
        return $value;
    }

    /**
     * @param iterable<mixed> $values
     * @return list<array<string,mixed>>
     */
    public static function rows(iterable $values): array
    {
        $rows = [];
        foreach ($values as $value) {
            $row = self::row($value);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param list<string> $columns
     * @return list<non-empty-string>
     */
    public static function uniqueColumns(array $columns): array
    {
        $unique = [];
        foreach ($columns as $column) {
            $column = self::column($column);
            $unique[$column] = true;
        }

        return array_keys($unique);
    }

    /**
     * @param list<mixed> $values
     * @return list<int|float|string|bool>
     */
    public static function uniqueValues(array $values): array
    {
        $unique = [];
        foreach ($values as $value) {
            $value = self::value($value);
            if ($value !== null) {
                $unique[self::key($value)] = $value;
            }
        }

        return array_values($unique);
    }

    public static function value(mixed $value): int|float|string|bool|null
    {
        if ($value === null || is_int($value) || is_float($value) || is_string($value) || is_bool($value)) {
            return $value;
        }

        throw new InvalidArgumentException('Relation keys must be scalar or null.');
    }
}
