<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Repository;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Query\QueryBuilder;
use InvalidArgumentException;

/**
 * Repository-aware bounded relation projection.
 *
 * Parent and pivot reads stay explicit while related rows are fetched through
 * the related TableRepository so its scopes, casts, cache policy, and other
 * repository read semantics remain intact.
 */
final class RepositoryRelationLoader
{
    public function __construct(
        private readonly Connection $parentConnection,
        private readonly int $batchSize = 500,
    ) {
        if ($batchSize < 1) {
            throw new InvalidArgumentException('Relation batch size must be at least one.');
        }
    }

    /**
     * @param list<array<string,mixed>> $parents
     * @param null|callable(QueryBuilder):void $constraint
     * @return list<array<string,mixed>>
     */
    public function load(
        array $parents,
        string $as,
        RelationDefinition $definition,
        ?callable $constraint = null,
    ): array {
        if ($parents === []) {
            return $parents;
        }

        return match ($definition->type) {
            RelationDefinition::BELONGS_TO,
            RelationDefinition::HAS_ONE => $this->one($parents, $as, $definition, $constraint),
            RelationDefinition::HAS_MANY => $this->many($parents, $as, $definition, $constraint),
            RelationDefinition::BELONGS_TO_MANY => $this->manyToMany($parents, $as, $definition, $constraint),
            default => throw new InvalidArgumentException(sprintf(
                'Unsupported relation type [%s].',
                $definition->type,
            )),
        };
    }

    /**
     * @param list<array<string,mixed>> $parents
     * @param null|callable(QueryBuilder):void $constraint
     * @return list<array<string,mixed>>
     */
    private function many(
        array $parents,
        string $as,
        RelationDefinition $definition,
        ?callable $constraint,
    ): array {
        $related = $this->fetchRelated($parents, $definition, $constraint);
        $grouped = [];

        foreach ($related as $row) {
            $grouped[$this->key($row[$definition->relatedKey] ?? null)][] = $row;
        }

        foreach ($parents as &$parent) {
            $parent[$as] = $grouped[$this->key($parent[$definition->parentKey] ?? null)] ?? [];
        }
        unset($parent);

        return $parents;
    }

    /**
     * @param list<array<string,mixed>> $parents
     * @param null|callable(QueryBuilder):void $constraint
     * @return list<array<string,mixed>>
     */
    private function manyToMany(
        array $parents,
        string $as,
        RelationDefinition $definition,
        ?callable $constraint,
    ): array {
        $pivotTable = $definition->pivotTable
            ?? throw new InvalidArgumentException('Many-to-many relation requires a pivot table.');
        $pivotParentKey = $definition->pivotParentKey
            ?? throw new InvalidArgumentException('Many-to-many relation requires a pivot parent key.');
        $pivotRelatedKey = $definition->pivotRelatedKey
            ?? throw new InvalidArgumentException('Many-to-many relation requires a pivot related key.');

        $parentValues = $this->values($parents, $definition->parentKey);
        $pivotRows = [];
        $batchSize = $this->parentConnection->safeBatchSize(requested: $this->batchSize);

        foreach (array_chunk($parentValues, $batchSize) as $chunk) {
            array_push(
                $pivotRows,
                ...$this->parentConnection
                    ->table($pivotTable)
                    ->select([$pivotParentKey, $pivotRelatedKey])
                    ->whereIn($pivotParentKey, $chunk)
                    ->get(),
            );
        }

        $relatedRows = $this->fetchByValues(
            $this->values($pivotRows, $pivotRelatedKey),
            $definition,
            $constraint,
        );
        $relatedByKey = [];

        foreach ($relatedRows as $row) {
            $relatedByKey[$this->key($row[$definition->relatedKey] ?? null)] = $row;
        }

        $relatedKeysByParent = [];
        foreach ($pivotRows as $pivot) {
            $relatedKeysByParent[$this->key($pivot[$pivotParentKey] ?? null)][]
                = $this->key($pivot[$pivotRelatedKey] ?? null);
        }

        foreach ($parents as &$parent) {
            $matches = [];
            foreach ($relatedKeysByParent[$this->key($parent[$definition->parentKey] ?? null)] ?? [] as $relatedKey) {
                if (isset($relatedByKey[$relatedKey])) {
                    $matches[] = $relatedByKey[$relatedKey];
                }
            }

            $parent[$as] = $matches;
        }
        unset($parent);

        return $parents;
    }

    /**
     * @param list<array<string,mixed>> $parents
     * @param null|callable(QueryBuilder):void $constraint
     * @return list<array<string,mixed>>
     */
    private function one(
        array $parents,
        string $as,
        RelationDefinition $definition,
        ?callable $constraint,
    ): array {
        $related = $this->fetchRelated($parents, $definition, $constraint);
        $indexed = [];

        foreach ($related as $row) {
            $key = $this->key($row[$definition->relatedKey] ?? null);
            $indexed[$key] ??= $row;
        }

        foreach ($parents as &$parent) {
            $parent[$as] = $indexed[$this->key($parent[$definition->parentKey] ?? null)] ?? null;
        }
        unset($parent);

        return $parents;
    }

    /**
     * @param list<mixed> $values
     * @param null|callable(QueryBuilder):void $constraint
     * @return list<array<string,mixed>>
     */
    private function fetchByValues(
        array $values,
        RelationDefinition $definition,
        ?callable $constraint,
    ): array {
        if ($values === []) {
            return [];
        }

        $related = $definition->related;
        if (!is_a($related, TableRepository::class, true)) {
            throw new InvalidArgumentException(sprintf(
                'Related repository [%s] must extend %s.',
                $related,
                TableRepository::class,
            ));
        }

        $columns = $this->ensureKeySelected($definition->columns, $definition->relatedKey);
        $connection = $related::connection();
        $batchSize = $connection->safeBatchSize(requested: $this->batchSize);
        $rows = [];

        foreach (array_chunk($values, $batchSize) as $chunk) {
            $query = $related::query()->whereIn($definition->relatedKey, $chunk);
            if ($definition->scope !== null) {
                $query->apply($definition->scope);
            }
            if ($constraint !== null) {
                $query->apply($constraint);
            }

            array_push($rows, ...$query->get($columns)->toArray());
        }

        return $rows;
    }

    /**
     * @param list<array<string,mixed>> $parents
     * @param null|callable(QueryBuilder):void $constraint
     * @return list<array<string,mixed>>
     */
    private function fetchRelated(
        array $parents,
        RelationDefinition $definition,
        ?callable $constraint,
    ): array {
        return $this->fetchByValues(
            $this->values($parents, $definition->parentKey),
            $definition,
            $constraint,
        );
    }

    /**
     * @param list<string> $columns
     * @return list<string>
     */
    private function ensureKeySelected(array $columns, string $key): array
    {
        if ($columns === ['*'] || in_array('*', $columns, true) || in_array($key, $columns, true)) {
            return $columns;
        }

        return [...$columns, $key];
    }

    private function key(mixed $value): string
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
     * @param list<array<string,mixed>> $rows
     * @return list<mixed>
     */
    private function values(array $rows, string $column): array
    {
        $values = [];
        $seen = [];

        foreach ($rows as $row) {
            if (!array_key_exists($column, $row) || $row[$column] === null) {
                continue;
            }

            $key = $this->key($row[$column]);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $values[] = $row[$column];
        }

        return $values;
    }
}
