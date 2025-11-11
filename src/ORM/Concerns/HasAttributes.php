<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\ORM\Concerns;

use DateTimeInterface;

/**
 * Has Attributes Trait
 *
 * Manages model attributes with casting, mutators, and dirty tracking
 *
 * @package Infocyph\DBLayer\ORM\Concerns
 * @author Hasan
 */
trait HasAttributes
{
    public static bool $snakeAttributes = true;
    protected static array $booted = [];
    protected static array $mutatorCache = [];
    protected array $appends = [];
    protected array $attributes = [];
    protected array $casts = [];
    protected array $changes = [];
    protected string $dateFormat = 'Y-m-d H:i:s';
    protected array $dates = [];
    protected array $original = [];

    public static function cacheMutatedAttributes(string $class): void
    {
        preg_match_all('/(?<=^|;)get([^;]+?)Attribute(;|$)/', implode(';', get_class_methods($class)), $matches);

        static::$mutatorCache[$class] = array_map(function ($match) {
            return lcfirst($match);
        }, $matches[1]);
    }

    public function attributesToArray(): array
    {
        $attributes = $this->getArrayableAttributes();

        foreach ($this->getDates() as $key) {
            if (!isset($attributes[$key])) {
                continue;
            }
            $attributes[$key] = $this->serializeDate($this->asDateTime($attributes[$key]));
        }

        foreach ($this->getMutatedAttributes() as $key) {
            if (!array_key_exists($key, $attributes)) {
                continue;
            }
            $attributes[$key] = $this->mutateAttributeForArray($key, $attributes[$key]);
        }

        foreach ($this->getCasts() as $key => $castType) {
            if (!array_key_exists($key, $attributes)) {
                continue;
            }
            $attributes[$key] = $this->castAttribute($key, $attributes[$key]);
        }

        return $attributes;
    }

    public function fromDateTime(mixed $value): ?string
    {
        return empty($value) ? $value : $this->asDateTime($value)->format($this->getDateFormat());
    }

    public function fromJson(string $value, bool $asObject = false): mixed
    {
        return json_decode($value, !$asObject);
    }

