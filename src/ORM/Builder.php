<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\ORM;

use Infocyph\DBLayer\Exceptions\ModelException;
use Infocyph\DBLayer\Query\QueryBuilder;

/**
 * Model Query Builder
 *
 * Extends the base QueryBuilder with model-specific functionality:
 * - Eager loading relationships
 * - Model hydration
 * - Query scopes
 * - Soft delete support
 *
 * @package Infocyph\DBLayer\ORM
 * @author Hasan
 */
class Builder
{
    /**
     * All of the globally registered builder macros
     */
    protected static array $macros = [];

    /**
     * The relationships that should be eager loaded
     */
    protected array $eagerLoad = [];

    /**
     * All of the locally registered builder macros
     */
    protected array $localMacros = [];

    /**
     * The model being queried
     */
    protected ?Model $model = null;

    /**
     * The methods that should be returned from query builder
     */
    protected array $passthru = [
        'insert', 'insertGetId', 'update', 'delete', 'truncate',
        'exists', 'count', 'min', 'max', 'avg', 'sum'
    ];
    /**
     * The base query builder instance
     */
    protected QueryBuilder $query;

    /**
     * Removed global scopes
     */
    protected array $removedScopes = [];

    /**
     * Applied global scopes
     */
    protected array $scopes = [];

    /**
     * Create a new Model query builder instance
     */
    public function __construct(QueryBuilder $query)
    {
        $this->query = $query;
    }

    /**
     * Dynamically handle calls into the query instance
     */
    public function __call(string $method, array $parameters): mixed
    {
        if (in_array($method, $this->passthru)) {
            return $this->query->{$method}(...$parameters);
        }

        $this->query->{$method}(...$parameters);

        return $this;
    }

    /**
     * Force a clone of the underlying query builder when cloning
     */
    public function __clone()
    {
        $this->query = clone $this->query;
    }

    /**
     * Apply the scopes to the Eloquent builder instance and return it
     */
    public function applyScopes(): static
    {
        if (empty($this->scopes)) {
            return $this;
        }

        $builder = clone $this;

        foreach ($this->scopes as $identifier => $scope) {
            if (!isset($builder->scopes[$identifier])) {
                continue;
            }

            $scope($builder);
        }

        return $builder;
    }

    /**
     * Chunk the results of the query
     */
    public function chunk(int $count, callable $callback): bool
    {
        $this->enforceOrderBy();

        $page = 1;

        do {
            $results = $this->forPage($page, $count)->get();

            $countResults = $results->count();

            if ($countResults == 0) {
                break;
            }

            if ($callback($results, $page) === false) {
                return false;
            }

            unset($results);

            $page++;
        } while ($countResults == $count);

        return true;
    }

    /**
     * Create a new instance of the model being queried
     */
    public function create(array $attributes = []): Model
    {
        $model = $this->model->newInstance($attributes);
        $model->save();

        return $model;
    }

