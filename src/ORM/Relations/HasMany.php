<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\ORM\Relations;

use Infocyph\DBLayer\ORM\Builder;
use Infocyph\DBLayer\ORM\Collection;
use Infocyph\DBLayer\ORM\Model;

/**
 * Has Many Relation
 *
 * Represents a one-to-many relationship where the parent model
 * has multiple related models.
 *
 * @package Infocyph\DBLayer\ORM\Relations
 * @author Hasan
 */
class HasMany extends Relation
{
    /**
     * The foreign key of the parent model
     */
    protected string $foreignKey;

    /**
     * The local key of the parent model
     */
    protected string $localKey;

    /**
     * Create a new has many relationship instance
     */
    public function __construct(Builder $query, Model $parent, string $foreignKey, string $localKey)
    {
        $this->foreignKey = $foreignKey;
        $this->localKey = $localKey;

        parent::__construct($query, $parent);
    }

    /**
     * Set the base constraints on the relation query
     */
    public function addConstraints(): void
    {
        if (static::$constraints) {
            $this->query->where($this->foreignKey, '=', $this->getParentKey());
        }
    }

    /**
     * Set the constraints for an eager load of the relation
     */
    public function addEagerConstraints(array $models): void
    {
        $this->query->whereIn(
            $this->foreignKey,
            $this->getKeys($models, $this->localKey)
        );
    }

    /**
     * Create a new instance of the related model
     */
    public function create(array $attributes = []): Model
    {
        return tap($this->related->newInstance($attributes), function ($instance) {
            $instance->setAttribute($this->foreignKey, $this->getParentKey());
            $instance->save();
        });
    }

    /**
     * Create a Collection of new instances of the related model
     */
    public function createMany(array $records): Collection
    {
        $instances = $this->related->newCollection();

        foreach ($records as $record) {
            $instances->push($this->create($record));
        }

        return $instances;
    }

    /**
     * Get the plain foreign key
     */
    public function getForeignKeyName(): string
    {
        return $this->foreignKey;
    }

    /**
     * Get the fully qualified foreign key for the relation
     */
    public function getQualifiedForeignKeyName(): string
    {
        return $this->related->getTable() . '.' . $this->foreignKey;
    }

    /**
     * Get the results of the relationship
     */
    public function getResults(): Collection
    {
        return $this->query->get();
    }

    /**
     * Initialize the relation on a set of models
     */
    public function initRelation(array $models, string $relation): array
    {
        foreach ($models as $model) {
            $model->setRelation($relation, $this->related->newCollection());
        }

        return $models;
    }

    /**
     * Match the eagerly loaded results to their parents
     */
    public function match(array $models, Collection $results, string $relation): array
    {
        $dictionary = $this->buildDictionary($results);

        foreach ($models as $model) {
            $key = $model->getAttribute($this->localKey);

            if (isset($dictionary[$key])) {
                $model->setRelation(
                    $relation,
                    $this->related->newCollection($dictionary[$key])
                );
            }
        }

        return $models;
    }

    /**
     * Build model dictionary keyed by the relation's foreign key
     */
    protected function buildDictionary(Collection $results): array
    {
        $dictionary = [];

        foreach ($results as $result) {
            $dictionary[$result->{$this->foreignKey}][] = $result;
        }

        return $dictionary;
    }

    /**
     * Get the key value of the parent's local key
     */
    protected function getParentKey(): mixed
    {
        return $this->parent->getAttribute($this->localKey);
    }
}

/**
 * Helper function for tap
 */
function tap($value, $callback = null)
{
    if (is_null($callback)) {
        return $value;
    }

    $callback($value);

    return $value;
}
