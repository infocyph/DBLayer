<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Query\Concerns;

use Infocyph\DBLayer\Query\Expression;
use Infocyph\DBLayer\Query\QueryBuilder;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionParameter;

trait RepositoryInternals
{
    /**
     * @param array<int|string,mixed> $attributes
     * @return array<string,mixed>
     */
    private static function normalizeAttributeArray(array $attributes): array
    {
        $normalized = [];

        foreach ($attributes as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /**
     * Cast a single scalar through configured cast map when column is known.
     */
    private function applyCastValueForColumn(string $column, mixed $value): mixed
    {
        if (!array_key_exists($column, $this->casts)) {
            return $value;
        }

        return $this->castValue($value, $this->casts[$column], false);
    }

    /**
     * Apply configured read casts to one row.
     *
     * @param array<string,mixed>|null $row
     * @return array<string,mixed>|null
     */
    private function applyReadCastsToRow(?array $row): ?array
    {
        if ($row === null || $this->casts === []) {
            return $row;
        }

        foreach ($this->casts as $column => $cast) {
            if (!array_key_exists($column, $row)) {
                continue;
            }

            $row[$column] = $this->castValue($row[$column], $cast, false);
        }

        return $row;
    }

    /**
     * Apply configured read casts to many rows.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function applyReadCastsToRows(array $rows): array
    {
        if ($this->casts === [] || $rows === []) {
            return $rows;
        }

        $casted = [];

        foreach ($rows as $row) {
            $casted[] = $this->applyReadCastsToRow($row) ?? $row;
        }

        return $casted;
    }

    /**
     * @param list<Expression|string> $columns
     */
    private function applySelectedColumns(QueryBuilder $query, array $columns): QueryBuilder
    {
        if ($columns === []) {
            return $query;
        }

        return $query->select(...$columns);
    }

    /**
     * Apply active tenant value to one row payload when column is absent.
     *
     * @param array<string,mixed> $attributes
     * @return array<string,mixed>
     */
    private function applyTenantAttributes(array $attributes): array
    {
        if ($this->tenantId === null) {
            return $attributes;
        }

        if (!array_key_exists($this->tenantColumn, $attributes)) {
            $attributes[$this->tenantColumn] = $this->tenantId;
        }

        return $attributes;
    }

    /**
     * Apply active tenant to single-row or multi-row write payloads.
     *
     * @param array<string,mixed>|array<int,array<string,mixed>> $values
     * @return array<string,mixed>|array<int,array<string,mixed>>
     */
    private function applyTenantValues(array $values): array
    {
        return $this->mapValueRows(
            $values,
            fn(array $row): array => $this->applyTenantAttributes($row),
        );
    }

    /**
     * Apply write casts to one payload.
     *
     * @param array<string,mixed> $attributes
     * @return array<string,mixed>
     */
    private function applyWriteCastsToAttributes(array $attributes): array
    {
        if ($this->casts === []) {
            return $attributes;
        }

        foreach ($this->casts as $column => $cast) {
            if (!array_key_exists($column, $attributes)) {
                continue;
            }

            $attributes[$column] = $this->castValue($attributes[$column], $cast, true);
        }

        return $attributes;
    }

    /**
     * Apply write casts to one or many payload rows.
     *
     * @param array<string,mixed>|array<int,array<string,mixed>> $values
     * @return array<string,mixed>|array<int,array<string,mixed>>
     */
    private function applyWriteCastsToValues(array $values): array
    {
        return $this->mapValueRows(
            $values,
            fn(array $row): array => $this->applyWriteCastsToAttributes($row),
        );
    }

    /**
     * Resolve callable parameter count for lifecycle hook dispatching.
     */
    private function callableParameterCount(callable $callable): int
    {
        if (\is_array($callable)) {
            $reflection = new \ReflectionMethod($callable[0], $callable[1]);

            return $reflection->getNumberOfParameters();
        }

        if (\is_object($callable) && !$callable instanceof \Closure) {
            $reflection = new \ReflectionMethod($callable, '__invoke');

            return $reflection->getNumberOfParameters();
        }

        $reflection = new \ReflectionFunction(\Closure::fromCallable($callable));

        return $reflection->getNumberOfParameters();
    }

    private function castToFloat(mixed $value): float
    {
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return 0.0;
    }

    private function castToInt(mixed $value): int
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

        return 0;
    }

    private function castToString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        return '';
    }

