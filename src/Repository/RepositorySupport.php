<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Repository;

use InvalidArgumentException;

/** Internal typed helpers shared by repository relation infrastructure. */
final class RepositorySupport
{
    /** @return non-empty-string */
    public static function column(string $column): string
    {
        $column = trim($column);
        if ($column === '') {
            throw new InvalidArgumentException('Repository column names must not be empty.');
        }

        return $column;
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
     * @param list<mixed> $values
     * @return list<mixed>
     */
    public static function uniqueValues(array $values): array
    {
        $unique = [];
        foreach ($values as $value) {
            if ($value !== null) {
                $unique[self::key($value)] = $value;
            }
        }

        return array_values($unique);
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

    private function __construct() {}
}
