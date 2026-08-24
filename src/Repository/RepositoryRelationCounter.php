<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Repository;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Query\QueryBuilder;
use InvalidArgumentException;

/**
 * Count declared relations without hydrating relation graphs.
 */
final class RepositoryRelationCounter
{
    private readonly RepositoryRelationAggregator $aggregator;

    public function __construct(Connection $parentConnection, int $batchSize = 500)
    {
        if ($batchSize < 1) {
            throw new InvalidArgumentException('Relation batch size must be at least one.');
        }

        $this->aggregator = new RepositoryRelationAggregator($parentConnection, $batchSize);
    }

    /**
     * @param list<array<string,mixed>> $parents
     * @param null|callable(QueryBuilder):void $constraint
     * @return array<string,int>
     */
    public function count(
        array $parents,
        RelationDefinition $definition,
        ?callable $constraint = null,
    ): array {
        $aggregates = $this->aggregator->aggregate(
            $parents,
            $definition,
            'count',
            '*',
            $constraint,
        );
        $counts = [];

        foreach ($aggregates as $key => $value) {
            $counts[$key] = (int) $value;
        }

        return $counts;
    }
}
