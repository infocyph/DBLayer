<?php

declare(strict_types=1);

namespace Infocyph\DBLayer;

use ArrayAccess;
use Countable;
use Iterator;
use JsonSerializable;

/**
 * Collection class providing utility methods for result sets
 */
class Collection implements ArrayAccess, Iterator, Countable, JsonSerializable
{
    private array $items;
    private int $position = 0;

    public function __construct(array $items = [])
    {
        $this->items = array_values($items);
    }

    // ===== RETRIEVAL METHODS =====

    public function all(): array
    {
        return $this->items;
    }

    public function first(?callable $callback = null, mixed $default = null): mixed
    {
        if ($callback === null) {
            return $this->items[0] ?? $default;
        }

        foreach ($this->items as $key => $item) {
            if ($callback($item, $key)) {
                return $item;
            }
        }

        return $default;
    }

    public function last(?callable $callback = null, mixed $default = null): mixed
    {
        if ($callback === null) {
            return empty($this->items) ? $default : end($this->items);
        }

        return $this->filter($callback)->last(null, $default);
    }

    public function get(mixed $key, mixed $default = null): mixed
    {
        return $this->items[$key] ?? $default;
    }

    // ===== TRANSFORMATION METHODS =====

    public function map(callable $callback): self
    {
        return new self(array_map($callback, $this->items, array_keys($this->items)));
    }

    public function filter(?callable $callback = null): self
    {
        if ($callback === null) {
            return new self(array_filter($this->items));
        }

        return new self(array_filter($this->items, $callback, ARRAY_FILTER_USE_BOTH));
    }

    public function reject(callable $callback): self
    {
        return $this->filter(fn($item, $key) => !$callback($item, $key));
    }

    public function reduce(callable $callback, mixed $initial = null): mixed
    {
        return array_reduce($this->items, $callback, $initial);
    }

    public function each(callable $callback): self
    {
        foreach ($this->items as $key => $item) {
            if ($callback($item, $key) === false) {
                break;
            }
        }

        return $this;
    }

    public function pluck(string $value, ?string $key = null): self
    {
        $results = [];

        foreach ($this->items as $item) {
            $itemValue = is_array($item) ? ($item[$value] ?? null) : ($item->$value ?? null);

            if ($key === null) {
                $results[] = $itemValue;
            } else {
                $itemKey = is_array($item) ? ($item[$key] ?? null) : ($item->$key ?? null);
                $results[$itemKey] = $itemValue;
            }
        }

        return new self($results);
    }

    public function groupBy(string|callable $groupBy): self
    {
        $results = [];

        foreach ($this->items as $item) {
            $key = is_callable($groupBy)
                ? $groupBy($item)
                : (is_array($item) ? ($item[$groupBy] ?? null) : ($item->$groupBy ?? null));

            $results[$key][] = $item;
        }

        return new self($results);
    }

    public function keyBy(string|callable $keyBy): self
    {
        $results = [];

        foreach ($this->items as $item) {
            $key = is_callable($keyBy)
                ? $keyBy($item)
                : (is_array($item) ? ($item[$keyBy] ?? null) : ($item->$keyBy ?? null));

            $results[$key] = $item;
        }

        return new self($results);
    }

    public function unique(string|callable|null $key = null): self
    {
        if ($key === null) {
            return new self(array_unique($this->items, SORT_REGULAR));
        }

        $exists = [];
        $results = [];

        foreach ($this->items as $item) {
            $id = is_callable($key)
                ? $key($item)
                : (is_array($item) ? ($item[$key] ?? null) : ($item->$key ?? null));

            if (!in_array($id, $exists, true)) {
                $exists[] = $id;
                $results[] = $item;
            }
        }

        return new self($results);
    }

    // ===== SORTING METHODS =====

    public function sort(?callable $callback = null): self
    {
        $items = $this->items;

        if ($callback === null) {
            sort($items);
        } else {
            usort($items, $callback);
        }

        return new self($items);
    }

    public function sortBy(string|callable $callback, bool $descending = false): self
    {
        $items = $this->items;

        usort($items, function ($a, $b) use ($callback, $descending) {
            $valueA = is_callable($callback)
                ? $callback($a)
                : (is_array($a) ? ($a[$callback] ?? null) : ($a->$callback ?? null));

            $valueB = is_callable($callback)
                ? $callback($b)
                : (is_array($b) ? ($b[$callback] ?? null) : ($b->$callback ?? null));

            $result = $valueA <=> $valueB;
            return $descending ? -$result : $result;
        });

        return new self($items);
    }

    public function sortByDesc(string|callable $callback): self
    {
        return $this->sortBy($callback, true);
    }

    public function reverse(): self
    {
        return new self(array_reverse($this->items));
    }

    public function shuffle(): self
    {
        $items = $this->items;
        shuffle($items);
        return new self($items);
    }

    // ===== SLICING METHODS =====

    public function slice(int $offset, ?int $length = null): self
    {
        return new self(array_slice($this->items, $offset, $length));
    }

    public function take(int $limit): self
    {
        return $limit < 0 ? $this->slice($limit) : $this->slice(0, $limit);
    }

    public function skip(int $offset): self
    {
        return $this->slice($offset);
    }

    public function chunk(int $size): self
    {
        return new self(array_chunk($this->items, $size));
    }

    public function split(int $numberOfGroups): self
    {
        $size = (int) ceil($this->count() / $numberOfGroups);
        return $this->chunk($size);
    }

    // ===== SEARCH METHODS =====

    public function contains(string|callable $key, mixed $operator = null, mixed $value = null): bool
    {
        if (is_callable($key)) {
            foreach ($this->items as $item) {
                if ($key($item)) {
                    return true;
                }
            }
            return false;
        }

        if ($operator === null) {
            return in_array($key, $this->items, true);
        }

        return $this->where($key, $operator, $value)->isNotEmpty();
    }

