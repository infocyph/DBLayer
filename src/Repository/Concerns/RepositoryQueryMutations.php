<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Repository\Concerns;

use Infocyph\DBLayer\Repository\TableQueryRepository;
use InvalidArgumentException;
use LogicException;

/**
 * Repository-aware fluent mutation terminals.
 *
 * Any QueryBuilder mutation without a repository-semantic equivalent is
 * intentionally blocked by RepositoryQuery and remains available through
 * raw()/builder() as an explicit policy bypass.
 */
trait RepositoryQueryMutations
{
    /** Delete rows matching the current repository-aware fluent query. */
    public function delete(): int
    {
        return $this->tableRepository()->deleteWhere($this->scope());
    }

    /** Permanently delete rows matching the current fluent query. */
    public function forceDelete(): int
    {
        return $this->tableRepository()->forceDeleteWhere($this->scope());
    }

    /**
     * Insert one or many rows through repository write policy.
     *
     * @param array<string,mixed>|list<array<string,mixed>> $values
     */
    public function insert(array $values): bool
    {
        if ($values === []) {
            return true;
        }

        if (array_is_list($values)) {
            foreach ($values as $row) {
                if (!is_array($row)) {
                    throw new InvalidArgumentException('Repository bulk inserts require row arrays.');
                }
            }

            /** @var list<array<string,mixed>> $values */
            return $this->tableRepository()->bulkInsert($values);
        }

        /** @var array<string,mixed> $values */
        $this->tableRepository()->create($values);

        return true;
    }

    /**
     * Insert one row and return the repository primary/generated identifier.
     *
     * @param array<string,mixed> $values
     */
    public function insertGetId(array $values, ?string $sequence = null): string
    {
        if ($values === []) {
            throw new InvalidArgumentException('Repository insertGetId requires one associative row.');
        }

        $row = $this->tableRepository()->create($values);
        $column = $sequence ?? $this->definition->primaryKey;
        $id = $row[$column] ?? null;

        if (!is_int($id) && !is_string($id)) {
            throw new LogicException(sprintf(
                'Repository insert did not return identifier column [%s].',
                $column,
            ));
        }

        return (string) $id;
    }

    /** Restrict this query to soft-deleted rows. */
    public function onlyTrashed(): self
    {
        $this->tableRepository()->onlyTrashed();
        $this->rebuildBuilder();

        return $this;
    }

    /** Restore soft-deleted rows matching the current fluent query. */
    public function restore(): int
    {
        return $this->tableRepository()->restoreWhere($this->scope());
    }

    /**
     * Update rows matching the current repository-aware fluent query.
     *
     * @param array<string,mixed> $values
     */
    public function update(array $values): int
    {
        return $this->tableRepository()->updateWhere($values, $this->scope());
    }

    /**
     * Upsert one or many rows through repository write policy.
     *
     * @param array<string,mixed>|list<array<string,mixed>> $values
     * @param list<string> $uniqueBy
     * @param list<string>|null $update
     */
    public function upsert(array $values, array $uniqueBy, ?array $update = null): bool
    {
        return $this->tableRepository()->upsert($values, $uniqueBy, $update);
    }

    /** Restore default soft-delete visibility for this query. */
    public function withoutTrashed(): self
    {
        $this->tableRepository()->withoutTrashed();
        $this->rebuildBuilder();

        return $this;
    }

    /** Include soft-deleted rows for this query only. */
    public function withTrashed(): self
    {
        $this->tableRepository()->withTrashed();
        $this->rebuildBuilder();

        return $this;
    }

    private function isBlockedBuilderMutation(string $method): bool
    {
        return in_array($method, [
            'insertIgnore',
            'insertReturning',
            'truncate',
            'upsertReturning',
        ], true);
    }

    private function tableRepository(): TableQueryRepository
    {
        if (!$this->repository instanceof TableQueryRepository) {
            throw new LogicException('Repository-aware mutation requires TableQueryRepository.');
        }

        return $this->repository;
    }
}
