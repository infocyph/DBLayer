<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Query\Repository;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Grammar\Grammar;
use Infocyph\DBLayer\Query\Executor;
use Infocyph\DBLayer\Query\QueryBuilder;
use Infocyph\DBLayer\Query\ResultProcessor;
use Infocyph\DBLayer\Support\Collection;

/**
 * Base Repository
 *
 * Thin repository/model layer on top of the QueryBuilder:
 * - Centralizes use of ResultProcessor
 * - Provides table-aware helpers (find, all, pluck, aggregates)
 * - Allows flexible scoping via closures
 *
 * Extend this per "model":
 *
 *   final class UserRepository extends Repository {
 *       protected function table(): string { return 'users'; }
 *       protected function primaryKey(): string { return 'id'; }
 *   }
 *
 * @package Infocyph\DBLayer\Query\Repository
 * @author Hasan
 */
abstract class Repository
{
    /**
     * Database connection
     */
    protected Connection $connection;

    /**
     * SQL grammar compiler
     */
    protected Grammar $grammar;

    /**
     * Query executor
     */
    protected Executor $executor;

    /**
     * Result processor
     */
    protected ResultProcessor $results;

    /**
     * Create a new repository instance
     */
    public function __construct(
      Connection $connection,
      Grammar $grammar,
      Executor $executor,
      ResultProcessor $results
    ) {
        $this->connection = $connection;
        $this->grammar = $grammar;
        $this->executor = $executor;
        $this->results = $results;
    }

    /**
     * The backing table name.
     *
     * Each concrete repository MUST define its table.
     */
    abstract protected function table(): string;

    /**
     * Primary key column name.
     *
     * Override if the primary key is not "id".
     */
    protected function primaryKey(): string
    {
        return 'id';
    }

    /**
     * Create a fresh QueryBuilder instance.
     */
    protected function newQuery(): QueryBuilder
    {
        return new QueryBuilder($this->connection, $this->grammar, $this->executor);
    }

    /**
     * Base query for this repository's table.
     */
    protected function query(): QueryBuilder
    {
        return $this->newQuery()->from($this->table());
    }

    /**
     * Apply an optional scope closure to the query.
     *
     * @param callable(QueryBuilder):void|null $scope
     */
    protected function applyScope(QueryBuilder $query, ?callable $scope): QueryBuilder
    {
        if ($scope !== null) {
            $scope($query);
        }

        return $query;
    }

    /**
     * Get all rows for this table as a Collection.
     */
    public function all(array $columns = ['*']): Collection
    {
        $rows = $this->query()
          ->select($columns)
          ->get();

        return $this->results->process($rows);
    }

    /**
     * Get rows using an optional scoped query as a Collection.
     *
     * Example:
     *   $repo->get(fn (QueryBuilder $q) => $q->where('status', 'active'));
     *
     * @param callable(QueryBuilder):void|null $scope
     */
    public function get(?callable $scope = null, array $columns = ['*']): Collection
    {
        $query = $this->applyScope(
          $this->query()->select($columns),
          $scope
        );

        $rows = $query->get();

        return $this->results->process($rows);
    }

    /**
     * Get the first row matching an optional scoped query.
     *
     * Example:
     *   $user = $repo->first(fn ($q) => $q->where('email', $email));
     */
    public function first(?callable $scope = null, array $columns = ['*']): ?array
    {
        $query = $this->applyScope(
          $this->query()->select($columns),
          $scope
        );

        return $query->first();
    }

    /**
     * Find a row by primary key.
     */
    public function find(mixed $id, array $columns = ['*']): ?array
    {
        $key = $this->primaryKey();

        return $this->query()
          ->select($columns)
          ->where($key, '=', $id)
          ->first();
    }

    /**
     * Find multiple rows by primary key.
     */
    public function findMany(array $ids, array $columns = ['*']): Collection
    {
        if ($ids === []) {
            return $this->results->process([]);
        }

        $key = $this->primaryKey();

        $rows = $this->query()
          ->select($columns)
          ->whereIn($key, $ids)
          ->get();

        return $this->results->process($rows);
    }

    /**
     * Get a scalar value from the first row of a scoped query.
     *
     * Example:
     *   $total = $repo->value('amount', fn ($q) => $q->where('status', 'paid'));
     */
    public function value(string $column, ?callable $scope = null): mixed
    {
        $query = $this->applyScope(
          $this->query()->select([$column]),
          $scope
        );

        $rows = $query->get();

        return $this->results->processAggregate($rows);
    }

    /**
     * Pluck a single column into a flat array, optionally keyed by another column.
     *
     * Example:
     *   $emails = $repo->pluck('email');
     *   $namesById = $repo->pluck('name', 'id');
     *
     * @param callable(QueryBuilder):void|null $scope
     */
    public function pluck(string $column, ?string $keyColumn = null, ?callable $scope = null): array
    {
        $query = $this->applyScope(
          $this->query(),
          $scope
        );

        $rows = $query->get();

        if ($keyColumn === null) {
            return $this->results->processColumn($rows, $column);
        }

        return $this->results->processKeyValue($rows, $keyColumn, $column);
    }

    /**
     * Group results by a column into an array keyed by that column.
     *
     * Example:
     *   $grouped = $repo->groupByKey('status');
     *
     * @param callable(QueryBuilder):void|null $scope
     * @return array<string|int,array<int,array<string,mixed>>>
     */
    public function groupByKey(string $column, ?callable $scope = null): array
    {
        $query = $this->applyScope(
          $this->query(),
          $scope
        );

        $rows = $query->get();

        return $this->results->processGrouped($rows, $column);
    }

    /**
     * Count rows for an optional scoped query.
     */
    public function count(?callable $scope = null): int
    {
        // Use aggregate on a scoped builder to avoid leaking state
        $query = $this->applyScope(
          $this->query(),
          $scope
        );

        return $query->count();
    }

    /**
     * Check if any row exists for an optional scoped query.
     */
    public function exists(?callable $scope = null): bool
    {
        $query = $this->applyScope(
          $this->query(),
          $scope
        );

        return $query->exists();
    }

    /**
     * Get a ready-to-use QueryBuilder for advanced usage.
     *
     * This is an escape hatch when the repository helpers
     * are not enough:
     *
     *   $repo->builder()
     *       ->where('status', 'pending')
     *       ->orderByDesc('created_at')
     *       ->get();
     */
    public function builder(): QueryBuilder
    {
        return $this->query();
    }
}
