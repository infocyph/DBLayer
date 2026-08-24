<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Repository;

use Infocyph\DBLayer\Query\QueryBuilder;
use Infocyph\DBLayer\Repository\Casts\AttributeCast;
use Infocyph\DBLayer\Repository\Casts\CastFactory;
use InvalidArgumentException;

/** Immutable, validated metadata compiled once per TableRepository class. */
final readonly class RepositoryDefinition
{
    /** @var array<string,mixed> */
    public array $defaults;

    /** @var list<string> */
    public array $creatable;

    /** @var list<string> */
    public array $updatable;

    /** @var array<string,string|callable(mixed):mixed|AttributeCast> */
    public array $casts;

    /** @var array<string,callable(QueryBuilder):void> */
    public array $globalScopes;

    /** @var array<string,RelationDefinition> */
    public array $relations;

    /**
     * @param class-string<TableRepository> $repositoryClass
     * @param array<string,mixed> $defaults
     * @param array<array-key,mixed> $creatable
     * @param array<array-key,mixed> $updatable
     * @param array<array-key,mixed> $casts
     * @param array<array-key,mixed> $globalScopes
     * @param array<array-key,mixed> $relations
     */
    public function __construct(
        public string $repositoryClass,
        public string $table,
        public string $primaryKey,
        public int $perPage,
        public int $maxRelationDepth = 3,
        array $defaults = [],
        array $creatable = [],
        array $updatable = [],
        public bool $timestamps = false,
        public string $createdAt = 'created_at',
        public string $updatedAt = 'updated_at',
        array $casts = [],
        array $globalScopes = [],
        array $relations = [],
    ) {
        $this->assertIdentifier($table, 'table');
        $this->assertIdentifier($primaryKey, 'primary key');
        $this->assertIdentifier($createdAt, 'created timestamp column');
        $this->assertIdentifier($updatedAt, 'updated timestamp column');

        if ($perPage < 1) {
            throw new InvalidArgumentException(sprintf(
                '%s must define $perPage as a positive integer.',
                $repositoryClass,
            ));
        }

        if ($maxRelationDepth < 1) {
            throw new InvalidArgumentException(sprintf(
                '%s must define $maxRelationDepth as a positive integer.',
                $repositoryClass,
            ));
        }

        $this->defaults = $defaults;
        $this->creatable = $this->normalizeColumns($creatable, 'creatable');
        $this->updatable = $this->normalizeColumns($updatable, 'updatable');
        $this->casts = $this->normalizeCasts($casts);
        $this->globalScopes = $this->normalizeScopes($globalScopes);
        $this->relations = $this->normalizeRelations($relations);
    }

    private function assertIdentifier(string $value, string $label): void
    {
        if (trim($value) !== '') {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            '%s must define a non-empty %s.',
            $this->repositoryClass,
            $label,
        ));
    }

    /**
     * @param array<array-key,mixed> $casts
     * @return array<string,string|callable(mixed):mixed|AttributeCast>
     */
    private function normalizeCasts(array $casts): array
    {
        $normalized = [];

        foreach ($casts as $column => $cast) {
            if (!is_string($column)) {
                throw new InvalidArgumentException(sprintf(
                    '%s cast columns must be strings.',
                    $this->repositoryClass,
                ));
            }

            $column = trim($column);
            if ($column === '') {
                throw new InvalidArgumentException(sprintf(
                    '%s contains an empty cast column.',
                    $this->repositoryClass,
                ));
            }

            if (!is_string($cast) && !is_callable($cast) && !$cast instanceof AttributeCast) {
                throw new InvalidArgumentException(sprintf(
                    '%s cast [%s] must be a cast name, callable, or AttributeCast.',
                    $this->repositoryClass,
                    $column,
                ));
            }

            $normalized[$column] = is_string($cast) ? CastFactory::compile($cast) : $cast;
        }

        return $normalized;
    }

    /**
     * @param array<array-key,mixed> $columns
     * @return list<string>
     */
    private function normalizeColumns(array $columns, string $label): array
    {
        $normalized = [];
        $seen = [];

        foreach ($columns as $column) {
            if (!is_string($column)) {
                throw new InvalidArgumentException(sprintf(
                    '%s %s attributes must be strings.',
                    $this->repositoryClass,
                    $label,
                ));
            }

            $column = trim($column);
            if ($column === '') {
                throw new InvalidArgumentException(sprintf(
                    '%s contains an empty %s attribute.',
                    $this->repositoryClass,
                    $label,
                ));
            }

            if (isset($seen[$column])) {
                continue;
            }

            $seen[$column] = true;
            $normalized[] = $column;
        }

        return $normalized;
    }

    /**
     * @param array<array-key,mixed> $scopes
     * @return array<string,callable(QueryBuilder):void>
     */
    private function normalizeScopes(array $scopes): array
    {
        $normalized = [];

        foreach ($scopes as $name => $scope) {
            if (!is_callable($scope)) {
                throw new InvalidArgumentException(sprintf(
                    '%s global scope [%s] must be callable.',
                    $this->repositoryClass,
                    (string) $name,
                ));
            }

            $scopeName = is_string($name) ? trim($name) : 'scope.' . $name;
            if ($scopeName === '') {
                throw new InvalidArgumentException(sprintf(
                    '%s contains an empty global-scope name.',
                    $this->repositoryClass,
                ));
            }

            $normalized[$scopeName] = $scope;
        }

        return $normalized;
    }

    /**
     * @param array<array-key,mixed> $relations
     * @return array<string,RelationDefinition>
     */
    private function normalizeRelations(array $relations): array
    {
        $normalized = [];

        foreach ($relations as $name => $relation) {
            $name = is_string($name) ? trim($name) : '';
            if ($name === '' || !$relation instanceof RelationDefinition) {
                throw new InvalidArgumentException(sprintf(
                    '%s relations must use non-empty names and RelationDefinition values.',
                    $this->repositoryClass,
                ));
            }

            $this->validateRelation($name, $relation);
            $normalized[$name] = $relation;
        }

        return $normalized;
    }

    private function validateRelation(string $name, RelationDefinition $relation): void
    {
        $this->assertRelationColumns($name, $relation);

        if ($relation->type === RelationDefinition::MORPH_TO) {
            $this->validateMorphTo($name, $relation);
            return;
        }

        if ($relation->related === null) {
            throw new InvalidArgumentException(sprintf(
                '%s relation [%s] must target a TableRepository class.',
                $this->repositoryClass,
                $name,
            ));
        }

        $this->validatePivot($relation);
        $this->validateThrough($name, $relation);
        $this->validateOneOfMany($name, $relation);

        foreach ($relation->pivotColumns as $column) {
            $this->assertIdentifier($column, 'pivot column');
        }
    }

    private function validateMorphTo(string $name, RelationDefinition $relation): void
    {
        if ($relation->related !== null || $relation->morphMap === []) {
            throw new InvalidArgumentException(sprintf(
                '%s morph-to relation [%s] requires only an explicit morph map.',
                $this->repositoryClass,
                $name,
            ));
        }

        foreach ($relation->morphMap as $alias => $related) {
            unset($related);
            if (trim($alias) === '') {
                throw new InvalidArgumentException(sprintf(
                    '%s morph-to relation [%s] contains an invalid morph-map entry.',
                    $this->repositoryClass,
                    $name,
                ));
            }
        }
    }

    private function validatePivot(RelationDefinition $relation): void
    {
        if ($relation->pivotTable === null) {
            return;
        }

        $this->assertIdentifier($relation->pivotTable, 'pivot table');
        $this->assertIdentifier((string) $relation->pivotParentKey, 'pivot parent key');
        $this->assertIdentifier((string) $relation->pivotRelatedKey, 'pivot related key');
    }

    private function validateThrough(string $name, RelationDefinition $relation): void
    {
        if ($relation->through === null) {
            return;
        }

        if (!in_array($relation->type, [RelationDefinition::HAS_ONE, RelationDefinition::HAS_MANY], true)) {
            throw new InvalidArgumentException(sprintf(
                '%s through relation [%s] must use has-one or has-many cardinality.',
                $this->repositoryClass,
                $name,
            ));
        }

        $this->assertIdentifier((string) $relation->throughParentKey, 'through parent key');
        $this->assertIdentifier((string) $relation->throughKey, 'through local key');
    }

    private function validateOneOfMany(string $name, RelationDefinition $relation): void
    {
        if ($relation->oneOfManyAggregate === null) {
            return;
        }

        if (!in_array($relation->type, [
            RelationDefinition::HAS_ONE,
            RelationDefinition::HAS_ONE_THROUGH,
            RelationDefinition::MORPH_ONE,
        ], true)) {
            throw new InvalidArgumentException(sprintf(
                '%s relation [%s] uses one-of-many metadata on an unsupported relation type.',
                $this->repositoryClass,
                $name,
            ));
        }

        if (!in_array($relation->oneOfManyAggregate, ['max', 'min'], true)) {
            throw new InvalidArgumentException(sprintf(
                '%s relation [%s] has an invalid one-of-many aggregate.',
                $this->repositoryClass,
                $name,
            ));
        }

        if ($relation->oneOfManyColumn !== null) {
            $this->assertIdentifier($relation->oneOfManyColumn, 'one-of-many column');
        }
    }

    private function assertRelationColumns(string $name, RelationDefinition $relation): void
    {
        $this->assertIdentifier($relation->parentKey, sprintf('relation [%s] parent key', $name));
        $this->assertIdentifier($relation->relatedKey, sprintf('relation [%s] related key', $name));

        if ($relation->morphTypeColumn !== null) {
            $this->assertIdentifier($relation->morphTypeColumn, sprintf('relation [%s] morph type column', $name));
        }

        if ($relation->morphIdColumn !== null) {
            $this->assertIdentifier($relation->morphIdColumn, sprintf('relation [%s] morph id column', $name));
        }

        if ($relation->morphAlias !== null && trim($relation->morphAlias) === '') {
            throw new InvalidArgumentException(sprintf(
                '%s relation [%s] morph alias must not be empty.',
                $this->repositoryClass,
                $name,
            ));
        }
    }
}
