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
 * Array wrapper with fluent helper methods:
 * - map, filter, reduce operations
 * - Sorting and transformation
 * - Array manipulation
 * - JSON serialization
 *
 * @package Infocyph\DBLayer\Support
 * @author Hasan
 */
class Collection implements ArrayAccess, Countable, Iterator, JsonSerializable
{
    protected array $items = [];
    private int $position = 0;

    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    public function __toString(): string
    {
        return $this->toJson();
    }

    public static function make(array $items = []): self
    {
        return new self($items);
    }

    public function all(): array
    {
        return $this->items;
    }

    public function avg(?string $key = null): int|float
    {
        $count = $this->count();
        return $count > 0 ? $this->sum($key) / $count : 0;
    }

    public function chunk(int $size): self
    {
        $chunks = [];
        foreach (array_chunk($this->items, $size, true) as $chunk) {
            $chunks[] = new self($chunk);
        }
        return new self($chunks);
    }

    public function contains(mixed $key, mixed $value = null): bool
    {
        if (func_num_args() === 1) {
            return in_array($key, $this->items, true);
        }

        return $this->where($key, $value)->isNotEmpty();
    }

    // Countable implementation
    public function count(): int
    {
        return count($this->items);
    }

    // Iterator implementation
    public function current(): mixed
    {
        return current($this->items);
    }

    public function filter(?callable $callback = null): self
    {
        if ($callback === null) {
            return new self(array_filter($this->items));
        }

        return new self(array_filter($this->items, $callback, ARRAY_FILTER_USE_BOTH));
    }

    public function first(?callable $callback = null, mixed $default = null): mixed
    {
        if ($callback === null) {
            return reset($this->items) ?: $default;
        }

        foreach ($this->items as $key => $value) {
            if ($callback($value, $key)) {
                return $value;
            }
        }

        return $default;
    }

    public function groupBy(string|callable $groupBy): self
    {
        $results = [];

        foreach ($this->items as $key => $value) {
            $groupKey = is_callable($groupBy) ? $groupBy($value, $key) :
                (is_array($value) ? ($value[$groupBy] ?? null) : ($value->$groupBy ?? null));

            if (!isset($results[$groupKey])) {
                $results[$groupKey] = new self();
            }

            $results[$groupKey]->items[] = $value;
        }

        return new self($results);
    }

    public function implode(string $glue, ?string $key = null): string
    {
        if ($key === null) {
            return implode($glue, $this->items);
        }

        return $this->pluck($key)->implode($glue);
    }

    public function isEmpty(): bool
    {
        return empty($this->items);
    }

    public function isNotEmpty(): bool
    {
        return !$this->isEmpty();
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function key(): mixed
    {
        return key($this->items);
    }

    public function keys(): self
    {
        return new self(array_keys($this->items));
    }

    public function last(?callable $callback = null, mixed $default = null): mixed
    {
        if ($callback === null) {
            return end($this->items) ?: $default;
        }

        return $this->reverse()->first($callback, $default);
    }

    public function map(callable $callback): self
    {
        return new self(array_map($callback, $this->items, array_keys($this->items)));
    }

    public function max(?string $key = null): mixed
    {
        if ($key === null) {
            return max($this->items);
        }

        return $this->pluck($key)->max();
    }

    public function merge(array $items): self
    {
        return new self(array_merge($this->items, $items));
    }

    public function min(?string $key = null): mixed
    {
        if ($key === null) {
            return min($this->items);
        }

        return $this->pluck($key)->min();
    }

    public function next(): void
    {
        next($this->items);
    }

    // ArrayAccess implementation
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->items[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->items[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->items[$offset]);
    }

    public function pluck(string $key): self
    {
        return $this->map(fn ($item) => is_array($item) ? ($item[$key] ?? null) : ($item->$key ?? null));
    }

    public function pop(): mixed
    {
        return array_pop($this->items);
    }

    public function push(...$values): self
    {
        foreach ($values as $value) {
            $this->items[] = $value;
        }
        return $this;
    }

    public function reduce(callable $callback, mixed $initial = null): mixed
    {
        return array_reduce($this->items, $callback, $initial);
    }

    public function reverse(): self
    {
        return new self(array_reverse($this->items, true));
    }

    public function rewind(): void
    {
        reset($this->items);
    }

    public function shift(): mixed
    {
        return array_shift($this->items);
    }

    public function skip(int $count): self
    {
        return new self(array_slice($this->items, $count));
    }

    public function sortBy(string|callable $callback, int $options = SORT_REGULAR, bool $descending = false): self
    {
        $items = $this->items;

        if (is_callable($callback)) {
            uasort($items, $callback);
        } else {
            uasort(
                $items,
                fn ($a, $b) =>
                ($descending ? -1 : 1) * (
                    (is_array($a) ? ($a[$callback] ?? null) : ($a->$callback ?? null)) <=>
                    (is_array($b) ? ($b[$callback] ?? null) : ($b->$callback ?? null))
                )
            );
        }

        return new self($items);
    }

    public function sortByDesc(string|callable $callback, int $options = SORT_REGULAR): self
    {
        return $this->sortBy($callback, $options, true);
    }

    public function sum(?string $key = null): int|float
    {
        if ($key === null) {
            return array_sum($this->items);
        }

        return $this->pluck($key)->sum();
    }

    public function take(int $limit): self
    {
        if ($limit < 0) {
            return new self(array_slice($this->items, $limit, abs($limit)));
        }

        return new self(array_slice($this->items, 0, $limit));
    }

    public function toArray(): array
    {
        return array_map(function ($value) {
            return $value instanceof self ? $value->toArray() : $value;
        }, $this->items);
    }

    public function toJson(int $options = 0): string
    {
        return json_encode($this->jsonSerialize(), $options);
    }

    public function unique(?string $key = null): self
    {
        if ($key === null) {
            return new self(array_unique($this->items, SORT_REGULAR));
        }

        $exists = [];
        return $this->filter(function ($item) use ($key, &$exists) {
            $value = is_array($item) ? ($item[$key] ?? null) : ($item->$key ?? null);
            if (in_array($value, $exists)) {
                return false;
            }
            $exists[] = $value;
            return true;
        });
    }

    public function unshift(...$values): self
    {
        array_unshift($this->items, ...$values);
        return $this;
    }

    public function valid(): bool
    {
        return key($this->items) !== null;
    }

    public function values(): self
    {
        return new self(array_values($this->items));
    }

    public function where(string $key, mixed $value): self
    {
        return $this->filter(
            fn ($item) =>
            (is_array($item) ? ($item[$key] ?? null) : ($item->$key ?? null)) === $value
        );
    }
}
