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
 * @package Infocyph\DBLayer\Support
 * @author Hasan
 * @implements ArrayAccess<array-key, mixed>
 * @implements Iterator<array-key, mixed>
 */
class Collection implements ArrayAccess, Countable, Iterator, JsonSerializable
{
    /**
     * Collection items
     *
     * @var array<array-key, mixed>
     */
    protected array $items = [];

    /**
     * Current iterator position
     */
    private int $position = 0;

    /**
     * Create a new collection
     *
     * @param array<array-key, mixed> $items Initial items
     */
    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    /**
     * Convert collection to JSON string
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->toJson();
    }

    /**
     * Create a new collection instance
     *
     * @param array<array-key, mixed> $items Initial items
     * @return self
     */
    public static function make(array $items = []): self
    {
        return new self($items);
    }

    /**
     * Get all items in the collection
     *
     * @return array<array-key, mixed>
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * Get the average value of a given key
     *
     * @param string|null $key Key to average (null for numeric array)
     * @return int|float
     */
    public function avg(?string $key = null): int|float
    {
        $count = $this->count();
        return $count > 0 ? $this->sum($key) / $count : 0;
    }

    /**
     * Chunk the collection into smaller collections
     *
     * @param int $size Chunk size
     * @return self<int, self>
     */
    public function chunk(int $size): self
    {
        $chunks = [];
        foreach (array_chunk($this->items, $size, true) as $chunk) {
            $chunks[] = new self($chunk);
        }
        return new self($chunks);
    }

    /**
     * Determine if an item exists in the collection
     *
     * @param mixed $key Key or value to check
     * @param mixed $value Value to check (if key provided)
     * @return bool
     */
    public function contains(mixed $key, mixed $value = null): bool
    {
        if (func_num_args() === 1) {
            return in_array($key, $this->items, true);
        }

        return $this->where($key, $value)->isNotEmpty();
    }

    /**
     * Count the number of items (Countable implementation)
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Get current item (Iterator implementation)
     *
     * @return mixed
     */
    public function current(): mixed
    {
        return current($this->items);
    }

    /**
     * Filter the collection using a callback
     *
     * @param callable|null $callback Filter callback
     * @return self
     */
    public function filter(?callable $callback = null): self
    {
        if ($callback === null) {
            return new self(array_filter($this->items));
        }

        return new self(array_filter($this->items, $callback, ARRAY_FILTER_USE_BOTH));
    }

    /**
     * Get the first item matching the condition
     *
     * @param callable|null $callback Filter callback
     * @param mixed $default Default value if not found
     * @return mixed
     */
    public function first(?callable $callback = null, mixed $default = null): mixed
    {
        if ($callback === null) {
            $value = reset($this->items);
            return $value !== false ? $value : $default;
        }

        foreach ($this->items as $key => $value) {
            if ($callback($value, $key)) {
                return $value;
            }
        }

        return $default;
    }

    /**
     * Determine if the collection is empty
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return empty($this->items);
    }

    /**
     * Determine if the collection is not empty
     *
     * @return bool
     */
    public function isNotEmpty(): bool
    {
        return !$this->isEmpty();
    }

    /**
     * Get current iterator key (Iterator implementation)
     *
     * @return int|string|null
     */
    public function key(): int|string|null
    {
        return key($this->items);
    }

    /**
     * Map over the collection
     *
     * @param callable $callback Map callback
     * @return self
     */
    public function map(callable $callback): self
    {
        return new self(array_map($callback, $this->items, array_keys($this->items)));
    }

    /**
     * Move to next iterator position (Iterator implementation)
     *
     * @return void
     */
    public function next(): void
    {
        next($this->items);
    }

    /**
     * Check if offset exists (ArrayAccess implementation)
     *
     * @param mixed $offset
     * @return bool
     */
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->items[$offset]);
    }

    /**
     * Get offset value (ArrayAccess implementation)
     *
     * @param mixed $offset
     * @return mixed
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->items[$offset] ?? null;
    }

    /**
     * Set offset value (ArrayAccess implementation)
     *
     * @param mixed $offset
     * @param mixed $value
     * @return void
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }

    /**
     * Unset offset (ArrayAccess implementation)
     *
     * @param mixed $offset
     * @return void
     */
    public function offsetUnset(mixed $offset): void
    {
        unset($this->items[$offset]);
    }

    /**
     * Rewind iterator to first position (Iterator implementation)
     *
     * @return void
     */
    public function rewind(): void
    {
        reset($this->items);
    }

    /**
     * Get the sum of a given key
     *
     * @param string|null $key Key to sum
     * @return int|float
     */
    public function sum(?string $key = null): int|float
    {
        if ($key === null) {
            return array_sum($this->items);
        }

        return array_sum(array_column($this->items, $key));
    }

    /**
     * Convert collection to array
     *
     * @return array<array-key, mixed>
     */
    public function toArray(): array
    {
        return $this->items;
    }

    /**
     * Convert collection to JSON (JsonSerializable implementation)
     *
     * @return string
     */
    public function toJson(int $options = 0): string
    {
        return json_encode($this->jsonSerialize(), $options);
    }

    /**
     * Check if current iterator position is valid (Iterator implementation)
     *
     * @return bool
     */
    public function valid(): bool
    {
        return key($this->items) !== null;
    }

    /**
     * Filter items by key/value
     *
     * @param string $key
     * @param mixed $value
     * @return self
     */
    public function where(string $key, mixed $value): self
    {
        return $this->filter(fn ($item) => isset($item[$key]) && $item[$key] === $value);
    }

    /**
     * Get data for JSON serialization
     *
     * @return array<array-key, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->items;
    }
}