    /**
     * Apply cast rules for one value.
     *
     * @param string|callable(mixed):mixed $cast
     */
    private function castValue(mixed $value, string|callable $cast, bool $forWrite): mixed
    {
        if (\is_callable($cast)) {
            return $cast($value);
        }

        $type = strtolower($cast);

        return match ($type) {
            'int', 'integer' => $value === null ? null : $this->castToInt($value),
            'float', 'double', 'real' => $value === null ? null : $this->castToFloat($value),
            'bool', 'boolean' => $value === null ? null : (bool) $value,
            'string' => $value === null ? null : $this->castToString($value),
            'json', 'array' => $forWrite
                ? (is_array($value) || is_object($value) ? json_encode($value, JSON_THROW_ON_ERROR) : $value)
                : (is_string($value) ? (json_decode($value, true) ?? $value) : $value),
            'datetime' => $forWrite
                ? $this->normalizeDateTimeForWrite($value)
                : $value,
            default => $value,
        };
    }

    /**
     * Find the first row that matches all given attributes.
     *
     * @param array<string,mixed> $attributes
     * @return array<string,mixed>|null
     */
    private function firstByAttributes(array $attributes): ?array
    {
        $query = $this->applyAttributes($this->query(), $attributes);

        return $this->applyReadCastsToRow($query->first());
    }

    /**
     * Generate a DB date-time string for soft deletes.
     */
    private function freshTimestamp(): string
    {
        return new \DateTimeImmutable('now')->format($this->grammar->getDateFormat());
    }

    /**
     * @param ReflectionClass<object> $reflection
     * @param array<string,mixed> $row
     */
    private function hydratePublicProperties(ReflectionClass $reflection, object $instance, array $row): void
    {
        foreach ($row as $key => $value) {
            if (!$reflection->hasProperty($key)) {
                continue;
            }

            $property = $reflection->getProperty($key);
            if (!$property->isPublic() || $property->isReadOnly()) {
                continue;
            }

            $property->setValue($instance, $value);
        }
    }

    /**
     * @param ReflectionClass<object> $reflection
     * @param class-string $className
     * @param array<string,mixed> $row
     */
    private function instantiateDto(ReflectionClass $reflection, string $className, array $row): object
    {
        $constructor = $reflection->getConstructor();
        if ($constructor === null || $constructor->getNumberOfParameters() === 0) {
            return $reflection->newInstance();
        }

        $arguments = [];

        foreach ($constructor->getParameters() as $parameter) {
            $arguments[] = $this->resolveDtoArgument($className, $parameter, $row);
        }

        return $reflection->newInstanceArgs($arguments);
    }

    /**
     * Map one row into a DTO class by constructor/property names.
     *
     * @param class-string $className
     * @param array<string,mixed> $row
     */
    private function mapRowIntoClass(string $className, array $row): object
    {
        $reflection = $this->resolveDtoReflection($className);
        $instance = $this->instantiateDto($reflection, $className, $row);

        $this->hydratePublicProperties($reflection, $instance, $row);

        return $instance;
    }

    /**
     * @param array<string,mixed>|array<int,array<string,mixed>> $values
     * @param callable(array<string,mixed>):array<string,mixed> $transform
     * @return array<string,mixed>|array<int,array<string,mixed>>
     */
    private function mapValueRows(array $values, callable $transform): array
    {
        if ($values === []) {
            return $values;
        }

        $first = reset($values);

        if (!is_array($first)) {
            return $transform(self::normalizeAttributeArray($values));
        }

        $mapped = [];

        foreach ($values as $value) {
            if (!is_array($value)) {
                continue;
            }

            $mapped[] = $transform(self::normalizeAttributeArray($value));
        }

        return $mapped;
    }

