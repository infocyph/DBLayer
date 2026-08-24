<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Repository;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Query\QueryBuilder;

/**
 * Aggregate one-of-many relations by projecting only the selected related row
 * for each parent.
 */
final readonly class RepositoryOneOfManyAggregator
{
    public function __construct(private Connection $parentConnection, private int $batchSize = 500) {}

    /**
     * @param list<array<string,mixed>> $parents
     * @param null|callable(QueryBuilder):void $constraint
     * @return array<string,mixed>
     */
    public function aggregate(
        array $parents,
        RelationDefinition $definition,
        string $function,
        string $column,
        ?callable $constraint = null,
    ): array {
        $projection = $function === 'count'
            ? [$definition->relatedKey]
            : [$definition->relatedKey, $column];
        $selectedDefinition = $definition->select(array_values(array_unique($projection)));
        $alias = '__one_of_many_aggregate';
        $rows = new RepositoryRelationLoader($this->parentConnection, $this->batchSize)
            ->load($parents, $alias, $selectedDefinition, $constraint);
        $result = [];

        foreach ($rows as $parent) {
            $related = $parent[$alias] ?? null;
            if (!is_array($related)) {
                continue;
            }

            $identity = $this->parentIdentity($parent, $definition);
            $result[$identity] = match ($function) {
                'count' => 1,
                'sum', 'avg' => is_numeric($related[$column] ?? null) ? ($related[$column] + 0) : 0,
                'min', 'max' => $related[$column] ?? null,
                default => null,
            };
        }

        return $result;
    }

    private function key(mixed $value): string
    {
        return match (true) {
            is_int($value), is_string($value) => 'scalar:' . $value,
            is_float($value) => 'float:' . serialize($value),
            is_bool($value) => 'bool:' . ($value ? '1' : '0'),
            $value === null => 'null:',
            default => serialize($value),
        };
    }

    /** @param array<string,mixed> $parent */
    private function parentIdentity(array $parent, RelationDefinition $definition): string
    {
        return $this->key($parent[$definition->parentKey] ?? null);
    }
}
