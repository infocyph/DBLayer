<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Repository;

use Infocyph\DBLayer\Query\QueryBuilder;
use InvalidArgumentException;

/**
 * Immutable, validated metadata compiled once per TableRepository class.
 *
 * Runtime query state is deliberately excluded so the definition is safe to
 * reuse in long-running workers.
 */
final readonly class RepositoryDefinition
{
    /** @var array<string,mixed> */
    public array $defaults;

    /** @var list<string> */
    public array $creatable;

    /** @var list<string> */
    public array $updatable;

    /** @var array<string,mixed> */
    public array $casts;

    /** @var array<string,callable(QueryBuilder):void> */
    public array $globalScopes;

    /** @var array<string,RelationDefinition> */
    public array $relations;

    /**
     * @param class-string<TableRepository> $repositoryClass
     * @param array<string,mixed> $defaults
     * @param list<string> $creatable
     * @param list<string> $updatable
     * @param array<string,mixed> $casts
     * @param array<array-key,callable(QueryBuilder):void> $globalScopes
     * @param array<string,RelationDefinition> $relations
     */
    public function __construct(
        public string $repositoryClass,
        public string $table,
        public string $primaryKey,
        public int $perPage,
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

        $this->defaults = $defaults;
        $this->creatable = $this->normalizeColumns($creatable, 'creatable');
        $this->updatable = $this->normalizeColumns($updatable, 'updatable');
        $this->casts = $casts;
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
     * @param list<string> $columns
     * @return list<string>
     */
    private function normalizeColumns(array $columns, string $label): array
    {
        $normalized = [];
        $seen = [];

        foreach ($columns as $column) {
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
     * @param array<array-key,callable(QueryBuilder):void> $scopes
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
     * @param array<string,RelationDefinition> $relations
     * @return array<string,RelationDefinition>
     */
    private function normalizeRelations(array $relations): array
    {
        $normalized = [];

        foreach ($relations as $name => $relation) {
            $name = trim($name);
            if ($name === '' || !$relation instanceof RelationDefinition) {
                throw new InvalidArgumentException(sprintf(
                    '%s relations must use non-empty names and RelationDefinition values.',
                    $this->repositoryClass,
                ));
            }

            $normalized[$name] = $relation;
        }

        return $normalized;
    }
}
