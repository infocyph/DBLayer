<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Support;

use ArrayAccess;
use Countable;
use Iterator;
use JsonSerializable;

/**
 * Collection
 *
 * Fluent array wrapper providing chainable methods for array manipulation.
 * Implements standard PHP interfaces for array-like behavior.
 *
 * Intentionally lightweight and non-magic. Higher-level layers
 * can wrap or extend it if needed.
 *
 * @template TKey of array-key
 * @template TValue
 *
 * @implements ArrayAccess<TKey, TValue>
 * @implements Iterator<TKey, TValue>
 * @implements JsonSerializable
 */
final class Collection implements ArrayAccess, Countable, Iterator, JsonSerializable
{
    /**
     * @var array<TKey, TValue>
     */
    protected array $items;

    /**
     * Create a new collection.
     *
     * @param array<TKey, TValue> $items Initial items
     */
    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    /**
     * Convert collection to JSON string.
     */
    public function __toString(): string
    {
        return $this->toJson();
    }

    /**
     * Create an empty collection.
     *
     * @return static
     */
    public static function empty(): static
    {
        return new static();
    }

    /**
     * Create a collection from an array.
     *
     * @param array<TKey, TValue> $items
     * @return static
     */
    public static function make(array $items = []): static
    {
        return new static($items);
    }

    /**
     * Get all items in the collection.
     *
     * @return array<TKey, TValue>
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * Get the average of a given key (or of values themselves if $key is null).
     * Only numeric values are considered in the average.
     *
     * @param string|null $key Key to average
     * @return int|float
     */
    public function avg(?string $key = null): int|float
    {
        if ($this->items === []) {
            return 0;
        }

        [$total, $count] = $this->aggregateAverage($key);

        if ($count === 0) {
            return 0;
        }

        return $total / $count;
    }

    /**
     * Chunk the collection into smaller collections.
     *
     * @param int $size Chunk size
     * @return static<int, static<TKey, TValue>>
     */
    public function chunk(int $size): static
    {
        if ($size <= 0 || $this->items === []) {
            return static::empty();
        }

        $chunks = [];

        foreach (array_chunk($this->items, $size, true) as $chunk) {
            $chunks[] = new static($chunk);
        }

        /** @var static<int, static<TKey, TValue>> */
        return new static($chunks);
    }

    /**
     * Determine if an item exists in the collection.
     *
     * If only one argument is given, checks if that value exists.
     * If two arguments are given, treats them as key/value and calls where().
     */
    public function contains(mixed $key, mixed $value = null): bool
    {
        if (func_num_args() === 1) {
            return in_array($key, $this->items, true);
        }

        return $this->where((string) $key, $value)->isNotEmpty();
    }

    /**
     * Count the number of items (Countable implementation).
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Get the current item (Iterator implementation).
     *
     * @return TValue
     */
    public function current(): mixed
    {
        return current($this->items);
    }

    /**
     * Filter the collection using a callback.
     *
     * @param callable(TValue, TKey): bool|null $callback Filter callback
     * @return static<TKey, TValue>
     */
    public function filter(?callable $callback = null): static
    {
        if ($this->items === []) {
            return static::empty();
        }

        if ($callback === null) {
            /** @var array<TKey, TValue> $filtered */
            $filtered = array_filter($this->items);

            return new static($filtered);
        }

        $results = [];

        foreach ($this->items as $key => $value) {
            if ($callback($value, $key) === true) {
                $results[$key] = $value;
            }
        }

        /** @var static<TKey, TValue> */
        return new static($results);
    }

    /**
     * Get the first item, optionally matching a callback.
     *
     * @param callable(TValue, TKey): bool|null $callback
     * @param mixed $default
     * @return TValue|mixed
     */
    public function first(?callable $callback = null, mixed $default = null): mixed
    {
        if ($this->items === []) {
            return $default;
        }

        if ($callback === null) {
            foreach ($this->items as $item) {
                return $item;
            }

            return $default;
        }

        foreach ($this->items as $key => $value) {
            if ($callback($value, $key) === true) {
                return $value;
            }
        }

        return $default;
    }

    /**
     * Determine if the collection is empty.
     */
    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * Determine if the collection is not empty.
     */
    public function isNotEmpty(): bool
    {
        return $this->items !== [];
    }

