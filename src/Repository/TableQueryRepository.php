<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Repository;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Exceptions\UnwritableAttributeException;
use Infocyph\DBLayer\Query\QueryBuilder;
use Infocyph\DBLayer\Query\ResultProcessor;

/**
 * Concrete Repository used by TableRepository metadata.
 *
 * Keeps table-definition policy in the repository layer without introducing
 * entity state or Active Record behavior.
 */
final class TableQueryRepository extends RepositoryPagination
{
    /** @var array<string,callable(QueryBuilder):void> */
    private array $namedGlobalScopes = [];

    /** @var array<string,true> */
    private array $disabledGlobalScopes = [];

    /**
     * @param array<string,mixed> $defaults
     * @param list<string> $creatable
     * @param list<string> $updatable
     */
    public function __construct(
        Connection $connection,
        private readonly string $tableName,
        private readonly string $primaryKeyName,
        ResultProcessor $results,
        private readonly array $defaults = [],
        private readonly array $creatable = [],
        private readonly array $updatable = [],
        private readonly bool $timestamps = false,
        private readonly string $createdAt = 'created_at',
        private readonly string $updatedAt = 'updated_at',
    ) {
        parent::__construct(
            $connection,
            $connection->getExecutorInstance(),
            $results,
        );
    }

    /**
     * Register or replace one named global scope.
     *
     * Internal repository-definition API; public query ergonomics live on
     * RepositoryQuery to avoid TableRepository static-dispatch collisions.
     *
     * @param callable(QueryBuilder):void $scope
     */
    public function registerNamedGlobalScope(string $name, callable $scope): static
    {
        $this->namedGlobalScopes[$name] = $scope;
        unset($this->disabledGlobalScopes[$name]);

        return $this;
    }

    /**
     * Disable one named global scope for this repository instance.
     */
    public function disableNamedGlobalScope(string $name): static
    {
        if (array_key_exists($name, $this->namedGlobalScopes)) {
            $this->disabledGlobalScopes[$name] = true;
        }

        return $this;
    }

    /**
     * Disable selected named scopes, or every named scope when names are null.
     *
     * @param list<string>|null $names
     */
    public function disableNamedGlobalScopes(?array $names = null): static
    {
        $names ??= array_keys($this->namedGlobalScopes);

        foreach ($names as $name) {
            $this->disableNamedGlobalScope($name);
        }

        return $this;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    #[\Override]
    public function bulkInsert(array $rows): bool
    {
        if ($rows === []) {
            return true;
        }

        return parent::bulkInsert(array_map(
            fn(array $row): array => $this->prepareCreateAttributes($row),
            $rows,
        ));
    }

    /**
     * @param array<string,mixed> $attributes
     * @return array<string,mixed>
     */
    #[\Override]
    public function create(array $attributes): array
    {
        return parent::create($this->prepareCreateAttributes($attributes));
    }

    /**
     * @param array<string,mixed> $values
     */
    #[\Override]
    public function updateById(mixed $id, array $values): int
    {
        if ($values === []) {
            return 0;
        }

        return parent::updateById($id, $this->prepareUpdateAttributes($values));
    }

    /**
     * @param array<string,mixed> $values
     */
    #[\Override]
    public function updateByIdWithVersion(
        mixed $id,
        array $values,
        int|float|string $expectedVersion,
        ?string $versionColumn = null,
    ): bool {
        if ($values === []) {
            return false;
        }

        return parent::updateByIdWithVersion(
            $id,
            $this->prepareUpdateAttributes($values),
            $expectedVersion,
            $versionColumn,
        );
    }

    /**
     * @param array<string,mixed> $attributes
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    #[\Override]
    public function updateOrCreate(array $attributes, array $values = []): array
    {
        $existing = $this->first(static function (QueryBuilder $query) use ($attributes): void {
            foreach ($attributes as $column => $value) {
                $query->where((string) $column, '=', $value);
            }
        });

        if ($existing === null) {
            return $this->create(array_merge($attributes, $values));
        }

        if ($values === []) {
            return $existing;
        }

        if (array_key_exists($this->primaryKeyName, $existing)) {
            $this->updateById($existing[$this->primaryKeyName], $values);

            return $this->find($existing[$this->primaryKeyName]) ?? $existing;
        }

        return parent::updateOrCreate(
            $attributes,
            $this->prepareUpdateAttributes($values),
        );
    }

    /**
     * @param array<string,mixed>|array<int,array<string,mixed>> $values
     * @param list<string> $uniqueBy
     * @param list<string>|null $update
     */
    #[\Override]
    public function upsert(array $values, array $uniqueBy, ?array $update = null): bool
    {
        if ($values === []) {
            return true;
        }

        $many = is_array(reset($values));
        $rows = $many ? $values : [$values];
        $prepared = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $prepared[] = $this->prepareCreateAttributes($row);
        }

        if ($prepared === []) {
            return true;
        }

        $updateColumns = $this->prepareUpsertUpdateColumns(
            $update,
            array_keys($prepared[0]),
            $uniqueBy,
        );

        return parent::upsert(
            $many ? $prepared : $prepared[0],
            $uniqueBy,
            $updateColumns,
        );
    }