    /**
     * Execute a callback over each item while chunking
     */
    public function each(callable $callback, int $count = 1000): bool
    {
        return $this->chunk($count, function ($results) use ($callback) {
            foreach ($results as $key => $value) {
                if ($callback($value, $key) === false) {
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * Eager load the relationships for the models
     */
    public function eagerLoadRelations(array $models): array
    {
        foreach ($this->eagerLoad as $name => $constraints) {
            if (!str_contains($name, '.')) {
                $models = $this->eagerLoadRelation($models, $name, $constraints);
            }
        }

        return $models;
    }

    /**
     * Find a model by its primary key
     */
    public function find(mixed $id, array $columns = ['*']): ?Model
    {
        if (is_array($id)) {
            return $this->findMany($id, $columns);
        }

        return $this->whereKey($id)->first($columns);
    }

    /**
     * Find multiple models by their primary keys
     */
    public function findMany(array $ids, array $columns = ['*']): Collection
    {
        if (empty($ids)) {
            return $this->model->newCollection();
        }

        return $this->whereKey($ids)->get($columns);
    }

    /**
     * Find a model by its primary key or call a callback
     */
    public function findOr(mixed $id, $columns = ['*'], ?callable $callback = null): mixed
    {
        if ($columns instanceof \Closure) {
            $callback = $columns;
            $columns = ['*'];
        }

        if (!is_null($model = $this->find($id, $columns))) {
            return $model;
        }

        return $callback ? $callback() : null;
    }

    /**
     * Find a model by its primary key or throw an exception
     */
    public function findOrFail(mixed $id, array $columns = ['*']): Model
    {
        $result = $this->find($id, $columns);

        if (is_null($result)) {
            throw new ModelException("Model not found with ID: {$id}");
        }

        return $result;
    }

    /**
     * Execute the query and get the first result
     */
    public function first(array $columns = ['*']): ?Model
    {
        $results = $this->take(1)->get($columns);

        return $results->first();
    }

    /**
     * Execute the query and get the first result or call a callback
     */
    public function firstOr($columns = ['*'], ?callable $callback = null): mixed
    {
        if ($columns instanceof \Closure) {
            $callback = $columns;
            $columns = ['*'];
        }

        if (!is_null($model = $this->first($columns))) {
            return $model;
        }

        return $callback ? $callback() : null;
    }

    /**
     * Get the first record matching the attributes or create it
     */
    public function firstOrCreate(array $attributes = [], array $values = []): Model
    {
        if (!is_null($instance = $this->where($attributes)->first())) {
            return $instance;
        }

        return $this->create(array_merge($attributes, $values));
    }

    /**
     * Execute the query and get the first result or throw an exception
     */
    public function firstOrFail(array $columns = ['*']): Model
    {
        $model = $this->first($columns);

        if (is_null($model)) {
            throw new ModelException('No query results for model');
        }

        return $model;
    }

    /**
     * Get the first record matching the attributes or instantiate it
     */
    public function firstOrNew(array $attributes = [], array $values = []): Model
    {
        if (!is_null($instance = $this->where($attributes)->first())) {
            return $instance;
        }

        return $this->model->newInstance(array_merge($attributes, $values));
    }

    /**
     * Set the limit and offset for a given page
     */
    public function forPage(int $page, int $perPage = 15): static
    {
        return $this->offset(($page - 1) * $perPage)->limit($perPage);
    }

    /**
     * Execute the query as a "select" statement
     */
    public function get(array $columns = ['*']): Collection
    {
        $builder = $this->applyScopes();

        $models = $builder->getModels($columns);

        if (count($models) > 0) {
            $models = $builder->eagerLoadRelations($models);
        }

        return $builder->model->newCollection($models);
    }

    /**
     * Get the model instance being queried
     */
    public function getModel(): Model
    {
        return $this->model;
    }

    /**
     * Get the hydrated models without eager loading
     */
    public function getModels(array $columns = ['*']): array
    {
        $results = $this->query->get($columns);

        return $this->hydrate($results);
    }

    /**
     * Get the underlying query builder instance
     */
    public function getQuery(): QueryBuilder
    {
        return $this->query;
    }

    /**
     * Get the relation instance for the given relation name
     */
    public function getRelation(string $name): Relations\Relation
    {
        $relation = $this->model->{$name}();

        if (!$relation instanceof Relations\Relation) {
            throw new ModelException(
                sprintf('%s::%s must return a relationship instance.', get_class($this->model), $name)
            );
        }

        return $relation;
    }

    /**
     * Create a collection of models from plain arrays
     */
    public function hydrate(array $items): array
    {
        $instance = $this->model->newInstance();

        return array_map(function ($item) use ($instance) {
            return $instance->newFromBuilder($item);
        }, $items);
    }

    /**
     * Put the query's results in random order
     */
    public function inRandomOrder(?string $seed = null): static
    {
        $this->query->inRandomOrder($seed);

        return $this;
    }

    /**
     * Set the "limit" value of the query
     */
    public function limit(int $value): static
    {
        $this->query->limit($value);

        return $this;
    }

    /**
     * Save a new model and return the instance
     */
    public function make(array $attributes = []): Model
    {
        return $this->model->newInstance($attributes);
    }

    /**
     * Set the "offset" value of the query
     */
    public function offset(int $value): static
    {
        $this->query->offset($value);

        return $this;
    }

    /**
     * Add an "order by" clause to the query
     */
    public function orderBy(string $column, string $direction = 'asc'): static
    {
        $this->query->orderBy($column, $direction);

        return $this;
    }

    /**
     * Add a descending "order by" clause to the query
     */
    public function orderByDesc(string $column): static
    {
        return $this->orderBy($column, 'desc');
    }

    /**
     * Add an "or where" clause to the query
     */
    public function orWhere($column, $operator = null, $value = null): static
    {
        return $this->where($column, $operator, $value, 'or');
    }

    /**
     * Paginate the given query
     */
    public function paginate(int $perPage = 15, array $columns = ['*'], int $page = 1): array
    {
        $total = $this->query->count();

        $results = $this->forPage($page, $perPage)->get($columns);

        return [
            'data' => $results,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => max((int) ceil($total / $perPage), 1),
            'from' => (($page - 1) * $perPage) + 1,
            'to' => min($page * $perPage, $total),
        ];
    }

    /**
     * Set the model instance for the model being queried
     */
    public function setModel(Model $model): static
    {
        $this->model = $model;

        return $this;
    }

    /**
     * Set the underlying query builder instance
     */
    public function setQuery(QueryBuilder $query): static
    {
        $this->query = $query;

        return $this;
    }

    /**
     * Get a paginator only supporting simple next and previous links
     */
    public function simplePaginate(int $perPage = 15, array $columns = ['*'], int $page = 1): array
    {
        $results = $this->forPage($page, $perPage + 1)->get($columns);

        return [
            'data' => $results->take($perPage),
            'per_page' => $perPage,
            'current_page' => $page,
            'has_more' => $results->count() > $perPage,
        ];
    }

    /**
     * Alias to set the "offset" value of the query
     */
    public function skip(int $value): static
    {
        return $this->offset($value);
    }

    /**
     * Alias to set the "limit" value of the query
     */
    public function take(int $value): static
    {
        return $this->limit($value);
    }

    /**
     * Create or update a record matching the attributes, and fill it with values
     */
    public function updateOrCreate(array $attributes, array $values = []): Model
    {
        $instance = $this->firstOrNew($attributes);

        $instance->fill($values)->save();

        return $instance;
    }

    /**
     * Add a basic where clause to the query
     */
    public function where($column, $operator = null, $value = null, string $boolean = 'and'): static
    {
        if ($column instanceof \Closure) {
            $column($query = $this->model->newQueryWithoutRelationships());

            $this->query->addNestedWhereQuery($query->getQuery(), $boolean);

            return $this;
        }

        $this->query->where($column, $operator, $value, $boolean);

        return $this;
    }

    /**
     * Add a "where in" clause to the query
     */
    public function whereIn(string $column, mixed $values, string $boolean = 'and', bool $not = false): static
    {
        $this->query->whereIn($column, $values, $boolean, $not);

        return $this;
    }

    /**
     * Add a where clause on the primary key to the query
     */
    public function whereKey(mixed $id): static
    {
        if (is_array($id)) {
            $this->query->whereIn($this->model->getKeyName(), $id);
            return $this;
        }

        return $this->where($this->model->getKeyName(), '=', $id);
    }

    /**
     * Add a where clause on the primary key to the query
     */
    public function whereKeyNot(mixed $id): static
    {
        if (is_array($id)) {
            $this->query->whereNotIn($this->model->getKeyName(), $id);
            return $this;
        }

        return $this->where($this->model->getKeyName(), '!=', $id);
    }

    /**
     * Add a "where not in" clause to the query
     */
    public function whereNotIn(string $column, mixed $values, string $boolean = 'and'): static
    {
        return $this->whereIn($column, $values, $boolean, true);
    }

    /**
     * Add a "where not null" clause to the query
     */
    public function whereNotNull(string|array $columns, string $boolean = 'and'): static
    {
        return $this->whereNull($columns, $boolean, true);
    }

    /**
     * Add a "where null" clause to the query
     */
    public function whereNull(string|array $columns, string $boolean = 'and', bool $not = false): static
    {
        $this->query->whereNull($columns, $boolean, $not);

        return $this;
    }

    /**
     * Set the relationships that should be eager loaded
     */
    public function with(string|array $relations, $callback = null): static
    {
        if (is_string($relations)) {
            $relations = [$relations => $callback ?? static fn () => null];
        } elseif (is_array($relations)) {
            $relations = array_map(
                fn ($value) => is_callable($value) ? $value : static fn () => null,
                $relations
            );
        }

        $this->eagerLoad = array_merge($this->eagerLoad, $relations);

        return $this;
    }

    /**
     * Register a new global scope
     */
    public function withGlobalScope(string $identifier, $scope): static
    {
        $this->scopes[$identifier] = $scope;

        return $this;
    }

    /**
     * Prevent the specified relations from being eager loaded
     */
    public function without(string|array $relations): static
    {
        $this->eagerLoad = array_diff_key(
            $this->eagerLoad,
            array_flip(is_string($relations) ? func_get_args() : $relations)
        );

        return $this;
    }

    /**
     * Remove a registered global scope
     */
    public function withoutGlobalScope(string $scope): static
    {
        unset($this->scopes[$scope]);

        $this->removedScopes[] = $scope;

        return $this;
    }

    /**
     * Remove all or passed registered global scopes
     */
    public function withoutGlobalScopes(?array $scopes = null): static
    {
        if (is_array($scopes)) {
            foreach ($scopes as $scope) {
                $this->withoutGlobalScope($scope);
            }
        } else {
            $this->scopes = [];
        }

        return $this;
    }

    /**
     * Eagerly load the relationship on a set of models
     */
    protected function eagerLoadRelation(array $models, string $name, \Closure $constraints): array
    {
        $relation = $this->getRelation($name);

        $relation->addEagerConstraints($models);

        $constraints($relation);

        return $relation->match(
            $relation->initRelation($models, $name),
            $relation->getEager(),
            $name
        );
    }

    /**
     * Enforce that an order by clause exists on the query
     */
    protected function enforceOrderBy(): void
    {
        if (empty($this->query->orders) && empty($this->query->unionOrders)) {
            $this->orderBy($this->model->getKeyName(), 'asc');
        }
    }
}