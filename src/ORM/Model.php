<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\ORM;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Exceptions\ModelException;
use Infocyph\DBLayer\ORM\Concerns\GuardsAttributes;
use Infocyph\DBLayer\ORM\Concerns\HasAttributes;
use Infocyph\DBLayer\ORM\Concerns\HasEvents;
use Infocyph\DBLayer\ORM\Concerns\HasRelationships;
use Infocyph\DBLayer\ORM\Concerns\HasTimestamps;
use Infocyph\DBLayer\Query\QueryBuilder;

/**
 * Active Record Base Model
 *
 * Provides ORM functionality with support for:
 * - Attribute casting and mutators
 * - Relationships (HasOne, HasMany, BelongsTo, BelongsToMany)
 * - Timestamps and soft deletes
 * - Mass assignment protection
 * - Model events
 * - Query scopes
 *
 * @package Infocyph\DBLayer\ORM
 * @author Hasan
 */
abstract class Model
{
    use GuardsAttributes;
    use HasAttributes;
    use HasEvents;
    use HasRelationships;
    use HasTimestamps;

    /**
     * The name of the "created at" column
     */
    public const CREATED_AT = 'created_at';

    /**
     * The name of the "deleted at" column
     */
    public const DELETED_AT = 'deleted_at';

    /**
     * The name of the "updated at" column
     */
    public const UPDATED_AT = 'updated_at';

    /**
     * The connection instance
     */
    protected static ?Connection $connection = null;

    /**
     * Indicates if the model exists in the database
     */
    public bool $exists = false;

    /**
     * Indicates if the IDs are auto-incrementing
     */
    public bool $incrementing = true;

    /**
     * Indicates if the model should be timestamped
     */
    public bool $timestamps = true;

    /**
     * Indicates if the model was inserted during the current request lifecycle
     */
    public bool $wasRecentlyCreated = false;

    /**
     * The accessors to append to the model's array form
     */
    protected array $appends = [];

    /**
     * The connection name for the model
     */
    protected ?string $connectionName = null;

    /**
     * The attributes that should be hidden for serialization
     */
    protected array $hidden = [];

    /**
     * The "type" of the primary key ID
     */
    protected string $keyType = 'int';

    /**
     * User exposed observable events
     */
    protected array $observables = [];

    /**
     * The number of models to return for pagination
     */
    protected int $perPage = 15;

    /**
     * The primary key for the model
     */
    protected string $primaryKey = 'id';

    /**
     * The table associated with the model
     */
    protected string $table = '';

    /**
     * The attributes that should be visible in serialization
     */
    protected array $visible = [];

    /**
     * The relations to eager load on every query
     */
    protected array $with = [];

    /**
     * The relationship counts that should be eager loaded on every query
     */
    protected array $withCount = [];

    /**
     * Create a new Model instance
     */
    public function __construct(array $attributes = [])
    {
        $this->bootIfNotBooted();
        $this->initializeTraits();
        $this->fill($attributes);
    }

    /**
     * Handle dynamic method calls into the model
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->forwardCallTo($this->newQuery(), $method, $parameters);
    }

    /**
     * Handle dynamic static method calls into the method
     */
    public static function __callStatic(string $method, array $parameters): mixed
    {
        return new static()->$method(...$parameters);
    }

    /**
     * Dynamically retrieve attributes on the model
     */
    public function __get(string $key): mixed
    {
        return $this->getAttribute($key);
    }

    /**
     * Determine if an attribute or relation exists on the model
     */
    public function __isset(string $key): bool
    {
        return $this->offsetExists($key);
    }

    /**
     * Dynamically set attributes on the model
     */
    public function __set(string $key, mixed $value): void
    {
        $this->setAttribute($key, $value);
    }

    /**
     * Convert the model to its string representation
     */
    public function __toString(): string
    {
        return $this->toJson();
    }

    /**
     * Unset an attribute on the model
     */
    public function __unset(string $key): void
    {
        $this->offsetUnset($key);
    }

    /**
     * Retrieve all models
     */
    public static function all(array $columns = ['*']): Collection
    {
        return static::query()->get($columns);
    }

    /**
     * Create a new instance of the model
     */
    public static function create(array $attributes = []): static
    {
        $model = new static($attributes);
        $model->save();

        return $model;
    }

    /**
     * Find a model by its primary key
     */
    public static function find(mixed $id, array $columns = ['*']): ?static
    {
        if (is_array($id)) {
            return static::findMany($id, $columns);
        }

        return static::query()->where(new static()->getKeyName(), '=', $id)->first($columns);
    }

    /**
     * Find multiple models by their primary keys
     */
    public static function findMany(array $ids, array $columns = ['*']): Collection
    {
        if (empty($ids)) {
            return new static()->newCollection();
        }

        return static::query()->whereIn(new static()->getKeyName(), $ids)->get($columns);
    }