    #[\Override]
    protected function applyRepositoryConstraints(QueryBuilder $query): QueryBuilder
    {
        $query = parent::applyRepositoryConstraints($query);

        foreach ($this->namedGlobalScopes as $name => $scope) {
            if (isset($this->disabledGlobalScopes[$name])) {
                continue;
            }

            $scope($query);
        }

        return $query;
    }

    #[\Override]
    protected function primaryKey(): string
    {
        return $this->primaryKeyName;
    }

    #[\Override]
    protected function table(): string
    {
        return $this->tableName;
    }

    /**
     * @param array<string,mixed> $attributes
     * @return array<string,mixed>
     */
    private function prepareCreateAttributes(array $attributes): array
    {
        $this->assertAllowedAttributes($attributes, $this->creatable, true);

        $payload = array_replace($this->defaults, $attributes);

        if (!$this->timestamps) {
            return $payload;
        }

        $timestamp = $this->freshTimestamp();
        $payload[$this->createdAt] ??= $timestamp;
        $payload[$this->updatedAt] ??= $timestamp;

        return $payload;
    }

    /**
     * @param array<string,mixed> $attributes
     * @return array<string,mixed>
     */
    private function prepareUpdateAttributes(array $attributes): array
    {
        $this->assertAllowedAttributes($attributes, $this->updatable, false);

        if ($this->timestamps) {
            $attributes[$this->updatedAt] = $this->freshTimestamp();
        }

        return $attributes;
    }

    /**
     * @param array<string,mixed> $attributes
     * @param list<string> $allowed
     */
    private function assertAllowedAttributes(array $attributes, array $allowed, bool $creating): void
    {
        if ($allowed === []) {
            return;
        }

        foreach (array_keys($attributes) as $attribute) {
            if (in_array($attribute, $allowed, true)) {
                continue;
            }

            throw $creating
                ? UnwritableAttributeException::forCreate($attribute)
                : UnwritableAttributeException::forUpdate($attribute);
        }
    }

    private function freshTimestamp(): string
    {
        return new \DateTimeImmutable('now')->format(
            $this->connection->getDriver()->dateFormat(),
        );
    }

    /**
     * @param list<string>|null $requested
     * @param list<string> $columns
     * @param list<string> $uniqueBy
     * @return list<string>|null
     */
    private function prepareUpsertUpdateColumns(
        ?array $requested,
        array $columns,
        array $uniqueBy,
    ): ?array {
        if ($requested !== null) {
            $this->assertAllowedAttributes(
                array_fill_keys($requested, true),
                $this->updatable,
                false,
            );

            if (!$this->timestamps || in_array($this->updatedAt, $requested, true)) {
                return $requested;
            }

            return [...$requested, $this->updatedAt];
        }

        if (!$this->timestamps) {
            return null;
        }

        return array_values(array_filter(
            $columns,
            fn(string $column): bool => !in_array($column, $uniqueBy, true)
                && $column !== $this->createdAt,
        ));
    }
}
