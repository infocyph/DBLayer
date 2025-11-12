<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\ORM\Concerns;

use Infocyph\DBLayer\ORM\Relations\BelongsTo;
use Infocyph\DBLayer\ORM\Relations\BelongsToMany;
use Infocyph\DBLayer\ORM\Relations\HasMany;
use Infocyph\DBLayer\ORM\Relations\HasOne;

/**
 * Has Relationships Trait
 *
 * Provides relationship management for models
 *
 * @package Infocyph\DBLayer\ORM\Concerns
 * @author Hasan
 */
trait HasRelationships
{
    protected array $relations = [];
    protected array $touches = [];

    public function belongsTo(string $related, ?string $foreignKey = null, ?string $ownerKey = null, ?string $relation = null): BelongsTo
    {
        $instance = new $related();
        $foreignKey = $foreignKey ?: strtolower(class_basename($related)) . '_id';
        $ownerKey = $ownerKey ?: $instance->getKeyName();
        $relation = $relation ?: debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'];

        return new BelongsTo($instance->newQuery(), $this, $foreignKey, $ownerKey, $relation);
    }

    public function belongsToMany(
        string $related,
        ?string $table = null,
        ?string $foreignPivotKey = null,
        ?string $relatedPivotKey = null,
        ?string $parentKey = null,
        ?string $relatedKey = null
    ): BelongsToMany {
        $instance = new $related();
        $foreignPivotKey = $foreignPivotKey ?: $this->getForeignKey();
        $relatedPivotKey = $relatedPivotKey ?: $instance->getForeignKey();
        $table = $table ?: $this->joiningTable($related);
        $parentKey = $parentKey ?: $this->getKeyName();
        $relatedKey = $relatedKey ?: $instance->getKeyName();

        return new BelongsToMany(
            $instance->newQuery(),
            $this,
            $table,
            $foreignPivotKey,
            $relatedPivotKey,
            $parentKey,
            $relatedKey
        );
    }

    public function getForeignKey(): string
    {
        return strtolower(class_basename($this)) . '_' . $this->getKeyName();
    }

    public function getRelation(string $relation): mixed
    {
        return $this->relations[$relation] ?? null;
    }

    public function getRelations(): array
    {
        return $this->relations;
    }

    public function hasMany(string $related, ?string $foreignKey = null, ?string $localKey = null): HasMany
    {
        $instance = new $related();
        $foreignKey = $foreignKey ?: $this->getForeignKey();
        $localKey = $localKey ?: $this->getKeyName();

        return new HasMany($instance->newQuery(), $this, $foreignKey, $localKey);
    }

    public function hasOne(string $related, ?string $foreignKey = null, ?string $localKey = null): HasOne
    {
        $instance = new $related();
        $foreignKey = $foreignKey ?: $this->getForeignKey();
        $localKey = $localKey ?: $this->getKeyName();

        return new HasOne($instance->newQuery(), $this, $foreignKey, $localKey);
    }

    public function newFromBuilder(array $attributes = [], ?string $connection = null): static
    {
        $model = $this->newInstance([], true);
        $model->setRawAttributes((array) $attributes, true);

        return $model;
    }

    public function newQueryWithoutRelationships(): \Infocyph\DBLayer\ORM\Builder
    {
        return $this->newQuery();
    }

    public function relationLoaded(string $key): bool
    {
        return array_key_exists($key, $this->relations);
    }

    public function relationsToArray(): array
    {
        $attributes = [];

        foreach ($this->getArrayableRelations() as $key => $value) {
            if ($value instanceof \Infocyph\DBLayer\ORM\Collection) {
                $relation = $value->toArray();
            } elseif ($value instanceof \Infocyph\DBLayer\ORM\Model) {
                $relation = $value->toArray();
            } else {
                $relation = $value;
            }

            if (!is_null($relation)) {
                $attributes[$key] = $relation;
            }
        }

        return $attributes;
    }

    public function setRelation(string $relation, mixed $value): static
    {
        $this->relations[$relation] = $value;

        return $this;
    }

    public function setRelations(array $relations): static
    {
        $this->relations = $relations;

        return $this;
    }

    public function touches(string $relation): bool
    {
        return in_array($relation, $this->touches);
    }

    public function unsetRelation(string $relation): static
    {
        unset($this->relations[$relation]);

        return $this;
    }

    protected function getArrayableRelations(): array
    {
        return $this->getArrayableItems($this->relations);
    }

    protected function getRelationValue(string $key): mixed
    {
        if ($this->relationLoaded($key)) {
            return $this->relations[$key];
        }

        if (!method_exists($this, $key)) {
            return null;
        }

        $relation = $this->$key();

        if (!$relation instanceof \Infocyph\DBLayer\ORM\Relations\Relation) {
            throw new \LogicException(
                sprintf('%s::%s must return a relationship instance.', static::class, $key)
            );
        }

        $results = $relation->getResults();
        $this->setRelation($key, $results);

        return $results;
    }

    protected function joiningTable(string $related): string
    {
        $models = [
            strtolower(class_basename($this)),
            strtolower(class_basename($related)),
        ];

        sort($models);

        return implode('_', $models);
    }
}