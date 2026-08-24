<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Repository;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Exceptions\UnwritableAttributeException;
use Infocyph\DBLayer\Query\QueryBuilder;
use Infocyph\DBLayer\Query\Repository;
use Infocyph\DBLayer\Query\ResultProcessor;
use Infocyph\DBLayer\Repository\Casts\AttributeCast;
use Infocyph\DBLayer\Repository\Casts\RepositoryWriteCaster;
use Infocyph\DBLayer\Repository\Concerns\RepositoryOperationLifecycle;
use InvalidArgumentException;

/**
 * Concrete Repository used by TableRepository metadata.
 *
 * Keeps table-definition policy in the repository layer without introducing
 * entity state or Active Record behavior.
 */
final class TableQueryRepository extends Repository
{
    use RepositoryOperationLifecycle;

    /** @var array<string,true> */
    private array $disabledGlobalScopes = [];

    public function __construct(
        Connection $connection,
        private readonly RepositoryDefinition $definition,
        ResultProcessor $results,
    ) {
        parent::__construct(
            $connection,
            $connection->getExecutorInstance(),
            $results,
        );
    }

    /** @param array<int,array<string,mixed>> $rows */
    #[\Override]
    public function bulkInsert(array $rows): bool
    {
        if ($rows === []) {
            return true;
        }

        $prepared = array_map(
            $this->prepareCreateAttributes(...),
            $rows,
        );
        $context = ['rows' => $prepared, 'count' => count($prepared)];
        $this->dispatchOperationHook('beforeBulkInsert', $context);

        $inserted = parent::bulkInsert($prepared);
        if (!$inserted) {
            return false;
        }

        $this->dispatchOperationHook('afterBulkInsert', $context);
        $this->scheduleAfterCommit('bulk_insert', $context);

        return true;
    }

    /**
     * @param array<string,mixed> $attributes
     * @return array<string,mixed>
     */
    #[\Override]
    public function create(array $attributes): array
    {
        $created = parent::create($this->prepareCreateAttributes($attributes));
        $this->scheduleAfterCommit('create', ['row' => $created]);

        return $created;
    }

    /** Delete one row by primary key while preserving repository lifecycle. */
    #[\Override]
    public function deleteById(mixed $id): int
    {
        $affected = parent::deleteById($id);
        if ($affected > 0) {
            $this->scheduleAfterCommit('delete', [
                'id' => $id,
                'affected' => $affected,
                'soft' => $this->softDeletes,
            ]);
        }

        return $affected;
    }

    /** Delete all rows matching a repository-aware scope. */
    public function deleteWhere(?callable $scope = null): int
    {
        $context = ['soft' => $this->softDeletes];
        $this->dispatchOperationHook('beforeBulkDelete', $context);

        $query = $this->applyMutationScope($this->builder(), $scope);
        $affected = $this->softDeletes
            ? $query->update([$this->softDeleteColumn => $this->freshTimestamp()])
            : $query->delete();

        $context['affected'] = $affected;
        $this->dispatchOperationHook('afterBulkDelete', $context);
        if ($affected > 0) {
            $this->scheduleAfterCommit('bulk_delete', $context);
        }

        return $affected;
    }

    public function disableNamedGlobalScope(string $name): static
    {
        if (array_key_exists($name, $this->definition->globalScopes)) {
            $this->disabledGlobalScopes[$name] = true;
        }

        return $this;
    }

    /** @param list<string>|null $names */
    public function disableNamedGlobalScopes(?array $names = null): static
    {
        $names ??= array_keys($this->definition->globalScopes);

        foreach ($names as $name) {
            $this->disableNamedGlobalScope($name);
        }

        return $this;
    }

    /** Permanently delete one row by primary key. */
    #[\Override]
    public function forceDeleteById(mixed $id): int
    {
        $context = ['id' => $id, 'force' => true, 'bulk' => false];
        $this->dispatchOperationHook('beforeForceDelete', $context);

        $affected = parent::forceDeleteById($id);
        $context['affected'] = $affected;

        $this->dispatchOperationHook('afterForceDelete', $context);
        if ($affected > 0) {
            $this->scheduleAfterCommit('force_delete', $context);
        }

        return $affected;
    }

