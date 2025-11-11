<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\ORM\Relations;

use Infocyph\DBLayer\ORM\Builder;
use Infocyph\DBLayer\ORM\Collection;
use Infocyph\DBLayer\ORM\Model;

/**
 * Belongs To Many Relation
 *
 * Represents a many-to-many relationship using a pivot/junction table.
 *
 * @package Infocyph\DBLayer\ORM\Relations
 * @author Hasan
 */
class BelongsToMany extends Relation
{
    /**
     * The foreign key of the parent model
     */
    protected string $foreignPivotKey;

    /**
     * The parent key
     */
    protected string $parentKey;

    /**
     * The pivot table columns to retrieve
     */
    protected array $pivotColumns = [];

    /**
     * The custom pivot table column for the created_at timestamp
     */
    protected ?string $pivotCreatedAt = null;

    /**
     * The custom pivot table column for the updated_at timestamp
     */
    protected ?string $pivotUpdatedAt = null;

    /**
     * Any pivot table restrictions
     */
    protected array $pivotWheres = [];

    /**
     * The related key
     */
    protected string $relatedKey;

    /**
     * The associated key of the relation
     */
    protected string $relatedPivotKey;
    /**
     * The intermediate table for the relation
     */
    protected string $table;

    /**
     * Create a new belongs to many relationship instance
     */
    public function __construct(
        Builder $query,
        Model $parent,
        string $table,
        string $foreignPivotKey,
        string $relatedPivotKey,
        string $parentKey,
        string $relatedKey
    ) {
        $this->table = $table;
        $this->foreignPivotKey = $foreignPivotKey;
        $this->relatedPivotKey = $relatedPivotKey;
        $this->parentKey = $parentKey;
        $this->relatedKey = $relatedKey;

        parent::__construct($query, $parent);
    }

    /**
     * Set the base constraints on the relation query
     */
    public function addConstraints(): void
    {
        $this->performJoin();

        if (static::$constraints) {
            $this->addWhereConstraints();
        }
    }

    /**
     * Set the constraints for an eager load of the relation
     */
    public function addEagerConstraints(array $models): void
    {
        $whereIn = $this->whereInMethod($this->parent, $this->parentKey);

        $this->query->{$whereIn}(
            $this->getQualifiedForeignPivotKeyName(),
            $this->getKeys($models, $this->parentKey)
        );
    }

    /**
     * Attach a model to the parent
     */
    public function attach(mixed $id, array $attributes = [], bool $touch = true): void
    {
        $this->parent->getConnection()->table($this->table)->insert(
            $this->formatAttachRecords(
                $this->parseIds($id),
                $attributes
            )
        );

        if ($touch) {
            $this->touchIfTouching();
        }
    }

    /**
     * Detach models from the relationship
     */
    public function detach(mixed $ids = null, bool $touch = true): int
    {
        $query = $this->parent->getConnection()->table($this->table)
            ->where($this->foreignPivotKey, $this->parent->{$this->parentKey});

        if (!is_null($ids)) {
            $query->whereIn($this->relatedPivotKey, $this->parseIds($ids));
        }

        $results = $query->delete();

        if ($touch) {
            $this->touchIfTouching();
        }

        return $results;
    }

    /**
     * Execute the query and get all related models
     */
    public function get(array $columns = ['*']): Collection
    {
        $builder = $this->query->applyScopes();

        $columns = $builder->getQuery()->columns ? [] : $columns;

        $models = $builder->addSelect(
            $this->shouldSelect($columns)
        )->getModels();

        $this->hydratePivotRelation($models);

        if (count($models) > 0) {
            $models = $builder->eagerLoadRelations($models);
        }

        return $this->related->newCollection($models);
    }

    /**
     * Get the fully qualified foreign key for the relation
     */
    public function getQualifiedForeignPivotKeyName(): string
    {
        return $this->table . '.' . $this->foreignPivotKey;
    }

    /**
     * Get the fully qualified "related key" for the relation
     */
    public function getQualifiedRelatedPivotKeyName(): string
    {
        return $this->table . '.' . $this->relatedPivotKey;
    }

