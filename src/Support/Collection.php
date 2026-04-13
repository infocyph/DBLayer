<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Support;

use Infocyph\ArrayKit\Collection\Collection as ArrayKitCollection;
use Traversable;

/**
 * Collection
 *
 * DBLayer collection built on top of infocyph/arraykit while preserving the
 * existing DBLayer API surface.
 *
 * @template TKey of array-key
 * @template TValue
 */
final class Collection extends ArrayKitCollection implements \Stringable
{
    /**
     * Create an empty collection.
     */
    public static function empty(): static
    {
        return new static();
    }

    /**
     * Create a collection from value(s).
     */
    #[\Override]
    public static function make(mixed $items = []): static
    {
        if ($items instanceof self) {
            return new static($items->all());
        }

        if ($items instanceof Traversable) {
            return new static(iterator_to_array($items));
        }

        if (! is_array($items)) {
            return new static([$items]);
        }

        return new static($items);
    }

    /**
     * Get the average of a given key (or of values themselves if $key is null).
     */
    public function avg(?string $key = null): int|float
    {
        $items = $this->all();
        if ($items === []) {
            return 0;
        }

        [$total, $count] = $this->aggregateAverage($items, $key);

        if ($count === 0) {
            return 0;
        }

        return $total / $count;
    }

    /**
     * Chunk the collection into smaller collections.
     *
     * @return static<int, static<TKey, TValue>>
     */
    public function chunk(int $size): static
    {
        $items = $this->all();
        if ($size <= 0 || $items === []) {
            return static::empty();
        }

        $chunks = [];

        foreach (array_chunk($items, $size, true) as $chunk) {
            $chunks[] = new static($chunk);
        }

        return new static($chunks);
    }

    /**
     * Determine if an item exists in the collection.
     *
     * If one argument is given, checks if that value exists.
     * If two arguments are given, treats them as key/value and calls where().
     */
    public function contains(mixed $key, mixed $value = null): bool
    {
        if (func_num_args() === 1) {
            return in_array($key, $this->all(), true);
        }

        return $this->where((string) $key, $value)->isNotEmpty();
    }

    /**
     * Filter the collection using a callback.
     *
     * @param callable(TValue, TKey): bool|null $callback
     * @return static<TKey, TValue>
     */
    public function filter(?callable $callback = null): static
    {
        $items = $this->all();
        if ($items === []) {
            return static::empty();
        }

        if ($callback === null) {
            return new static(array_filter($items));
        }

        $results = [];

        foreach ($items as $key => $value) {
            if ($callback($value, $key) === true) {
                $results[$key] = $value;
            }
        }

        return new static($results);
    }

    /**
     * Get the first item, optionally matching a callback.
     *
     * @param callable(TValue, TKey): bool|null $callback
     * @return TValue|mixed
     */
    public function first(?callable $callback = null, mixed $default = null): mixed
    {
        $items = $this->all();
        if ($items === []) {
            return $default;
        }

        if ($callback === null) {
            foreach ($items as $item) {
                return $item;
            }

            return $default;
        }

        foreach ($items as $key => $value) {
            if ($callback($value, $key) === true) {
                return $value;
            }
        }

        return $default;
    }

    /**
     * Determine if the collection is not empty.
     */
    public function isNotEmpty(): bool
    {
        return ! $this->isEmpty();
    }

    /**
     * Map over the collection.
     *
     * @param callable(TValue, TKey): mixed $callback
     * @return static<TKey, mixed>
     */
    public function map(callable $callback): static
    {
        $items = $this->all();
        if ($items === []) {
            return static::empty();
        }

        $results = [];

        foreach ($items as $key => $value) {
            $results[$key] = $callback($value, $key);
        }

        return new static($results);
    }

    /**
     * Get the sum of a given key (or of values themselves if $key is null).
     */
    public function sum(?string $key = null): int|float
    {
        $items = $this->all();
        if ($items === []) {
            return 0;
        }

        $total = 0;

        if ($key === null) {
            foreach ($items as $value) {
                if (is_numeric($value)) {
                    $total += $value + 0;
                }
            }

            return $total;
        }

        foreach ($items as $item) {
            $value = $this->extractFieldValue($item, $key);

            if (is_numeric($value)) {
                $total += $value + 0;
            }
        }

        return $total;
    }

    /**
     * Convert collection to JSON.
     */
    #[\Override]
    public function toJson(int $options = 0): string
    {
        $json = json_encode(
            $this->jsonSerialize(),
            $options | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        return $json === false ? '[]' : $json;
    }

    /**
     * Filter items by key/value.
     *
     * @return static<TKey, TValue>
     */
    public function where(string $key, mixed $value): static
    {
        return $this->filter(
            static fn($item) => (
                (is_array($item) && array_key_exists($key, $item) && $item[$key] === $value)
                || (is_object($item) && isset($item->{$key}) && $item->{$key} === $value)
            ),
        );
    }

    /**
     * @param  array<TKey, TValue>  $items
     * @return array{0:int|float,1:int}
     */
    private function aggregateAverage(array $items, ?string $key): array
    {
        $total = 0;
        $count = 0;

        foreach ($items as $item) {
            $value = $this->extractFieldValue($item, $key);

            if (! is_numeric($value)) {
                continue;
            }

            $total += $value + 0;
            $count++;
        }

        return [$total, $count];
    }

    private function extractFieldValue(mixed $item, ?string $key): mixed
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