    /** Permanently delete rows matching a repository-aware scope. */
    public function forceDeleteWhere(?callable $scope = null): int
    {
        $context = ['force' => true, 'bulk' => true];
        $this->dispatchOperationHook('beforeForceDelete', $context);

        $query = $this->applyMutationScope($this->builderWithoutSoftDeleteConstraint(), $scope);
        $affected = $query->delete();
        $context['affected'] = $affected;

        $this->dispatchOperationHook('afterForceDelete', $context);
        if ($affected > 0) {
            $this->scheduleAfterCommit('force_delete', $context);
        }

        return $affected;
    }

    /** Restore one soft-deleted row by primary key. */
    #[\Override]
    public function restoreById(mixed $id): int
    {
        $context = ['id' => $id, 'bulk' => false];
        $this->dispatchOperationHook('beforeRestore', $context);

        $affected = parent::restoreById($id);
        $context['affected'] = $affected;

        $this->dispatchOperationHook('afterRestore', $context);
        if ($affected > 0) {
            $this->scheduleAfterCommit('restore', $context);
        }

        return $affected;
    }

    /** Restore all matching soft-deleted rows. */
    public function restoreWhere(?callable $scope = null): int
    {
        if (!$this->softDeletes) {
            return 0;
        }

        $context = ['bulk' => true];
        $this->dispatchOperationHook('beforeRestore', $context);

        $query = $this->builderWithoutSoftDeleteConstraint()
            ->whereNotNull($this->softDeleteColumn);
        $query = $this->applyMutationScope($query, $scope);
        $affected = $query->update([$this->softDeleteColumn => null]);
        $context['affected'] = $affected;

        $this->dispatchOperationHook('afterRestore', $context);
        if ($affected > 0) {
            $this->scheduleAfterCommit('restore', $context);
        }

        return $affected;
    }

    /** @param array<string,string|callable(mixed):mixed|AttributeCast> $casts */
    #[\Override]
    public function setCasts(array $casts): static
    {
        $this->casts = $casts;

        return $this;
    }

    /** @param array<string,mixed> $values */
    #[\Override]
    public function updateById(mixed $id, array $values): int
    {
        if ($values === []) {
            return 0;
        }

        $payload = $this->prepareUpdateAttributes($values);
        $affected = parent::updateById($id, $payload);
        if ($affected > 0) {
            $this->scheduleAfterCommit('update', [
                'id' => $id,
                'payload' => $payload,
                'affected' => $affected,
            ]);
        }

        return $affected;
    }

    /** @param array<string,mixed> $values */
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

        $payload = $this->prepareUpdateAttributes($values);
        $updated = parent::updateByIdWithVersion(
            $id,
            $payload,
            $expectedVersion,
            $versionColumn,
        );

        if ($updated) {
            $this->scheduleAfterCommit('update', [
                'id' => $id,
                'payload' => $payload,
                'optimistic' => true,
                'version_column' => $versionColumn,
            ]);
        }

        return $updated;
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
                $column = trim($column);
                if ($column === '') {
                    throw new InvalidArgumentException('Repository lookup attributes must use non-empty column names.');
                }