    /**
     * Get data for JSON serialization.
     *
     * @return array<TKey, TValue>
     */
    public function jsonSerialize(): array
    {
        return $this->items;
    }

    /**
     * Get the current key (Iterator implementation).
     *
     * @return TKey|null
     */
    public function key(): int|string|null
    {
        /** @var TKey|null */
        return key($this->items);
    }

    /**
     * Map over the collection.
     *
     * @param callable(TValue, TKey): mixed $callback
     * @return static<TKey, mixed>
     */
    public function map(callable $callback): static
    {
        if ($this->items === []) {
            return static::empty();
        }

        $results = [];

        foreach ($this->items as $key => $value) {
            $results[$key] = $callback($value, $key);
        }

        /** @var static<TKey, mixed> */
        return new static($results);
    }

    /**
     * Move to the next item (Iterator implementation).
     */
    public function next(): void
    {
        next($this->items);
    }

    /**
     * Check if offset exists (ArrayAccess implementation).
     *
     * @param mixed $offset
     */
    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->items);
    }

    /**
     * Get offset value (ArrayAccess implementation).
     *
     * @param mixed $offset
     * @return TValue|null
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->items[$offset] ?? null;
    }

    /**
     * Set offset value (ArrayAccess implementation).
     *
     * @param mixed  $offset
     * @param TValue $value
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->items[] = $value;

            return;
        }

        $this->items[$offset] = $value;
    }

    /**
     * Unset offset value (ArrayAccess implementation).
     *
     * @param mixed $offset
     */
    public function offsetUnset(mixed $offset): void
    {
        unset($this->items[$offset]);
    }

    /**
     * Rewind the iterator (Iterator implementation).
     */
    public function rewind(): void
    {
        reset($this->items);
    }

    /**
     * Get the sum of a given key (or of values themselves if $key is null).
     *
     * @param string|null $key Key to sum
     * @return int|float
     */
    public function sum(?string $key = null): int|float
    {
        if ($this->items === []) {
            return 0;
        }

        $total = 0;

        if ($key === null) {
            foreach ($this->items as $value) {
                if (is_numeric($value)) {
                    $total += $value + 0;
                }
            }

            return $total;
        }

        foreach ($this->items as $item) {
            $value = null;

            if (is_array($item) && array_key_exists($key, $item)) {
                $value = $item[$key];
            } elseif (is_object($item) && isset($item->{$key})) {
                $value = $item->{$key};
            }

            if (is_numeric($value)) {
                $total += $value + 0;
            }
        }

        return $total;
    }

    /**
     * Convert collection to array.
     *
     * @return array<TKey, TValue>
     */
    public function toArray(): array
    {
        return $this->items;
    }

    /**
     * Convert collection to JSON.
     */
    public function toJson(int $options = 0): string
    {
        $json = json_encode(
            $this->jsonSerialize(),
            $options | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        return $json === false ? '[]' : $json;
    }

    /**
     * Check if the current position is valid (Iterator implementation).
     */
    public function valid(): bool
    {
        return key($this->items) !== null;
    }

    /**
     * Filter items by key/value.
     *
     * @param string $key
     * @param mixed  $value
     * @return static<TKey, TValue>
     */
    public function where(string $key, mixed $value): static
    {
        return $this->filter(
            static fn ($item) => (
                (is_array($item) && array_key_exists($key, $item) && $item[$key] === $value)
            || (is_object($item) && isset($item->{$key}) && $item->{$key} === $value)
            )
        );
    }

    /**
     * Aggregate total and count for numeric average calculation.
     *
     * @return array{0:int|float,1:int}
     */
    private function aggregateAverage(?string $key): array
    {
        $total = 0;
        $count = 0;

        foreach ($this->items as $item) {
            $value = $this->averageCandidateValue($item, $key);

            if (! is_numeric($value)) {
                continue;
            }

            $total += $value + 0;
            $count++;
        }

        return [$total, $count];
    }

    /**
     * Resolve the value candidate used by avg().
     */
    private function averageCandidateValue(mixed $item, ?string $key): mixed
    {
        if ($key === null) {
            return $item;
        }

        if (is_array($item) && array_key_exists($key, $item)) {
            return $item[$key];
        }

        if (is_object($item) && isset($item->{$key})) {
            return $item->{$key};
        }

        return null;
    }
}