    /**
     * Get the results of the relationship
     */
    public function getResults(): Collection
    {
        return $this->get();
    }

    /**
     * Get the intermediate table for the relationship
     */
    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * Determine if the given column is defined as a pivot column
     */
    public function hasPivotColumn(string $column): bool
    {
        return in_array($column, $this->pivotColumns);
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
            $key = $model->{$this->parentKey};

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
     * Sync the intermediate tables with a list of IDs or collection of models
     */
    public function sync(mixed $ids, bool $detaching = true): array
    {
        $changes = [
            'attached' => [],
            'detached' => [],
            'updated' => [],
        ];

        $current = $this->getCurrentlyAttachedPivots()
            ->pluck($this->relatedPivotKey)->all();

        $records = $this->formatRecordsList($this->parseIds($ids));

        $detach = array_diff($current, array_keys($records));

        if ($detaching && count($detach) > 0) {
            $this->detach($detach);
            $changes['detached'] = $detach;
        }

        $changes = array_merge(
            $changes,
            $this->attachNew($records, $current, false)
        );

        if (count($changes['attached']) || count($changes['updated']) || count($changes['detached'])) {
            $this->touchIfTouching();
        }

        return $changes;
    }

    /**
     * Specify pivot columns to retrieve
     */
    public function withPivot(string|array $columns): static
    {
        $this->pivotColumns = array_merge(
            $this->pivotColumns,
            is_array($columns) ? $columns : func_get_args()
        );

        return $this;
    }

    /**
     * Specify that the pivot table has creation and update timestamps
     */
    public function withTimestamps(?string $createdAt = null, ?string $updatedAt = null): static
    {
        $this->pivotCreatedAt = $createdAt;
        $this->pivotUpdatedAt = $updatedAt;

        return $this->withPivot($this->createdAt(), $this->updatedAt());
    }

    /**
     * Add timestamps to the attach record
     */
    protected function addTimestampsToAttachment(array $record, bool $exists = false): array
    {
        $fresh = $this->parent->freshTimestamp();

        if (!$exists && $this->hasPivotColumn($this->createdAt())) {
            $record[$this->createdAt()] = $fresh;
        }

        if ($this->hasPivotColumn($this->updatedAt())) {
            $record[$this->updatedAt()] = $fresh;
        }

        return $record;
    }

    /**
     * Set the where clause for the relation query
     */
    protected function addWhereConstraints(): static
    {
        $this->query->where(
            $this->getQualifiedForeignPivotKeyName(),
            '=',
            $this->parent->{$this->parentKey}
        );

        return $this;
    }

    /**
     * Get the pivot columns for the relation
     */
    protected function aliasedPivotColumns(): array
    {
        $defaults = [$this->foreignPivotKey, $this->relatedPivotKey];

        return array_map(function ($column) {
            return $this->table . '.' . $column . ' as pivot_' . $column;
        }, array_merge($defaults, $this->pivotColumns));
    }

    /**
     * Attach all new models
     */
    protected function attachNew(array $records, array $current, bool $touch = true): array
    {
        $changes = ['attached' => [], 'updated' => []];

        foreach ($records as $id => $attributes) {
            if (!in_array($id, $current)) {
                $this->attach($id, $attributes, $touch);
                $changes['attached'][] = $id;
            }
        }

        return $changes;
    }

    /**
     * Create the base attach record
     */
    protected function baseAttachRecord(mixed $id, bool $timed): array
    {
        $record = [
            $this->relatedPivotKey => $id,
            $this->foreignPivotKey => $this->parent->{$this->parentKey},
        ];

        if ($timed) {
            $record = $this->addTimestampsToAttachment($record);
        }

        return $record;
    }

    /**
     * Build model dictionary keyed by the relation's foreign key
     */
    protected function buildDictionary(Collection $results): array
    {
        $dictionary = [];

        foreach ($results as $result) {
            $dictionary[$result->pivot->{$this->foreignPivotKey}][] = $result;
        }

        return $dictionary;
    }

    /**
     * Format a single attach record
     */
    protected function formatAttachRecord(mixed $id, array $attributes, bool $hasTimestamps): array
    {
        $record = array_merge(
            $this->baseAttachRecord($id, $hasTimestamps),
            $attributes
        );

        return $record;
    }

    /**
     * Format the records for attaching
     */
    protected function formatAttachRecords(array $ids, array $attributes): array
    {
        $records = [];

        $hasTimestamps = $this->hasPivotColumn($this->createdAt());

        foreach ($ids as $id) {
            $records[] = $this->formatAttachRecord(
                $id,
                $attributes,
                $hasTimestamps
            );
        }

        return $records;
    }

    /**
     * Format the sync / toggle record list
     */
    protected function formatRecordsList(array $records): array
    {
        return collect($records)->mapWithKeys(function ($attributes, $id) {
            if (!is_array($attributes)) {
                [$id, $attributes] = [$attributes, []];
            }

            return [$id => $attributes];
        })->all();
    }

    /**
     * Get the currently attached pivot models
     */
    protected function getCurrentlyAttachedPivots(): Collection
    {
        return $this->parent->getConnection()->table($this->table)
            ->where($this->foreignPivotKey, $this->parent->{$this->parentKey})
            ->get();
    }

    /**
     * Attempt to guess the name of the inverse of the relation
     */
    protected function guessInverseRelation(): string
    {
        return strtolower(class_basename($this->getParent()));
    }

    /**
     * Hydrate the pivot table relationship on the models
     */
    protected function hydratePivotRelation(array $models): void
    {
        foreach ($models as $model) {
            $model->setRelation('pivot', $this->newExistingPivot(
                $this->migratePivotAttributes($model)
            ));
        }
    }

    /**
     * Get the pivot attributes from a model
     */
    protected function migratePivotAttributes(Model $model): array
    {
        $values = [];

        foreach ($model->getAttributes() as $key => $value) {
            if (str_starts_with($key, 'pivot_')) {
                $values[substr($key, 6)] = $value;
                unset($model->{$key});
            }
        }

        return $values;
    }

    /**
     * Create a new pivot model instance
     */
    protected function newExistingPivot(array $attributes = []): Model
    {
        $pivot = new class () extends Model {
            public function __construct(array $attributes = [])
            {
                parent::__construct($attributes);
                $this->exists = true;
            }
        };

        $pivot->fill($attributes);
        $pivot->exists = true;

        return $pivot;
    }

    /**
     * Parse the ID
     */
    protected function parseIds(mixed $value): array
    {
        if ($value instanceof Model) {
            return [$value->{$this->relatedKey}];
        }

        if ($value instanceof Collection) {
            return $value->pluck($this->relatedKey)->all();
        }

        return (array) $value;
    }

    /**
     * Perform the join to the pivot table
     */
    protected function performJoin(?Builder $query = null): void
    {
        $query = $query ?: $this->query;

        $baseTable = $this->related->getTable();

        $key = $baseTable . '.' . $this->relatedKey;

        $query->join($this->table, $key, '=', $this->getQualifiedRelatedPivotKeyName());
    }

    /**
     * Get the select columns for the relation query
     */
    protected function shouldSelect(array $columns = ['*']): array
    {
        if ($columns == ['*']) {
            $columns = [$this->related->getTable() . '.*'];
        }

        return array_merge($columns, $this->aliasedPivotColumns());
    }

    /**
     * Touch the owning relations if required
     */
    protected function touchIfTouching(): void
    {
        if ($this->touchingParent()) {
            $this->parent->touch();
        }

        if ($this->getParent()->touches($this->relationName)) {
            $this->touch();
        }
    }

    /**
     * Determine if we should touch the parent on changes
     */
    protected function touchingParent(): bool
    {
        return $this->getRelated()->touches($this->guessInverseRelation());
    }

    /**
     * Determine which where method to use
     */
    protected function whereInMethod(Model $model, string $key): string
    {
        return 'whereIn';
    }
}

/**
 * Helper function for collect
 */
function collect($value = [])
{
    return new Collection(is_array($value) ? $value : [$value]);
}
