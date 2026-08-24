<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Repository;

use Infocyph\DBLayer\Query\QueryBuilder;
use InvalidArgumentException;
use LogicException;

/**
 * Explicit bounded pruning service for TableRepository classes.
 *
 * Candidate selection uses the repository-aware query path. Deletion is
 * executed once per primary-key batch, avoiding per-row delete loops. Bulk
 * lifecycle semantics apply; no per-row model events are fabricated.
 */
final readonly class RepositoryPruner
{
    /**
     * @param class-string<TableRepository> $repositoryClass
     */
    public function __construct(
        private string $repositoryClass,
        private ?string $connection = null,
    ) {}

    /**
     * Count rows matching a pruning scope.
     *
     * @param null|callable(RepositoryQuery):void $scope
     */
    public function count(?callable $scope = null): int
    {
        $query = $this->query($scope);

        return $query->count();
    }

    /**
     * Prune matching rows in bounded primary-key batches.
     *
     * By default this follows repository delete semantics, including configured
     * soft deletes. Set $force=true for permanent deletion.
     *
     * @param null|callable(RepositoryQuery):void $scope
     */
    public function prune(
        ?callable $scope = null,
        int $chunkSize = 1000,
        bool $force = false,
    ): int {
        if ($chunkSize < 1) {
            throw new InvalidArgumentException('Prune chunk size must be at least one.');
        }

        $class = $this->repositoryClass;
        $definition = $class::definition();
        $repository = $class::repository($this->connection);

        if (!$repository instanceof TableQueryRepository) {
            throw new LogicException('Repository pruning requires TableQueryRepository.');
        }

        $deleted = 0;
        $query = $this->query($scope);
        $builder = $query->raw()->select($definition->primaryKey);

        $builder->chunkById(
            $chunkSize,
            function (array $rows) use ($repository, $definition, $force, &$deleted): bool {
                $ids = [];

                foreach ($rows as $row) {
                    if (!array_key_exists($definition->primaryKey, $row)) {
                        continue;
                    }
                    $ids[] = $row[$definition->primaryKey];
                }

                if ($ids === []) {
                    return true;
                }

                $scope = static function (QueryBuilder $query) use ($definition, $ids): void {
                    $query->whereIn($definition->primaryKey, $ids);
                };

                $deleted += $force
                    ? $repository->forceDeleteWhere($scope)
                    : $repository->deleteWhere($scope);

                return true;
            },
            $definition->primaryKey,
        );

        return $deleted;
    }

    /** @param null|callable(RepositoryQuery):void $scope */
    private function query(?callable $scope): RepositoryQuery
    {
        $class = $this->repositoryClass;
        $query = $class::repositoryQuery($this->connection);

        if ($scope !== null) {
            $scope($query);
        }

        return $query;
    }
}
