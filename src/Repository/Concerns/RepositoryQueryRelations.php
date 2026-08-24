<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Repository\Concerns;

use Infocyph\DBLayer\Query\QueryBuilder;
use Infocyph\DBLayer\Repository\RelationDefinition;
use Infocyph\DBLayer\Repository\RepositoryRelationFilter;
use Infocyph\DBLayer\Repository\RepositoryRelationLoader;
use Infocyph\DBLayer\Repository\RepositorySupport;
use InvalidArgumentException;

trait RepositoryQueryRelations
{
    /** @param null|callable(QueryBuilder):void $constraint */
    public function whereDoesntHave(string $relation, ?callable $constraint = null): self
    {
        $definition = $this->directRelationDefinition($relation);
        new RepositoryRelationFilter($this->connection)->apply($this, $definition, $constraint, true);

        return $this;
    }

    /** @param null|callable(QueryBuilder):void $constraint */
    public function whereHas(string $relation, ?callable $constraint = null): self
    {
        $definition = $this->directRelationDefinition($relation);
        new RepositoryRelationFilter($this->connection)->apply($this, $definition, $constraint);

        return $this;
    }

    public function whereRelation(
        string $relation,
        string $column,
        mixed $operator = null,
        mixed $value = null,
    ): self {
        if (func_num_args() === 3) {
            $value = $operator;
            $operator = '=';
        }

        if (!is_string($operator) || trim($operator) === '') {
            throw new InvalidArgumentException('Relation operator must be a non-empty string.');
        }

        $column = RepositorySupport::column($column);

        return $this->whereHas(
            $relation,
            static function (QueryBuilder $query) use ($column, $operator, $value): void {
                $query->where($column, $operator, $value);
            },
        );
    }

    /** @param string|array<int|string,string|callable(QueryBuilder):void> ...$relations */
    public function with(string|array ...$relations): self
    {
        foreach ($relations as $relation) {
            if (is_string($relation)) {
                $this->registerRelation($relation, null);

                continue;
            }

            foreach ($relation as $name => $constraint) {
                if (is_int($name)) {
                    if (!is_string($constraint)) {
                        throw new InvalidArgumentException('Numeric relation entries must contain relation names.');
                    }

                    $this->registerRelation($constraint, null);

                    continue;
                }

                if (!is_callable($constraint)) {
                    throw new InvalidArgumentException(sprintf(
                        'Relation constraint for [%s] must be callable.',
                        $name,
                    ));
                }

                $this->registerRelation($name, $constraint);
            }
        }

        return $this;
    }

