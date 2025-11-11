<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\ORM;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/**
 * Model Collection
 *
 * Collection class specifically for Model instances with:
 * - Array-like access and iteration
 * - Higher-order collection methods
 * - Relationship loading
 * - Serialization support
 *
 * @package Infocyph\DBLayer\ORM
 * @author Hasan
 */
class Collection implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable
{
    /**
     * The items contained in the collection
     */
    protected array $items = [];

    /**
     * Create a new collection
     */
    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    /**
     * Convert the collection to its string representation
     */
    public function __toString(): string
    {
        return $this->toJson();
    }

    /**
     * Create a new collection instance if the value isn't one already
     */
    public static function make(mixed $items = []): static
    {
        return new static($items);
    }

    /**
     * Get all of the items in the collection
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * Alias for the "avg" method
     */
    public function average(?string $key = null): float|int|null
    {
        return $this->avg($key);
    }

    /**
     * Get the average value of a given key
     */
    public function avg(?string $key = null): float|int|null
    {
        $count = $this->count();

        if ($count === 0) {
            return null;
        }

        return $this->sum($key) / $count;
    }

    /**
     * Chunk the collection into chunks of the given size
     */
    public function chunk(int $size): static
    {
        if ($size <= 0) {
            return new static();
        }

        $chunks = [];

        foreach (array_chunk($this->items, $size, true) as $chunk) {
            $chunks[] = new static($chunk);
        }

        return new static($chunks);
    }

    /**
     * Collapse the collection of items into a single array
     */
    public function collapse(): static
    {
        $results = [];

        foreach ($this->items as $values) {
            if ($values instanceof static) {
                $values = $values->all();
            } elseif (!is_array($values)) {
                continue;
            }

            $results = array_merge($results, $values);
        }

        return new static($results);
    }

    /**
     * Determine if an item exists in the collection
     */
    public function contains(mixed $key, mixed $operator = null, mixed $value = null): bool
    {
        if (func_num_args() === 1) {
            if ($this->useAsCallable($key)) {
                return $this->first($key) !== null;
            }

            return in_array($key, $this->items, true);
        }

        return $this->contains($this->operatorForWhere(...func_get_args()));
    }

    /**
     * Count the number of items in the collection
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Get the items in the collection that are not present in the given items
     */
    public function diff(mixed $items): static
    {
        return new static(array_diff($this->items, $this->getArrayableItems($items)));
    }

    /**
     * Execute a callback over each item
     */
    public function each(callable $callback): static
    {
        foreach ($this->items as $key => $item) {
            if ($callback($item, $key) === false) {
                break;
            }
        }

        return $this;
    }

    /**
     * Determine if all items pass the given truth test
     */
    public function every(callable $callback): bool
    {
        return array_all($this->items, fn ($item, $key) => $callback($item, $key));
    }

    /**
     * Get all items except for those with the specified keys
     */
    public function except(array $keys): static
    {
        return new static(array_diff_key($this->items, array_flip($keys)));
    }

    /**
     * Run a filter over each of the items
     */
    public function filter(?callable $callback = null): static
    {
        if ($callback) {
            return new static(array_filter($this->items, $callback, ARRAY_FILTER_USE_BOTH));
        }

        return new static(array_filter($this->items));
    }

    /**
     * Get the first item from the collection passing the given truth test
     */
    public function first(?callable $callback = null, mixed $default = null): mixed
    {
        if (is_null($callback)) {
            if (empty($this->items)) {
                return $default;
            }

            foreach ($this->items as $item) {
                return $item;
            }
        }

        foreach ($this->items as $key => $item) {
            if ($callback($item, $key)) {
                return $item;
            }
        }

        return $default;
    }

    /**
     * Get a flattened array of the items in the collection
     */
    public function flatten(int $depth = INF): static
    {
        $result = [];

        foreach ($this->items as $item) {
            if (!is_array($item)) {
                $result[] = $item;
            } else {
                $values = $depth === 1
                    ? array_values($item)
                    : new static($item)->flatten($depth - 1)->all();

                foreach ($values as $value) {
                    $result[] = $value;
                }
            }
        }

        return new static($result);
    }

    /**
     * Flip the items in the collection
     */
    public function flip(): static
    {
        return new static(array_flip($this->items));
    }

    /**
     * Remove an item from the collection by key
     */
    public function forget(string|int $key): static
    {
        unset($this->items[$key]);

        return $this;
    }