    public function getAttribute(string $key): mixed
    {
        if (!$key) {
            return null;
        }

        if (array_key_exists($key, $this->getAttributes()) ||
            array_key_exists($key, $this->casts) ||
            $this->hasGetMutator($key)) {
            return $this->getAttributeValue($key);
        }

        if (method_exists($this, $key)) {
            return $this->getRelationValue($key);
        }

        return null;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getAttributeValue(string $key): mixed
    {
        return $this->transformModelValue($key, $this->getAttributeFromArray($key));
    }

    public function getCasts(): array
    {
        return $this->casts;
    }

    public function getChanges(): array
    {
        return $this->changes;
    }

    public function getDateFormat(): string
    {
        return $this->dateFormat;
    }

    public function getDates(): array
    {
        if (!$this->usesTimestamps()) {
            return $this->dates;
        }

        $defaults = [static::CREATED_AT, static::UPDATED_AT];
        return array_unique(array_merge($this->dates, $defaults));
    }

    public function getDirty(): array
    {
        $dirty = [];

        foreach ($this->getAttributes() as $key => $value) {
            if (!$this->originalIsEquivalent($key)) {
                $dirty[$key] = $value;
            }
        }

        return $dirty;
    }

    public function getHidden(): array
    {
        return $this->hidden ?? [];
    }

    public function getMutatedAttributes(): array
    {
        $class = static::class;

        if (!isset(static::$mutatorCache[$class])) {
            static::cacheMutatedAttributes($class);
        }

        return static::$mutatorCache[$class];
    }

    public function getOriginal(?string $key = null, mixed $default = null): mixed
    {
        if ($key) {
            return $this->original[$key] ?? $default;
        }
        return $this->original;
    }

    public function getVisible(): array
    {
        return $this->visible ?? [];
    }

    public function hasCast(string $key, array|string|null $types = null): bool
    {
        if (array_key_exists($key, $this->getCasts())) {
            return $types ? in_array($this->getCastType($key), (array) $types, true) : true;
        }
        return false;
    }

    public function hasGetMutator(string $key): bool
    {
        return method_exists($this, 'get' . str_replace('_', '', ucwords($key, '_')) . 'Attribute');
    }

    public function hasSetMutator(string $key): bool
    {
        return method_exists($this, 'set' . str_replace('_', '', ucwords($key, '_')) . 'Attribute');
    }

    public function isClean(string|array|null $attributes = null): bool
    {
        return !$this->isDirty(...func_get_args());
    }

    public function isDirty(string|array|null $attributes = null): bool
    {
        return $this->hasChanges(
            $this->getDirty(),
            is_array($attributes) ? $attributes : func_get_args()
        );
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function offsetExists(mixed $offset): bool
    {
        return !is_null($this->getAttribute($offset));
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->getAttribute($offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->setAttribute($offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->attributes[$offset]);
    }

    public function setAttribute(string $key, mixed $value): static
    {
        if ($this->hasSetMutator($key)) {
            return $this->setMutatedAttributeValue($key, $value);
        }

        if (!is_null($value) && $this->isDateAttribute($key)) {
            $value = $this->fromDateTime($value);
        }

        if ($this->isJsonCastable($key) && !is_null($value)) {
            $value = $this->castAttributeAsJson($key, $value);
        }

        $this->attributes[$key] = $value;
        return $this;
    }

    public function setDateFormat(string $format): static
    {
        $this->dateFormat = $format;
        return $this;
    }

    public function setHidden(array $hidden): static
    {
        $this->hidden = $hidden;
        return $this;
    }

    public function setRawAttributes(array $attributes, bool $sync = false): static
    {
        $this->attributes = $attributes;

        if ($sync) {
            $this->syncOriginal();
        }

        return $this;
    }

    public function setVisible(array $visible): static
    {
        $this->visible = $visible;
        return $this;
    }

    public function syncChanges(): static
    {
        $this->changes = $this->getDirty();
        return $this;
    }

    public function syncOriginal(): static
    {
        $this->original = $this->getAttributes();
        return $this;
    }

    public function toArray(): array
    {
        return array_merge($this->attributesToArray(), $this->relationsToArray());
    }

    public function toJson(int $options = 0): string
    {
        return json_encode($this->jsonSerialize(), $options);
    }

    protected function asDate(mixed $value): \DateTime
    {
        return $this->asDateTime($value);
    }

    protected function asDateTime(mixed $value): \DateTime
    {
        if ($value instanceof \DateTimeInterface) {
            return new \DateTime($value->format('Y-m-d H:i:s'));
        }

        if (is_numeric($value)) {
            return new \DateTime()->setTimestamp($value);
        }

        return new \DateTime($value);
    }

    protected function asTimestamp(mixed $value): int
    {
        return $this->asDateTime($value)->getTimestamp();
    }

    protected function castAttribute(string $key, mixed $value): mixed
    {
        $castType = $this->getCastType($key);

        if (is_null($value) && in_array($castType, ['int', 'integer', 'float', 'double', 'string', 'bool', 'boolean'])) {
            return $value;
        }

        return match ($castType) {
            'int', 'integer' => (int) $value,
            'real', 'float', 'double' => (float) $value,
            'string' => (string) $value,
            'bool', 'boolean' => (bool) $value,
            'array', 'json' => $this->fromJson($value),
            'object' => $this->fromJson($value, true),
            'collection' => new \Infocyph\DBLayer\ORM\Collection($this->fromJson($value)),
            'date' => $this->asDate($value),
            'datetime', 'custom_datetime' => $this->asDateTime($value),
            'timestamp' => $this->asTimestamp($value),
            default => $value,
        };
    }

    protected function castAttributeAsJson(string $key, mixed $value): string
    {
        return json_encode($value);
    }

    protected function getArrayableAttributes(): array
    {
        return $this->getArrayableItems($this->getAttributes());
    }

    protected function getArrayableItems(array $values): array
    {
        if (count($this->getVisible()) > 0) {
            $values = array_intersect_key($values, array_flip($this->getVisible()));
        }

        if (count($this->getHidden()) > 0) {
            $values = array_diff_key($values, array_flip($this->getHidden()));
        }

        return $values;
    }

    protected function getAttributeFromArray(string $key): mixed
    {
        return $this->getAttributes()[$key] ?? null;
    }

    protected function getAttributesForInsert(): array
    {
        return $this->getAttributes();
    }

    protected function getCastType(string $key): string
    {
        $castType = $this->getCasts()[$key] ?? '';
        return trim(strtolower($castType));
    }

    protected function hasChanges(array $changes, array|string|null $attributes = null): bool
    {
        if (empty($attributes)) {
            return count($changes) > 0;
        }
        return array_any((array) $attributes, fn ($attribute) => array_key_exists($attribute, $changes));
    }

    protected function isDateAttribute(string $key): bool
    {
        return in_array($key, $this->getDates(), true) || $this->isDateCastable($key);
    }

    protected function isDateCastable(string $key): bool
    {
        return $this->hasCast($key, ['date', 'datetime', 'custom_datetime']);
    }

    protected function isJsonCastable(string $key): bool
    {
        return $this->hasCast($key, ['array', 'json', 'object', 'collection']);
    }

    protected function mergeAttributesFromClassCasts(): void
    {
        // Placeholder for advanced casting
    }

    protected function mutateAttribute(string $key, mixed $value): mixed
    {
        return $this->{'get' . str_replace('_', '', ucwords($key, '_')) . 'Attribute'}($value);
    }

    protected function mutateAttributeForArray(string $key, mixed $value): mixed
    {
        $value = $this->mutateAttribute($key, $value);

        return $value instanceof DateTimeInterface
            ? $this->serializeDate($value)
            : $value;
    }

    protected function originalIsEquivalent(string $key): bool
    {
        if (!array_key_exists($key, $this->original)) {
            return false;
        }

        $attribute = $this->getAttributes()[$key] ?? null;
        $original = $this->original[$key] ?? null;

        if ($attribute === $original) {
            return true;
        }

        if (is_null($attribute)) {
            return false;
        }

        if ($this->isDateAttribute($key)) {
            return $this->fromDateTime($attribute) === $this->fromDateTime($original);
        }

        return is_numeric($attribute) && is_numeric($original)
            && strcmp((string) $attribute, (string) $original) === 0;
    }

    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format($this->getDateFormat());
    }

    protected function setMutatedAttributeValue(string $key, mixed $value): static
    {
        return $this->{'set' . str_replace('_', '', ucwords($key, '_')) . 'Attribute'}($value);
    }

    protected function transformModelValue(string $key, mixed $value): mixed
    {
        if ($this->hasGetMutator($key)) {
            return $this->mutateAttribute($key, $value);
        }

        if ($this->hasCast($key)) {
            return $this->castAttribute($key, $value);
        }

        if (in_array($key, $this->getDates()) && !is_null($value)) {
            return $this->asDateTime($value);
        }

        return $value;
    }
}