    private function directRelationDefinition(string $name): RelationDefinition
    {
        if (str_contains($name, '.')) {
            throw new InvalidArgumentException('Relation aggregates and existence filters currently require a direct relation name.');
        }

        return $this->relationDefinition($name);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array{0:list<array<string,mixed>>,1:array<int,int>}
     */
    private function flattenNestedRows(array $rows, string $name, bool $many): array
    {
        $flat = [];
        $sizes = [];

        foreach ($rows as $index => $row) {
            $value = $row[$name] ?? ($many ? [] : null);
            $items = $many ? $this->relationRows($value) : $this->singleRelationRows($value);
            $sizes[$index] = count($items);
            array_push($flat, ...$items);
        }

        return [$flat, $sizes];
    }

    private function isManyRelation(RelationDefinition $definition): bool
    {
        return in_array($definition->type, [
            RelationDefinition::BELONGS_TO_MANY,
            RelationDefinition::HAS_MANY,
            RelationDefinition::MORPH_MANY,
            RelationDefinition::MORPH_TO_MANY,
        ], true);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function loadRelations(array $rows): array
    {
        if ($rows === [] || $this->requestedRelations === []) {
            return $rows;
        }

        $loader = new RepositoryRelationLoader($this->connection);

        foreach ($this->relationGroups() as $name => $request) {
            $definition = $this->relationDefinition($name);
            $rows = $loader->load($rows, $name, $definition, $request['constraint']);

            if ($request['nested'] !== []) {
                $rows = $this->projectNested($rows, $name, $definition, $request['nested']);
            }
        }

        return $rows;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param array<string,null|callable(QueryBuilder):void> $nested
     * @return list<array<string,mixed>>
     */
    private function projectNested(
        array $rows,
        string $name,
        RelationDefinition $definition,
        array $nested,
    ): array {
        if ($definition->type === RelationDefinition::MORPH_TO) {
            return $this->projectNestedMorphTo($rows, $name, $definition, $nested);
        }

        $related = $definition->related
            ?? throw new InvalidArgumentException('Nested relation requires a related repository.');
        $many = $this->isManyRelation($definition);
        [$flat, $sizes] = $this->flattenNestedRows($rows, $name, $many);

        if ($flat === []) {
            return $rows;
        }

        $projected = $related::repositoryQuery()
            ->with($this->relationRequestArray($nested))
            ->project($flat);

        return $this->restoreNestedRows($rows, $name, $many, $sizes, $projected);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param array<string,null|callable(QueryBuilder):void> $nested
     * @return list<array<string,mixed>>
     */
    private function projectNestedMorphTo(
        array $rows,
        string $name,
        RelationDefinition $definition,
        array $nested,
    ): array {
        $typeColumn = $definition->morphTypeColumn
            ?? throw new InvalidArgumentException('Morph-to relation requires a type column.');
        /** @var array<string,list<array{index:int,row:array<string,mixed>}>> $groups */
        $groups = [];

        foreach ($rows as $index => $row) {
            $type = $row[$typeColumn] ?? null;
            $relatedRow = RepositorySupport::row($row[$name] ?? null);
            if (!is_string($type) || $relatedRow === null || !isset($definition->morphMap[$type])) {
                continue;
            }
            $groups[$type][] = ['index' => $index, 'row' => $relatedRow];
        }

        foreach ($groups as $type => $entries) {
            $related = $definition->morphMap[$type];
            $projected = $related::repositoryQuery()
                ->with($this->relationRequestArray($nested))
                ->project(array_map(
                    static fn(array $entry): array => $entry['row'],
                    $entries,
                ));

            foreach ($entries as $offset => $entry) {
                $rows[$entry['index']][$name] = $projected[$offset] ?? null;
            }
        }

        return $rows;
    }

    /** @param null|callable(QueryBuilder):void $constraint */
    private function registerRelation(string $path, ?callable $constraint): void
    {
        $path = trim($path);
        $segments = $path === '' ? [] : explode('.', $path);

        if ($segments === [] || in_array('', $segments, true)) {
            throw new InvalidArgumentException('Relation path must not be empty.');
        }
        if (count($segments) > $this->definition->maxRelationDepth) {
            throw new InvalidArgumentException(sprintf(
                'Relation path [%s] exceeds the configured maximum depth of %d.',
                $path,
                $this->definition->maxRelationDepth,
            ));
        }

        $this->relationDefinition($segments[0]);
        $this->requestedRelations[$path] = $constraint;
    }

    private function relationDefinition(string $name): RelationDefinition
    {
        $definition = $this->definition->relations[$name] ?? null;

        if (!$definition instanceof RelationDefinition) {
            throw new InvalidArgumentException(sprintf(
                'Relation [%s] is not defined for this repository.',
                $name,
            ));
        }

        return $definition;
    }

    /**
     * @return array<string,array{constraint:null|callable(QueryBuilder):void,nested:array<string,null|callable(QueryBuilder):void>}>
     */
    private function relationGroups(): array
    {
        $groups = [];

        foreach ($this->requestedRelations as $path => $constraint) {
            $parts = explode('.', $path, 2);
            $name = $parts[0];
            $nested = $parts[1] ?? null;
            $groups[$name] ??= ['constraint' => null, 'nested' => []];

            if ($nested === null) {
                $groups[$name]['constraint'] = $constraint;
            } else {
                $groups[$name]['nested'][$nested] = $constraint;
            }
        }

        return $groups;
    }

    /** @return list<string> */
    private function relationParentColumns(RelationDefinition $definition): array
    {
        if ($definition->type !== RelationDefinition::MORPH_TO) {
            return [$definition->parentKey];
        }

        return array_values(array_unique([
            $definition->morphTypeColumn
                ?? throw new InvalidArgumentException('Morph-to relation requires a type column.'),
            $definition->morphIdColumn
                ?? throw new InvalidArgumentException('Morph-to relation requires an id column.'),
        ]));
    }

    /**
     * @param array<string,null|callable(QueryBuilder):void> $relations
     * @return array<int|string,string|callable(QueryBuilder):void>
     */
    private function relationRequestArray(array $relations): array
    {
        $request = [];
        foreach ($relations as $path => $constraint) {
            if ($constraint === null) {
                $request[] = $path;
            } else {
                $request[$path] = $constraint;
            }
        }

        return $request;
    }

    /** @return list<array<string,mixed>> */
    private function relationRows(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $rows = [];
        foreach ($value as $candidate) {
            $row = RepositorySupport::row($candidate);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param array<int,int> $sizes
     * @param list<array<string,mixed>> $projected
     * @return list<array<string,mixed>>
     */
    private function restoreNestedRows(
        array $rows,
        string $name,
        bool $many,
        array $sizes,
        array $projected,
    ): array {
        $offset = 0;
        foreach ($rows as $index => &$row) {
            $size = $sizes[$index] ?? 0;
            $slice = array_slice($projected, $offset, $size);
            $row[$name] = $many ? $slice : ($slice[0] ?? null);
            $offset += $size;
        }
        unset($row);

        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function singleRelationRows(mixed $value): array
    {
        $row = RepositorySupport::row($value);

        return $row === null ? [] : [$row];
    }
}