                $query->where($column, '=', $value);
            }
        });

        if ($existing === null) {
            return $this->create(array_merge($attributes, $values));
        }

        if ($values === []) {
            return $existing;
        }

        if (array_key_exists($this->definition->primaryKey, $existing)) {
            $id = $existing[$this->definition->primaryKey];
            $this->updateById($id, $values);

            return $this->find($id) ?? $existing;
        }

        return parent::updateOrCreate(
            $attributes,
            $this->prepareUpdateAttributes($values),
        );
    }

    /**
     * Update all rows matching a repository-aware scope.
     *
     * @param array<string,mixed> $values
     */
    public function updateWhere(array $values, ?callable $scope = null): int
    {
        if ($values === []) {
            return 0;
        }

        $payload = RepositoryWriteCaster::cast(
            $this->prepareUpdateAttributes($values),
            $this->definition->casts,
            $this->connection,
        );
        $context = ['payload' => $payload];
        $this->dispatchOperationHook('beforeBulkUpdate', $context);

        $query = $this->applyMutationScope($this->builder(), $scope);
        $affected = $query->update($payload);
        $context['affected'] = $affected;

        $this->dispatchOperationHook('afterBulkUpdate', $context);
        if ($affected > 0) {
            $this->scheduleAfterCommit('bulk_update', $context);
        }

        return $affected;
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

            $prepared[] = $this->prepareCreateAttributes($this->normalizeWriteRow($row));
        }

        if ($prepared === []) {
            return true;
        }

        $updateColumns = $this->prepareUpsertUpdateColumns(
            $update,
            array_keys($prepared[0]),
            $uniqueBy,
        );
        $context = [
            'rows' => $prepared,
            'unique_by' => $uniqueBy,
            'update' => $updateColumns,
        ];
        $this->dispatchOperationHook('beforeUpsert', $context);

        $upserted = parent::upsert(
            $many ? $prepared : $prepared[0],
            $uniqueBy,
            $updateColumns,
        );

        if (!$upserted) {
            return false;
        }

        $this->dispatchOperationHook('afterUpsert', $context);
        $this->scheduleAfterCommit('upsert', $context);

        return true;
    }

    #[\Override]
    protected function applyRepositoryConstraints(QueryBuilder $query): QueryBuilder
    {
        $query = parent::applyRepositoryConstraints($query);

        foreach ($this->definition->globalScopes as $name => $scope) {
            if (isset($this->disabledGlobalScopes[$name])) {
                continue;
            }

            $scope($query);
        }

        return $query;
    }

    #[\Override]
    protected function defaultPerPage(): int
    {
        return $this->definition->perPage;
    }

    #[\Override]
    protected function primaryKey(): string
    {
        return $this->definition->primaryKey;
    }

    #[\Override]
    protected function table(): string
    {
        return $this->definition->table;
    }

    private function applyMutationScope(QueryBuilder $query, ?callable $scope): QueryBuilder
    {
        if ($scope !== null) {
            $scope($query);
        }

        return $query;
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

    private function builderWithoutSoftDeleteConstraint(): QueryBuilder
    {
        $withTrashed = $this->withTrashed;
        $onlyTrashed = $this->onlyTrashed;

        $this->withTrashed = true;
        $this->onlyTrashed = false;

        try {
            return $this->builder();
        } finally {
            $this->withTrashed = $withTrashed;
            $this->onlyTrashed = $onlyTrashed;
        }
    }

    private function freshTimestamp(): string
    {
        return new \DateTimeImmutable('now')->format(
            $this->connection->getDriver()->dateFormat(),
        );
    }

    /**
     * @param array<array-key,mixed> $row
     * @return array<string,mixed>
     */
    private function normalizeWriteRow(array $row): array
    {
        $normalized = [];

        foreach ($row as $column => $value) {
            if (!is_string($column) || trim($column) === '') {
                throw new InvalidArgumentException('Repository write rows must use non-empty string column names.');
            }

            $normalized[$column] = $value;
        }

        return $normalized;
    }

    /**
     * @param array<string,mixed> $attributes
     * @return array<string,mixed>
     */
    private function prepareCreateAttributes(array $attributes): array
    {
        $this->assertAllowedAttributes($attributes, $this->definition->creatable, true);

        $payload = array_replace($this->definition->defaults, $attributes);

        if (!$this->definition->timestamps) {
            return $payload;
        }

        $timestamp = $this->freshTimestamp();
        $payload[$this->definition->createdAt] ??= $timestamp;
        $payload[$this->definition->updatedAt] ??= $timestamp;

        return $payload;
    }

    /**
     * @param array<string,mixed> $attributes
     * @return array<string,mixed>
     */
    private function prepareUpdateAttributes(array $attributes): array
    {
        $this->assertAllowedAttributes($attributes, $this->definition->updatable, false);

        if ($this->definition->timestamps) {
            $attributes[$this->definition->updatedAt] = $this->freshTimestamp();
        }

        return $attributes;
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
                $this->definition->updatable,
                false,
            );

            if (!$this->definition->timestamps
                || in_array($this->definition->updatedAt, $requested, true)) {
                return $requested;
            }

            return [...$requested, $this->definition->updatedAt];
        }

        if (!$this->definition->timestamps) {
            return null;
        }

        return array_values(array_filter(
            $columns,
            fn(string $column): bool => !in_array($column, $uniqueBy, true)
                && $column !== $this->definition->createdAt,
        ));
    }
}