    /**
     * Get an item from the collection by key
     */
    public function get(string|int $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->items)) {
            return $this->items[$key];
        }

        return $default;
    }

    /**
     * Get an iterator for the items
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    /**
     * Group an associative array by a field or using a callback
     */
    public function groupBy(string|callable $groupBy): static
    {
        $results = [];

        foreach ($this->items as $key => $value) {
            $groupKey = is_callable($groupBy) ? $groupBy($value, $key) : $this->data_get($value, $groupBy);

            if (!array_key_exists($groupKey, $results)) {
                $results[$groupKey] = new static();
            }

            $results[$groupKey]->items[] = $value;
        }

        return new static($results);
    }

    /**
     * Determine if an item exists in the collection by key
     */
    public function has(string|int|array $key): bool
    {
        $keys = is_array($key) ? $key : func_get_args();
        return array_all($keys, fn ($value) => array_key_exists($value, $this->items));
    }

    /**
     * Concatenate values of a given key as a string
     */
    public function implode(string $value, ?string $glue = null): string
    {
        if ($glue === null) {
            return implode($this->items);
        }

        return implode($glue, $this->pluck($value)->all());
    }

    /**
     * Intersect the collection with the given items
     */
    public function intersect(mixed $items): static
    {
        return new static(array_intersect($this->items, $this->getArrayableItems($items)));
    }

    /**
     * Determine if the collection is empty or not
     */
    public function isEmpty(): bool
    {
        return empty($this->items);
    }

    /**
     * Determine if the collection is not empty
     */
    public function isNotEmpty(): bool
    {
        return !$this->isEmpty();
    }

    /**
     * Convert the object into something JSON serializable
     */
    public function jsonSerialize(): array
    {
        return array_map(function ($value) {
            if ($value instanceof JsonSerializable) {
                return $value->jsonSerialize();
            } elseif ($value instanceof Model) {
                return $value->toArray();
            }

            return $value;
        }, $this->items);
    }

    /**
     * Get the keys of the collection items
     */
    public function keys(): static
    {
        return new static(array_keys($this->items));
    }

    /**
     * Get the last item from the collection
     */
    public function last(?callable $callback = null, mixed $default = null): mixed
    {
        if (is_null($callback)) {
            return empty($this->items) ? $default : end($this->items);
        }

        return new static(array_reverse($this->items, true))->first($callback, $default);
    }

    /**
     * Run a map over each of the items
     */
    public function map(callable $callback): static
    {
        $keys = array_keys($this->items);
        $items = array_map($callback, $this->items, $keys);

        return new static(array_combine($keys, $items));
    }

    /**
     * Run an associative map over each of the items
     */
    public function mapWithKeys(callable $callback): static
    {
        $result = [];

        foreach ($this->items as $key => $value) {
            $assoc = $callback($value, $key);

            foreach ($assoc as $mapKey => $mapValue) {
                $result[$mapKey] = $mapValue;
            }
        }

        return new static($result);
    }

    /**
     * Get the max value of a given key
     */
    public function max(?string $key = null): mixed
    {
        return $this->reduce(function ($result, $item) use ($key) {
            $value = $key ? $this->data_get($item, $key) : $item;

            return is_null($result) || $value > $result ? $value : $result;
        });
    }

    /**
     * Merge the collection with the given items
     */
    public function merge(mixed $items): static
    {
        return new static(array_merge($this->items, $this->getArrayableItems($items)));
    }

    /**
     * Get the min value of a given key
     */
    public function min(?string $key = null): mixed
    {
        return $this->reduce(function ($result, $item) use ($key) {
            $value = $key ? $this->data_get($item, $key) : $item;

            return is_null($result) || $value < $result ? $value : $result;
        });
    }

    /**
     * Determine if an item exists at an offset
     */
    public function offsetExists(mixed $key): bool
    {
        return array_key_exists($key, $this->items);
    }

    /**
     * Get an item at a given offset
     */
    public function offsetGet(mixed $key): mixed
    {
        return $this->items[$key];
    }

    /**
     * Set the item at a given offset
     */
    public function offsetSet(mixed $key, mixed $value): void
    {
        if (is_null($key)) {
            $this->items[] = $value;
        } else {
            $this->items[$key] = $value;
        }
    }

    /**
     * Unset the item at a given offset
     */
    public function offsetUnset(mixed $key): void
    {
        unset($this->items[$key]);
    }

    /**
     * Get the items with the specified keys
     */
    public function only(array $keys): static
    {
        return new static(array_intersect_key($this->items, array_flip($keys)));
    }

    /**
     * Pass the collection to the given callback and return the result
     */
    public function pipe(callable $callback): mixed
    {
        return $callback($this);
    }

    /**
     * Get the values of a given key
     */
    public function pluck(string $value, ?string $key = null): static
    {
        $results = [];

        foreach ($this->items as $item) {
            $itemValue = $this->data_get($item, $value);

            if (is_null($key)) {
                $results[] = $itemValue;
            } else {
                $itemKey = $this->data_get($item, $key);
                $results[$itemKey] = $itemValue;
            }
        }

        return new static($results);
    }

    /**
     * Push an item onto the end of the collection
     */
    public function push(mixed $value): static
    {
        $this->items[] = $value;

        return $this;
    }

    /**
     * Put an item in the collection by key
     */
    public function put(string|int $key, mixed $value): static
    {
        $this->items[$key] = $value;

        return $this;
    }

    /**
     * Reduce the collection to a single value
     */
    public function reduce(callable $callback, mixed $initial = null): mixed
    {
        return array_reduce($this->items, $callback, $initial);
    }

    /**
     * Create a collection of all elements that do not pass a given truth test
     */
    public function reject(callable $callback): static
    {
        return $this->filter(function ($value, $key) use ($callback) {
            return !$callback($value, $key);
        });
    }

    /**
     * Reverse items order
     */
    public function reverse(): static
    {
        return new static(array_reverse($this->items, true));
    }

    /**
     * Search the collection for a given value and return the corresponding key if successful
     */
    public function search(mixed $value, bool $strict = false): int|string|false
    {
        if (!$this->useAsCallable($value)) {
            return array_search($value, $this->items, $strict);
        }

        foreach ($this->items as $key => $item) {
            if ($value($item, $key)) {
                return $key;
            }
        }

        return false;
    }

    /**
     * Slice the underlying collection array
     */
    public function slice(int $offset, ?int $length = null): static
    {
        return new static(array_slice($this->items, $offset, $length, true));
    }

    /**
     * Sort through each item with a callback
     */
    public function sort(?callable $callback = null): static
    {
        $items = $this->items;

        $callback ? uasort($items, $callback) : asort($items);

        return new static($items);
    }

    /**
     * Sort the collection using the given callback
     */
    public function sortBy(string|callable $callback, int $options = SORT_REGULAR, bool $descending = false): static
    {
        $results = [];

        foreach ($this->items as $key => $value) {
            $results[$key] = is_callable($callback) ? $callback($value, $key) : $this->data_get($value, $callback);
        }

        $descending ? arsort($results, $options) : asort($results, $options);

        foreach (array_keys($results) as $key) {
            $results[$key] = $this->items[$key];
        }

        return new static($results);
    }

    /**
     * Sort the collection in descending order using the given callback
     */
    public function sortByDesc(string|callable $callback, int $options = SORT_REGULAR): static
    {
        return $this->sortBy($callback, $options, true);
    }

    /**
     * Get the sum of the given values
     */
    public function sum(string|callable|null $callback = null): float|int
    {
        if (is_null($callback)) {
            return array_sum($this->items);
        }

        return $this->reduce(function ($result, $item) use ($callback) {
            return $result + (is_callable($callback) ? $callback($item) : $this->data_get($item, $callback));
        }, 0);
    }

    /**
     * Take the first or last {$limit} items
     */
    public function take(int $limit): static
    {
        if ($limit < 0) {
            return $this->slice($limit, abs($limit));
        }

        return $this->slice(0, $limit);
    }

    /**
     * Pass the collection to the given callback and then return it
     */
    public function tap(callable $callback): static
    {
        $callback(clone $this);

        return $this;
    }

    /**
     * Get the collection of items as a plain array
     */
    public function toArray(): array
    {
        return array_map(function ($value) {
            return $value instanceof Model ? $value->toArray() : $value;
        }, $this->items);
    }

    /**
     * Get the collection of items as JSON
     */
    public function toJson(int $options = 0): string
    {
        return json_encode($this->jsonSerialize(), $options);
    }

    /**
     * Transform each item in the collection using a callback
     */
    public function transform(callable $callback): static
    {
        $this->items = $this->map($callback)->all();

        return $this;
    }

    /**
     * Return only unique items from the collection array
     */
    public function unique(?string $key = null, bool $strict = false): static
    {
        if (is_null($key)) {
            return new static(array_unique($this->items, SORT_REGULAR));
        }

        $exists = [];
        return $this->reject(function ($item) use ($key, $strict, &$exists) {
            $value = $this->data_get($item, $key);
            $id = $this->getItemKey($value, $strict);

            if (in_array($id, $exists, true)) {
                return true;
            }

            $exists[] = $id;
            return false;
        });
    }

    /**
     * Reset the keys on the underlying array
     */
    public function values(): static
    {
        return new static(array_values($this->items));
    }

    /**
     * Filter items by the given key value pair
     */
    public function where(string $key, mixed $operator = null, mixed $value = null): static
    {
        return $this->filter($this->operatorForWhere(...func_get_args()));
    }

    /**
     * Filter items by the given key value pair
     */
    public function whereIn(string $key, array $values, bool $strict = false): static
    {
        return $this->filter(function ($item) use ($key, $values, $strict) {
            return in_array($this->data_get($item, $key), $values, $strict);
        });
    }

    /**
     * Filter items by the given key value pair using strict comparison
     */
    public function whereInStrict(string $key, array $values): static
    {
        return $this->whereIn($key, $values, true);
    }

    /**
     * Filter items where the value for the given key is not in the given values
     */
    public function whereNotIn(string $key, array $values, bool $strict = false): static
    {
        return $this->reject(function ($item) use ($key, $values, $strict) {
            return in_array($this->data_get($item, $key), $values, $strict);
        });
    }

    /**
     * Filter items where the given key is not null
     */
    public function whereNotNull(string $key): static
    {
        return $this->filter(function ($item) use ($key) {
            return !is_null($this->data_get($item, $key));
        });
    }

    /**
     * Filter items by the given key value pair using strict comparison
     */
    public function whereStrict(string $key, mixed $value): static
    {
        return $this->where($key, '===', $value);
    }

    /**
     * Zip the collection together with one or more arrays
     */
    public function zip(mixed ...$items): static
    {
        $arrayableItems = array_map(function ($items) {
            return $this->getArrayableItems($items);
        }, func_get_args());

        $params = array_merge([function () {
            return new static(func_get_args());
        }, $this->items], $arrayableItems);

        return new static(array_map(...$params));
    }

    /**
     * Get an item from an array or object using "dot" notation
     */
    protected function data_get(mixed $target, string $key, mixed $default = null): mixed
    {
        if ($key === '*') {
            return $target instanceof Model ? $target->toArray() : $target;
        }

        foreach (explode('.', $key) as $segment) {
            if ($target instanceof Model) {
                $target = $target->getAttribute($segment);
            } elseif (is_array($target)) {
                if (!array_key_exists($segment, $target)) {
                    return $default;
                }
                $target = $target[$segment];
            } elseif (is_object($target)) {
                $target = $target->$segment ?? $default;
            } else {
                return $default;
            }
        }

        return $target;
    }

    /**
     * Results array of items from Collection or Arrayable
     */
    protected function getArrayableItems(mixed $items): array
    {
        if (is_array($items)) {
            return $items;
        } elseif ($items instanceof static) {
            return $items->all();
        } elseif ($items instanceof Model) {
            return [$items];
        }

        return (array) $items;
    }

    /**
     * Get a unique key for the given item
     */
    protected function getItemKey(mixed $value, bool $strict): string
    {
        if (is_object($value)) {
            return spl_object_hash($value);
        }

        return ($strict ? 'strict_' : '') . json_encode($value);
    }

    /**
     * Get an operator checker callback
     */
    protected function operatorForWhere(string $key, ?string $operator = null, mixed $value = null): callable
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        return function ($item) use ($key, $operator, $value) {
            $retrieved = $this->data_get($item, $key);

            return match ($operator) {
                '=' => $retrieved == $value,
                '===' => $retrieved === $value,
                '!=' => $retrieved != $value,
                '!==' => $retrieved !== $value,
                '<' => $retrieved < $value,
                '>' => $retrieved > $value,
                '<=' => $retrieved <= $value,
                '>=' => $retrieved >= $value,
                default => false,
            };
        };
    }

    /**
     * Determine if the given value is callable, but not a string
     */
    protected function useAsCallable(mixed $value): bool
    {
        return !is_string($value) && is_callable($value);
    }
}