    /**
     * Execute a query for a single record by ID or call a callback
     */
    public static function findOr(mixed $id, $columns = ['*'], ?callable $callback = null): mixed
    {
        if ($columns instanceof \Closure) {
            $callback = $columns;
            $columns = ['*'];
        }

        if (!is_null($model = static::find($id, $columns))) {
            return $model;
        }

        return $callback ? $callback() : null;
    }

    /**
     * Find a model by its primary key or throw an exception
     */
    public static function findOrFail(mixed $id, array $columns = ['*']): static
    {
        $result = static::find($id, $columns);

        if (is_null($result)) {
            throw new ModelException("Model not found with ID: {$id}");
        }

        return $result;
    }

    /**
     * Get the first record matching the attributes or create it
     */
    public static function firstOrCreate(array $attributes = [], array $values = []): static
    {
        if (!is_null($instance = static::where($attributes)->first())) {
            return $instance;
        }

        return static::create(array_merge($attributes, $values));
    }

    /**
     * Get the first record matching the attributes or instantiate it
     */
    public static function firstOrNew(array $attributes = [], array $values = []): static
    {
        if (!is_null($instance = static::where($attributes)->first())) {
            return $instance;
        }

        return new static()->newInstance(array_merge($attributes, $values));
    }

    /**
     * Get the connection used by the model
     */
    public static function getConnection(): Connection
    {
        if (static::$connection === null) {
            throw new ModelException('Database connection not set');
        }

        return static::$connection;
    }

    /**
     * Get a new query builder for the model's table
     */
    public static function query(): Builder
    {
        return new static()->newQuery();
    }

    /**
     * Set the connection for all models
     */
    public static function setConnection(Connection $connection): void
    {
        static::$connection = $connection;
    }

    /**
     * Create or update a record matching the attributes, and fill it with values
     */
    public static function updateOrCreate(array $attributes, array $values = []): static
    {
        $instance = static::firstOrNew($attributes);

        $instance->fill($values)->save();

        return $instance;
    }

    /**
     * Begin querying the model
     */
    public static function where($column, $operator = null, $value = null, string $boolean = 'and'): Builder
    {
        return static::query()->where($column, $operator, $value, $boolean);
    }

    /**
     * Delete the model from the database
     */
    public function delete(): ?bool
    {
        if (is_null($this->getKeyName())) {
            throw new ModelException('No primary key defined on model.');
        }

        if (!$this->exists) {
            return null;
        }

        if ($this->fireModelEvent('deleting') === false) {
            return false;
        }

        $this->performDeleteOnModel();

        $this->fireModelEvent('deleted', false);

        return true;
    }

    /**
     * Force a hard delete on a soft deleted model
     */
    public function forceDelete(): ?bool
    {
        return $this->delete();
    }

    /**
     * Get the connection name for the model
     */
    public function getConnectionName(): ?string
    {
        return $this->connectionName;
    }

    /**
     * Get the value of the model's primary key
     */
    public function getKey(): mixed
    {
        return $this->getAttribute($this->getKeyName());
    }

    /**
     * Get the primary key for the model
     */
    public function getKeyName(): string
    {
        return $this->primaryKey;
    }

    /**
     * Get the number of models to return per page
     */
    public function getPerPage(): int
    {
        return $this->perPage;
    }

    /**
     * Get the queueable identity for the entity
     */
    public function getQueueableId(): mixed
    {
        return $this->getKey();
    }

    /**
     * Get the table associated with the model
     */
    public function getTable(): string
    {
        if (empty($this->table)) {
            $this->table = str_replace(
                '\\',
                '',
                strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', class_basename($this))) . 's'
            );
        }

        return $this->table;
    }

    /**
     * Determine if two models have the same ID and belong to the same table
     */
    public function is(?Model $model): bool
    {
        return !is_null($model) &&
               $this->getKey() === $model->getKey() &&
               $this->getTable() === $model->getTable() &&
               $this->getConnectionName() === $model->getConnectionName();
    }

    /**
     * Determine if two models are not the same
     */
    public function isNot(?Model $model): bool
    {
        return !$this->is($model);
    }

    /**
     * Create a new Collection instance
     */
    public function newCollection(array $models = []): Collection
    {
        return new Collection($models);
    }

    /**
     * Create a new model instance
     */
    public function newInstance(array $attributes = [], bool $exists = false): static
    {
        $model = new static($attributes);
        $model->exists = $exists;
        $model->setConnection(static::getConnection());

        return $model;
    }

    /**
     * Get a new query builder instance for the connection
     */
    public function newQuery(): Builder
    {
        return $this->newQueryBuilder(
            static::getConnection()->table($this->getTable())
        )->setModel($this);
    }

    /**
     * Create a new Model query builder
     */
    public function newQueryBuilder(QueryBuilder $query): Builder
    {
        return new Builder($query);
    }

    /**
     * Reload the current model instance with fresh attributes from the database
     */
    public function refresh(): static
    {
        if (!$this->exists) {
            return $this;
        }

        $this->setRawAttributes(
            static::find($this->getKey())->attributes
        );

        $this->syncOriginal();

        return $this;
    }

