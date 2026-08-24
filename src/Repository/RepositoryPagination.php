<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Repository;

use Infocyph\DBLayer\Pagination\LengthAwarePaginator;
use Infocyph\DBLayer\Pagination\SimplePaginator;
use Infocyph\DBLayer\Query\QueryBuilder;
use Infocyph\DBLayer\Query\Repository;

/**
 * Repository pagination with repository-processed row results.
 */
abstract class RepositoryPagination extends Repository
{
    /**
     * Paginate repository rows while preserving casts/result processing.
     *
     * @param callable(QueryBuilder):void|null $scope
     */
    #[\Override]
    public function paginate(int $perPage = 15, ?int $page = null, ?callable $scope = null): LengthAwarePaginator
    {
        if ($perPage < 1) {
            throw new \InvalidArgumentException('Pagination size must be at least one.');
        }

        $page = max(1, $page ?? 1);
        $total = $this->count($scope);
        $items = $this->get(
            static function (QueryBuilder $query) use ($scope, $page, $perPage): void {
                if ($scope !== null) {
                    $scope($query);
                }

                $query->forPage($page, $perPage);
            },
        )->toArray();

        return new LengthAwarePaginator($items, $total, $perPage, $page);
    }

    /**
     * Simple-paginate repository rows while preserving casts/result processing.
     *
     * @param callable(QueryBuilder):void|null $scope
     */
    #[\Override]
    public function simplePaginate(int $perPage = 15, ?int $page = null, ?callable $scope = null): SimplePaginator
    {
        if ($perPage < 1) {
            throw new \InvalidArgumentException('Pagination size must be at least one.');
        }

        $page = max(1, $page ?? 1);
        $items = $this->get(
            static function (QueryBuilder $query) use ($scope, $page, $perPage): void {
                if ($scope !== null) {
                    $scope($query);
                }

                $query
                    ->offset(($page - 1) * $perPage)
                    ->limit($perPage + 1);
            },
        )->toArray();

        $hasMore = count($items) > $perPage;
        if ($hasMore) {
            array_pop($items);
        }

        return new SimplePaginator($items, $perPage, $page, $hasMore);
    }
}