    /**
     * @return non-empty-string
     */
    private function normalizeColumnName(string $column, string $fallback = ''): string
    {
        $normalized = trim($column);

        if ($normalized === '') {
            return $fallback !== '' ? $fallback : 'id';
        }

        return $normalized;
    }

    /**
     * Convert DateTime values to grammar-aligned SQL date strings.
     */
    private function normalizeDateTimeForWrite(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format($this->grammar->getDateFormat());
        }

        return $value;
    }

    /**
     * Normalize and validate SQL direction.
     *
     * @return non-empty-string
     */
    private function normalizeDirection(string $direction): string
    {
        $normalized = strtolower($direction);

        if (!in_array($normalized, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid order direction [%s]. Expected "asc" or "desc".',
                $direction,
            ));
        }

        return $normalized;
    }

    /**
     * Base query without soft-delete visibility constraints.
     */
    private function queryWithoutSoftDeletes(): QueryBuilder
    {
        $withTrashed = $this->withTrashed;
        $onlyTrashed = $this->onlyTrashed;

        $this->withTrashed = true;
        $this->onlyTrashed = false;

        try {
            return $this->query();
        } finally {
            $this->withTrashed = $withTrashed;
            $this->onlyTrashed = $onlyTrashed;
        }
    }

    /**
     * Reload a row after insert when possible.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|null
     */
    private function reloadCreatedRow(array $payload): ?array
    {
        $primaryKey = $this->primaryKey();
        if (array_key_exists($primaryKey, $payload)) {
            $found = $this->find($payload[$primaryKey]);
            if ($found !== null) {
                return $found;
            }
        }

        $lastInsertId = $this->connection->lastInsertId();

        if ($lastInsertId !== '') {
            $found = $this->find($lastInsertId);
            if ($found !== null) {
                return $found;
            }
        }

        return $this->firstByAttributes($payload);
    }

    /**
     * @param class-string $className
     * @param array<string,mixed> $row
     */
    private function resolveDtoArgument(string $className, ReflectionParameter $parameter, array $row): mixed
    {
        $name = $parameter->getName();

        if (array_key_exists($name, $row)) {
            return $row[$name];
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        if ($parameter->allowsNull()) {
            return null;
        }

        throw new InvalidArgumentException(sprintf(
            'Cannot map row into DTO [%s]: missing required field [%s].',
            $className,
            $name,
        ));
    }

    /**
     * @param class-string $className
     * @return ReflectionClass<object>
     */
    private function resolveDtoReflection(string $className): ReflectionClass
    {
        if (!class_exists($className)) {
            throw new InvalidArgumentException(
                sprintf('DTO class [%s] does not exist.', $className),
            );
        }

        $reflection = new ReflectionClass($className);

        if (!$reflection->isInstantiable()) {
            throw new InvalidArgumentException(
                sprintf('DTO class [%s] is not instantiable.', $className),
            );
        }

        return $reflection;
    }

    /**
     * Execute payload hooks that can return a transformed payload.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function runPayloadHooks(string $event, array $payload): array
    {
        foreach ($this->hooks[$event] ?? [] as $hook) {
            $params = $this->callableParameterCount($hook);

            if ($params <= 0) {
                $result = $hook();
            } elseif ($params === 1) {
                $result = $hook($payload);
            } else {
                $result = $hook($payload, $this);
            }

            if (is_array($result)) {
                $payload = self::normalizeAttributeArray($result);
            }
        }

        return $payload;
    }

    /**
     * Execute fire-and-forget hooks with context payload.
     *
     * @param array<string,mixed> $context
     */
    private function runVoidHooks(string $event, array $context): void
    {
        foreach ($this->hooks[$event] ?? [] as $hook) {
            $params = $this->callableParameterCount($hook);

            if ($params <= 0) {
                $hook();
            } elseif ($params === 1) {
                $hook($context);
            } else {
                $hook($context, $this);
            }
        }
    }

    /**
     * @param array<int|string,mixed> $values
     * @return list<mixed>
     */
    private function toList(array $values): array
    {
        $list = [];

        foreach ($values as $value) {
            $list[] = $value;
        }

        return $list;
    }
}
