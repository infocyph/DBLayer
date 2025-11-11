<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\ORM\Relations;

use Infocyph\DBLayer\ORM\Builder;
use Infocyph\DBLayer\ORM\Collection;
use Infocyph\DBLayer\ORM\Model;

/**
 * Belongs To Relation
 *
 * Represents the inverse of a one-to-one or one-to-many relationship.
 * The child model belongs to a parent model.
 *
 * @package Infocyph\DBLayer\ORM\Relations
 * @author Hasan
 */
class BelongsTo extends Relation
{
    /**
     * The child model instance of the relation
     */
    protected Model $child;

    /**
     * The foreign key of the parent model
     */
    protected string $foreignKey;

    /**
     * The associated key on the parent model
     */
    protected string $ownerKey;

    /**
     * The name of the relationship
     */
    protected string $relationName;

    /**
     * Create a new belongs to relationship instance
     */
    public function __construct(
        Builder $query,
        Model $child,
        string $foreignKey,
        string $ownerKey,
        string $relationName
    ) {
        $this->ownerKey = $ownerKey;
        $this->relationName = $relationName;
        $this->foreignKey = $foreignKey;
        $this->child = $child;

        parent::__construct($query, $child);
    }

    /**
     * Set the base constraints on the relation query
     */
    public function addConstraints(): void
    {
        if (static::$constraints) {
            $this->query->where($this->ownerKey, '=', $this->child->{$this->foreignKey});
        }
    }

    /**
     * Set the constraints for an eager load of the relation
     */
    public function addEagerConstraints(array $models): void
    {
        $key = $this->related->getTable() . '.' . $this->ownerKey;

        $whereIn = $this->whereInMethod($this->related, $this->ownerKey);

        $this->query->{$whereIn}($key, $this->getEagerModelKeys($models));
    }

    /**
     * Associate the model instance to the given parent
     */
    public function associate(?Model $model): Model
    {
        $this->child->setAttribute($this->foreignKey, $model?->getAttribute($this->ownerKey));

        if ($model) {
            $this->child->setRelation($this->relationName, $model);
        } else {
            $this->child->unsetRelation($this->relationName);
        }

        return $this->child;
    }

    /**
     * Dissociate previously associated model from the given parent
     */
    public function dissociate(): Model
    {
        $this->child->setAttribute($this->foreignKey, null);
        $this->child->unsetRelation($this->relationName);

        return $this->child;
    }

    /**
     * Get the plain foreign key
     */
    public function getForeignKeyName(): string
    {
        return $this->foreignKey;
    }

    /**
     * Get the associated key of the relationship
     */
    public function getOwnerKeyName(): string
    {
        return $this->ownerKey;
    }

    /**
     * Get the fully qualified foreign key of the relationship
     */
    public function getQualifiedForeignKeyName(): string
    {
        return $this->child->getTable() . '.' . $this->foreignKey;
    }

    /**
     * Get the fully qualified associated key of the relationship
     */
    public function getQualifiedOwnerKeyName(): string
    {
        return $this->related->getTable() . '.' . $this->ownerKey;
    }

    /**
     * Get the name of the relationship
     */
    public function getRelationName(): string
    {
        return $this->relationName;
    }

    /**
     * Get the results of the relationship
     */
    public function getResults(): ?Model
    {
        if (is_null($this->child->{$this->foreignKey})) {
            return null;
        }

        return $this->query->first();
    }

    /**
     * Initialize the relation on a set of models
     */
    public function initRelation(array $models, string $relation): array
    {
        foreach ($models as $model) {
            $model->setRelation($relation, null);
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
            $key = $model->{$this->foreignKey};

            if (isset($dictionary[$key])) {
                $model->setRelation($relation, $dictionary[$key]);
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
            $dictionary[$result->{$this->ownerKey}] = $result;
        }

        return $dictionary;
    }

    /**
     * Gather the keys from an array of related models
     */
    protected function getEagerModelKeys(array $models): array
    {
        $keys = [];

        foreach ($models as $model) {
            $value = $model->{$this->foreignKey};

            if (!is_null($value)) {
                $keys[] = $value;
            }
        }

        sort($keys);

        return array_values(array_unique($keys));
    }

    /**
     * Determine which WhereIn method to use
     */
    protected function whereInMethod(Model $model, string $key): string
    {
        return 'whereIn';
    }
}