    /**
     * Clone the model into a new, non-existing instance
     */
    public function replicate(array $except = []): static
    {
        $defaults = [
            $this->getKeyName(),
            $this->getCreatedAtColumn(),
            $this->getUpdatedAtColumn(),
        ];

        $attributes = array_diff_key(
            $this->getAttributes(),
            array_flip(array_merge($defaults, $except))
        );

        return new static()->setRawAttributes($attributes)->setRelations($this->relations);
    }

    /**
     * Save the model to the database
     */
    public function save(array $options = []): bool
    {
        $this->mergeAttributesFromClassCasts();

        $query = $this->newQuery();

        if ($this->fireModelEvent('saving') === false) {
            return false;
        }

        if ($this->exists) {
            $saved = $this->isDirty() ? $this->performUpdate($query) : true;
        } else {
            $saved = $this->performInsert($query);
        }

        if ($saved) {
            $this->finishSave($options);
            $this->syncOriginal();
        }

        return $saved;
    }

    /**
     * Set the primary key for the model
     */
    public function setKeyName(string $key): static
    {
        $this->primaryKey = $key;
        return $this;
    }

    /**
     * Set the number of models to return per page
     */
    public function setPerPage(int $perPage): static
    {
        $this->perPage = $perPage;
        return $this;
    }

    /**
     * Set the table associated with the model
     */
    public function setTable(string $table): static
    {
        $this->table = $table;
        return $this;
    }

    /**
     * Update the model in the database
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        if (!$this->exists) {
            return false;
        }

        return $this->fill($attributes)->save($options);
    }

    /**
     * The "booting" method of the model
     */
    protected static function boot(): void
    {
        static::bootTraits();
    }

    /**
     * Boot all of the bootable traits on the model
     */
    protected static function bootTraits(): void
    {
        $class = static::class;

        foreach (class_uses_recursive($class) as $trait) {
            $method = 'boot' . class_basename($trait);

            if (method_exists($class, $method)) {
                forward_static_call([$class, $method]);
            }
        }
    }

    /**
     * Boot the model if not booted
     */
    protected function bootIfNotBooted(): void
    {
        if (!isset(static::$booted[static::class])) {
            static::$booted[static::class] = true;
            static::boot();
        }
    }

    /**
     * Finish processing on a successful save operation
     */
    protected function finishSave(array $options): void
    {
        $this->fireModelEvent('saved', false);
    }

    /**
     * Forward a method call to the given object
     */
    protected function forwardCallTo(object $object, string $method, array $parameters): mixed
    {
        if (!method_exists($object, $method)) {
            throw new \BadMethodCallException(
                sprintf('Call to undefined method %s::%s()', get_class($object), $method)
            );
        }

        return $object->$method(...$parameters);
    }

    /**
     * Get the primary key value for a save query
     */
    protected function getKeyForSaveQuery(): mixed
    {
        return $this->original[$this->getKeyName()] ?? $this->getKey();
    }

    /**
     * Initialize the traits on the model
     */
    protected function initializeTraits(): void
    {
        foreach (class_uses_recursive(static::class) as $trait) {
            $method = 'initialize' . class_basename($trait);

            if (method_exists($this, $method)) {
                $this->$method();
            }
        }
    }

    /**
     * Insert the given attributes and set the ID on the model
     */
    protected function insertAndSetId(Builder $query, array $attributes): void
    {
        $id = $query->getQuery()->insertGetId($attributes, $keyName = $this->getKeyName());

        $this->setAttribute($keyName, $id);
    }

    /**
     * Perform the actual delete query on this model instance
     */
    protected function performDeleteOnModel(): void
    {
        $this->setKeysForSaveQuery($this->newQuery())->delete();

        $this->exists = false;
    }

    /**
     * Perform a model insert operation
     */
    protected function performInsert(Builder $query): bool
    {
        if ($this->fireModelEvent('creating') === false) {
            return false;
        }

        if ($this->usesTimestamps()) {
            $this->updateTimestamps();
        }

        $attributes = $this->getAttributesForInsert();

        if ($this->incrementing) {
            $this->insertAndSetId($query, $attributes);
        } else {
            if (empty($attributes)) {
                return true;
            }

            $query->getQuery()->insert($attributes);
        }

        $this->exists = true;
        $this->wasRecentlyCreated = true;

        $this->fireModelEvent('created', false);

        return true;
    }

    /**
     * Perform a model update operation
     */
    protected function performUpdate(Builder $query): bool
    {
        if ($this->fireModelEvent('updating') === false) {
            return false;
        }

        if ($this->usesTimestamps()) {
            $this->updateTimestamps();
        }

        $dirty = $this->getDirty();

        if (count($dirty) > 0) {
            $this->setKeysForSaveQuery($query)->update($dirty);

            $this->syncChanges();
            $this->fireModelEvent('updated', false);
        }

        return true;
    }

    /**
     * Set the keys for a save update query
     */
    protected function setKeysForSaveQuery(Builder $query): Builder
    {
        $query->where($this->getKeyName(), '=', $this->getKeyForSaveQuery());

        return $query;
    }
}