    public function find(mixed $key, mixed $default = null): mixed
    {
        return $this->items[$key] ?? $default;
    }

    public function where(string $key, mixed $operator, mixed $value = null): self
    {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        return $this->filter(function ($item) use ($key, $operator, $value) {
            $itemValue = is_array($item) ? ($item[$key] ?? null) : ($item->$key ?? null);

            return match ($operator) {
                '=' => $itemValue == $value,
                '===' => $itemValue === $value,
                '!=' => $itemValue != $value,
                '!==' => $itemValue !== $value,
                '<' => $itemValue < $value,
                '>' => $itemValue > $value,
                '<=' => $itemValue <= $value,
                '>=' => $itemValue >= $value,
                default => false
            };
        });
    }

    public function whereIn(string $key, array $values): self
    {
        return $this->filter(function ($item) use ($key, $values) {
            $itemValue = is_array($item) ? ($item[$key] ?? null) : ($item->$key ?? null);
            return in_array($itemValue, $values, true);
        });
    }

    public function whereNotIn(string $key, array $values): self
    {
        return $this->filter(function ($item) use ($key, $values) {
            $itemValue = is_array($item) ? ($item[$key] ?? null) : ($item->$key ?? null);
            return !in_array($itemValue, $values, true);
        });
    }

    public function whereBetween(string $key, array $values): self
    {
        [$min, $max] = $values;

        return $this->filter(function ($item) use ($key, $min, $max) {
            $itemValue = is_array($item) ? ($item[$key] ?? null) : ($item->$key ?? null);
            return $itemValue >= $min && $itemValue <= $max;
        });
    }

    public function whereNull(string $key): self
    {
        return $this->filter(function ($item) use ($key) {
            $itemValue = is_array($item) ? ($item[$key] ?? null) : ($item->$key ?? null);
            return $itemValue === null;
        });
    }

    public function whereNotNull(string $key): self
    {
        return $this->filter(function ($item) use ($key) {
            $itemValue = is_array($item) ? ($item[$key] ?? null) : ($item->$key ?? null);
            return $itemValue !== null;
        });
    }

    // ===== AGGREGATE METHODS =====

    public function count(): int
    {
        return count($this->items);
    }

    public function sum(string|callable|null $callback = null): float
    {
        if ($callback === null) {
            return array_sum($this->items);
        }

        return $this->reduce(function ($carry, $item) use ($callback) {
            $value = is_callable($callback)
                ? $callback($item)
                : (is_array($item) ? ($item[$callback] ?? 0) : ($item->$callback ?? 0));

            return $carry + $value;
        }, 0);
    }

    public function avg(string|callable|null $callback = null): float
    {
        $count = $this->count();
        return $count === 0 ? 0 : $this->sum($callback) / $count;
    }

    public function min(string|callable|null $callback = null): mixed
    {
        if ($callback === null) {
            return min($this->items);
        }

        return $this->reduce(function ($min, $item) use ($callback) {
            $value = is_callable($callback)
                ? $callback($item)
                : (is_array($item) ? ($item[$callback] ?? null) : ($item->$callback ?? null));

            return $min === null || $value < $min ? $value : $min;
        });
    }

    public function max(string|callable|null $callback = null): mixed
    {
        if ($callback === null) {
            return max($this->items);
        }

        return $this->reduce(function ($max, $item) use ($callback) {
            $value = is_callable($callback)
                ? $callback($item)
                : (is_array($item) ? ($item[$callback] ?? null) : ($item->$callback ?? null));

            return $max === null || $value > $max ? $value : $max;
        });
    }

    public function median(string|callable|null $callback = null): mixed
    {
        $values = $callback === null
            ? $this->items
            : $this->map(fn($item) => is_callable($callback) ? $callback($item) : $item[$callback])->all();

        sort($values);
        $count = count($values);

        if ($count === 0) {
            return null;
        }

        $middle = (int) floor($count / 2);

        return $count % 2 === 0
            ? ($values[$middle - 1] + $values[$middle]) / 2
            : $values[$middle];
    }

    // ===== BOOLEAN METHODS =====

    public function isEmpty(): bool
    {
        return empty($this->items);
    }

    public function isNotEmpty(): bool
    {
        return !$this->isEmpty();
    }

    public function every(callable $callback): bool
    {
        foreach ($this->items as $key => $item) {
            if (!$callback($item, $key)) {
                return false;
            }
        }

        return true;
    }

    public function some(callable $callback): bool
    {
        foreach ($this->items as $key => $item) {
            if ($callback($item, $key)) {
                return true;
            }
        }

        return false;
    }

    // ===== CONVERSION METHODS =====

    public function toArray(): array
    {
        return $this->items;
    }

    public function toJson(int $options = 0): string
    {
        return json_encode($this->jsonSerialize(), $options);
    }

    public function jsonSerialize(): array
    {
        return $this->items;
    }

    // ===== UTILITY METHODS =====

    public function pipe(callable $callback): mixed
    {
        return $callback($this);
    }

    public function tap(callable $callback): self
    {
        $callback($this);
        return $this;
    }

    public function dd(): never
    {
        var_dump($this->items);
        exit(1);
    }

    public function dump(): self
    {
        var_dump($this->items);
        return $this;
    }

    // ===== ArrayAccess Implementation =====

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

    // ===== Iterator Implementation =====

    public function current(): mixed
    {
        return $this->items[$this->position] ?? null;
    }

    public function key(): mixed
    {
        return $this->position;
    }

    public function next(): void
    {
        ++$this->position;
    }

    public function rewind(): void
    {
        $this->position = 0;
    }

    public function valid(): bool
    {
        return isset($this->items[$this->position]);
    }
}
