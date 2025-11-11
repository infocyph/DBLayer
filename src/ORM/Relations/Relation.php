<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\ORM\Relations;

use Infocyph\DBLayer\ORM\Builder;
use Infocyph\DBLayer\ORM\Collection;
use Infocyph\DBLayer\ORM\Model;

/**
 * Base Relation Class
 *
 * Abstract base class for all model relationships.
 * Provides common functionality for relationship queries and constraints.
 *
 * @package Infocyph\DBLayer\ORM\Relations
 * @author Hasan
 */
abstract class Relation
{
    /**
     * Indicates if the relation is adding constraints
     */
    protected static bool $constraints = true;

    /**
     * The parent model instance
     */
    protected Model $parent;
    /**
     * The Model query builder instance
     */
    protected Builder $query;

    /**
     * The related model instance
     */
    protected Model $related;

    /**
     * Create a new relation instance
     */
    public function __construct(Builder $query, Model $parent)
    {
        $this->query = $query;
        $this->parent = $parent;
        $this->related = $query->getModel();

        $this->addConstraints();
    }

    /**
     * Handle dynamic method calls to the relationship
     */
    public function __call(string $method, array $parameters): mixed
    {
        if (method_exists($this->query, $method)) {
            $result = $this->query->$method(...$parameters);

            if ($result === $this->query) {
                return $this;
            }

            return $result;
        }

        throw new \BadMethodCallException(
            sprintf('Call to undefined method %s::%s()', static::class, $method)
        );
    }

    /**
     * Clone the relationship query
     */
    public function __clone()
    {
        $this->query = clone $this->query;
    }

    /**
     * Set the base constraints on the relation query
     */
    abstract public function addConstraints(): void;

    /**
     * Set the constraints for an eager load of the relation
     */
    abstract public function addEagerConstraints(array $models): void;

    /**
     * Get the results of the relationship
     */
    abstract public function getResults(): mixed;

    /**
     * Initialize the relation on a set of models
     */
    abstract public function initRelation(array $models, string $relation): array;

    /**
     * Match the eagerly loaded results to their parents
     */
    abstract public function match(array $models, Collection $results, string $relation): array;

    /**
     * Run a callback with constraints disabled on the relation
     */
    public static function noConstraints(callable $callback): mixed
    {
        $previous = static::$constraints;

        static::$constraints = false;

        try {
            return $callback();
        } finally {
            static::$constraints = $previous;
        }
    }

    /**
     * Get the name of the "created at" column
     */
    public function createdAt(): string
    {
        return $this->parent->getCreatedAtColumn();
    }

    /**
     * Execute the query and get the related model(s)
     */
    public function get(array $columns = ['*']): mixed
    {
        return $this->query->get($columns);
    }

    /**
     * Get the base query builder driving the Model builder
     */
    public function getBaseQuery(): \Infocyph\DBLayer\Query\QueryBuilder
    {
        return $this->query->getQuery();
    }

    /**
     * Execute the query as a "select" statement
     */
    public function getEager(): Collection
    {
        return $this->get();
    }

    /**
     * Get the relationship for eager loading
     */
    public function getEagerLoadRelation(string $name): static
    {
        return $this;
    }

    /**
     * Get the foreign key column name
     */
    public function getForeignKeyName(): string
    {
        return $this->foreignKey ?? $this->parent->getForeignKey();
    }

    /**
     * Get the parent model of the relation
     */
    public function getParent(): Model
    {
        return $this->parent;
    }

    /**
     * Get the fully qualified foreign key for the relation
     */
    public function getQualifiedForeignKeyName(): string
    {
        return $this->related->getTable() . '.' . $this->getForeignKeyName();
    }

    /**
     * Get the fully qualified parent key name
     */
    public function getQualifiedParentKeyName(): string
    {
        return $this->parent->getTable() . '.' . $this->parent->getKeyName();
    }

    /**
     * Get the underlying query for the relation
     */
    public function getQuery(): Builder
    {
        return $this->query;
    }

    /**
     * Get the related model of the relation
     */
    public function getRelated(): Model
    {
        return $this->related;
    }

    /**
     * Get a relationship join table hash
     */
    public function getRelationExistenceCountQuery(Builder $query, Builder $parentQuery): Builder
    {
        return $query->whereColumn(
            $this->getQualifiedParentKeyName(),
            '=',
            $this->getQualifiedForeignKeyName()
        );
    }

    /**
     * Run a raw update query against the related model
     */
    public function rawUpdate(array $attributes = []): int
    {
        return $this->query->getQuery()->update($attributes);
    }

    /**
     * Touch all of the related models for the relationship
     */
    public function touch(): void
    {
        if ($this->related->usesTimestamps()) {
            $this->rawUpdate([
                $this->related->getUpdatedAtColumn() => $this->related->freshTimestampString()
            ]);
        }
    }

    /**
     * Get the name of the "updated at" column
     */
    public function updatedAt(): string
    {
        return $this->parent->getUpdatedAtColumn();
    }

    /**
     * Get all of the primary keys for an array of models
     */
    protected function getKeys(array $models, ?string $key = null): array
    {
        return array_unique(array_values(array_map(function ($value) use ($key) {
            return $key ? $value->getAttribute($key) : $value->getKey();
        }, $models)));
    }

    /**
     * Get the key value of the parent's local key
     */
    protected function getParentKey(): mixed
    {
        return $this->parent->getAttribute($this->localKey);
    }

    /**
     * Get the query builder for the relationship query
     */
    protected function getRelationQuery(): Builder
    {
        return $this->query;
    }

    /**
     * Determine if the model key name uses a custom format
     */
    protected function usesTimestamps(): bool
    {
        return $this->related->usesTimestamps();
    }
}
