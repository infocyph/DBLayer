<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Repository;

use Infocyph\DBLayer\Query\QueryBuilder;
use InvalidArgumentException;

/**
 * Resolve parent keys for one-of-many existence queries. Winner selection is
 * performed before the caller's whereHas/whereRelation constraint is applied.
 */
final class RepositoryOneOfManyFilter
{
    public function __construct(private readonly int $batchSize = 500)
    {
        if ($batchSize < 1) {
            throw new InvalidArgumentException('Relation batch size must be at least one.');
        }
    }

    /**
     * @param null|callable(QueryBuilder):void $constraint
     * @return list<mixed>
     */
    public function matchingParentKeys(
        RelationDefinition $definition,
        ?callable $constraint = null,
    ): array {
        $related = $definition->related
            ?? throw new InvalidArgumentException('One-of-many relation requires a related repository.');
        $primaryKey = $related::definition()->primaryKey;
        $winners = (new RepositoryOneOfManySelector($this->batchSize))->select($definition);

        if ($winners === []) {
            return [];
        }

        if ($constraint === null) {
            return array_values(array_map(
                static fn(array $winner): mixed => $winner['parent'],
                $winners,
            ));
        }

        $parentById = [];
        foreach ($winners as $winner) {
            $parentById[$this->key($winner['id'])] = $winner['parent'];
        }

        $ids = array_values(array_map(
            static fn(array $winner): mixed => $winner['id'],
            $winners,
        ));
        $connection = $related::connection();
        $batchSize = $connection->safeBatchSize(requested: $this->batchSize);
        $matches = [];
        $seen = [];

        foreach (array_chunk($ids, $batchSize) as $chunk) {
            $query = $related::query()->apply(
                static function (QueryBuilder $builder) use ($primaryKey, $chunk): void {
                    $builder->whereIn($primaryKey, $chunk);
                },
            );
            $query->apply($constraint);

            foreach ($query->raw()->select($primaryKey)->cursor() as $row) {
                if (!is_array($row) || !array_key_exists($primaryKey, $row)) {
                    continue;
                }

                $parent = $parentById[$this->key($row[$primaryKey])] ?? null;
                if ($parent === null) {
                    continue;
                }

                $identity = $this->key($parent);
                if (isset($seen[$identity])) {
                    continue;
                }

                $seen[$identity] = true;
                $matches[] = $parent;
            }
        }

        return $matches;
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
}
