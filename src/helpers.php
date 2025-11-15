<?php

declare(strict_types=1);

/**
 * DBLayer Helper Functions
 *
 * Global helper functions for database operations.
 *
 * @package Infocyph\DBLayer
 */

use ArrayAccess;
use Closure;
use Countable;
use DateTime;
use DateTimeZone;
use Exception;
use Throwable;
use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Query\QueryBuilder;
use Infocyph\DBLayer\Support\Collection;

if (!function_exists('db')) {
    /**
     * Get a database connection instance or query builder.
     *
     * - db() → Connection
     * - db('users') → QueryBuilder on "users" table
     */
    function db(?string $table = null, ?string $connection = null): Connection|QueryBuilder
    {
        if ($table === null) {
            return DB::connection($connection);
        }

        return DB::table($table, $connection);
    }
}

if (!function_exists('db_transaction')) {
    /**
     * Execute a callback within a database transaction.
     *
     * @throws Throwable
     */
    function db_transaction(callable $callback, int $attempts = 1, ?string $connection = null): mixed
    {
        return DB::connection($connection)->transaction($callback, $attempts);
    }
}

if (!function_exists('db_select')) {
    /**
     * Execute a select query.
     */
    function db_select(string $query, array $bindings = [], ?string $connection = null): array
    {
        return DB::connection($connection)->select($query, $bindings);
    }
}

if (!function_exists('db_select_one')) {
    /**
     * Execute a select query and return the first result.
     */
    function db_select_one(string $query, array $bindings = [], ?string $connection = null): mixed
    {
        $results = db_select($query, $bindings, $connection);

        return $results[0] ?? null;
    }
}

if (!function_exists('db_insert')) {
    /**
     * Execute an insert query.
     */
    function db_insert(string $query, array $bindings = [], ?string $connection = null): bool
    {
        return DB::connection($connection)->insert($query, $bindings);
    }
}

if (!function_exists('db_update')) {
    /**
     * Execute an update query.
     */
    function db_update(string $query, array $bindings = [], ?string $connection = null): int
    {
        return DB::connection($connection)->update($query, $bindings);
    }
}

if (!function_exists('db_delete')) {
    /**
     * Execute a delete query.
     */
    function db_delete(string $query, array $bindings = [], ?string $connection = null): int
    {
        return DB::connection($connection)->delete($query, $bindings);
    }
}

if (!function_exists('db_statement')) {
    /**
     * Execute a general statement.
     */
    function db_statement(string $query, array $bindings = [], ?string $connection = null): bool
    {
        return DB::connection($connection)->statement($query, $bindings);
    }
}

if (!function_exists('db_unprepared')) {
    /**
     * Execute an unprepared statement.
     */
    function db_unprepared(string $query, ?string $connection = null): bool
    {
        return DB::connection($connection)->unprepared($query);
    }
}

if (!function_exists('db_table')) {
    /**
     * Get a query builder for a table.
     */
    function db_table(string $table, ?string $connection = null): QueryBuilder
    {
        return DB::table($table, $connection);
    }
}

if (!function_exists('db_raw')) {
    /**
     * Create a raw database expression.
     */
    function db_raw(mixed $value): mixed
    {
        return DB::raw($value);
    }
}

if (!function_exists('collect')) {
    /**
     * Create a collection from the given value.
     */
    function collect(mixed $value = []): Collection
    {
        return new Collection(is_array($value) ? $value : [$value]);
    }
}

if (!function_exists('value')) {
    /**
     * Return the default value of the given value.
     */
    function value(mixed $value): mixed
    {
        return $value instanceof Closure ? $value() : $value;
    }
}

if (!function_exists('tap')) {
    /**
     * Call the given Closure with the given value then return the value.
     */
    function tap(mixed $value, ?callable $callback = null): mixed
    {
        if ($callback === null) {
            return $value;
        }

        $callback($value);

        return $value;
    }
}

if (!function_exists('with')) {
    /**
     * Return the given value, optionally passed through the given callback.
     */
    function with(mixed $value, ?callable $callback = null): mixed
    {
        return $callback === null ? $value : $callback($value);
    }
}

if (!function_exists('data_get')) {
    /**
     * Get an item from an array or object using "dot" notation.
     */
    function data_get(mixed $target, string|array|int|null $key, mixed $default = null): mixed
    {
        if ($key === null) {
            return $target;
        }

        $key = is_array($key) ? $key : explode('.', (string) $key);

        foreach ($key as $segment) {
            if (is_array($target)) {
                if (!array_key_exists($segment, $target)) {
                    return value($default);
                }

                $target = $target[$segment];
            } elseif ($target instanceof ArrayAccess) {
                if (!isset($target[$segment])) {
                    return value($default);
                }

                $target = $target[$segment];
            } elseif (is_object($target)) {
                if (!isset($target->{$segment})) {
                    return value($default);
                }

                $target = $target->{$segment};
            } else {
                return value($default);
            }
        }

        return $target;
    }
}

if (!function_exists('data_set')) {
    /**
     * Set an item on an array or object using dot notation.
     */
    function data_set(mixed &$target, string|array $key, mixed $value, bool $overwrite = true): mixed
    {
        $segments = is_array($key) ? $key : explode('.', $key);
        $segment = array_shift($segments);

        if ($segment === null) {
            return $target;
        }

        if ($segments === []) {
            if (!$overwrite && is_array($target) && array_key_exists($segment, $target)) {
                return $target;
            }

            if (!is_array($target)) {
                $target = [];
            }

            $target[$segment] = $value;

            return $target;
        }

        if (!is_array($target) || !array_key_exists($segment, $target)) {
            if (!is_array($target)) {
                $target = [];
            }

            $target[$segment] = [];
        }

        data_set($target[$segment], $segments, $value, $overwrite);

        return $target;
    }
}

if (!function_exists('array_accessible')) {
    /**
     * Determine whether the given value is array accessible.
     */
    function array_accessible(mixed $value): bool
    {
        return is_array($value) || $value instanceof ArrayAccess;
    }
}

if (!function_exists('array_all')) {
    /**
     * Determine if all items in the array pass the given truth test.
     *
     * @param array $array
     * @param callable $callback fn(mixed $value, mixed $key): bool
     */
    function array_all(array $array, callable $callback): bool
    {
        foreach ($array as $key => $value) {
            if (!$callback($value, $key)) {
                return false;
            }
        }

        return true;
    }
}

if (!function_exists('class_basename')) {
    /**
     * Get the class "basename" of the given object / class.
     */
    function class_basename(string|object $class): string
    {
        $class = is_object($class) ? get_class($class) : $class;

        return basename(str_replace('\\', '/', $class));
    }
}

if (!function_exists('class_uses_recursive')) {
    /**
     * Returns all traits used by a class, its parent classes and their traits.
     */
    function class_uses_recursive(object|string $class): array
    {
        if (is_object($class)) {
            $class = get_class($class);
        }

        $results = [];

        foreach (array_reverse(class_parents($class)) + [$class => $class] as $cls) {
            $results += trait_uses_recursive($cls);
        }

        return array_unique($results);
    }
}

if (!function_exists('trait_uses_recursive')) {
    /**
     * Returns all traits used by a trait and its traits.
     */
    function trait_uses_recursive(string $trait): array
    {
        $traits = class_uses($trait);

        foreach ($traits as $used) {
            $traits += trait_uses_recursive($used);
        }

        return $traits;
    }
}

if (!function_exists('blank')) {
    /**
     * Determine if the given value is "blank".
     */
    function blank(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        if (is_numeric($value) || is_bool($value)) {
            return false;
        }

        if ($value instanceof Countable) {
            return count($value) === 0;
        }

        return empty($value);
    }
}

if (!function_exists('filled')) {
    /**
     * Determine if a value is "filled".
     */
    function filled(mixed $value): bool
    {
        return !blank($value);
    }
}

if (!function_exists('optional')) {
    /**
     * Provide access to optional objects.
     */
    function optional(mixed $value = null, ?callable $callback = null): mixed
    {
        if ($callback === null) {
            return $value;
        }

        if ($value !== null) {
            return $callback($value);
        }

        return null;
    }
}

if (!function_exists('retry')) {
    /**
     * Retry an operation a given number of times.
     *
     * @throws Exception
     */
    function retry(int $times, callable $callback, int $sleep = 0, ?callable $when = null): mixed
    {
        $attempts = 0;

        beginning:
        $attempts++;
        $times--;

        try {
            return $callback($attempts);
        } catch (Exception $e) {
            if ($times < 1 || ($when && !$when($e))) {
                throw $e;
            }

            if ($sleep > 0) {
                usleep($sleep * 1000);
            }

            goto beginning;
        }
    }
}

if (!function_exists('rescue')) {
    /**
     * Catch a potential exception and return a default value.
     */
    function rescue(callable $callback, mixed $rescue = null, bool $report = true): mixed
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            if ($report) {
                // Hook for logging if needed.
            }

            return value($rescue);
        }
    }
}

if (!function_exists('transform')) {
    /**
     * Transform the given value if it is present.
     */
    function transform(mixed $value, callable $callback, mixed $default = null): mixed
    {
        if (filled($value)) {
            return $callback($value);
        }

        if (is_callable($default)) {
            return $default($value);
        }

        return $default;
    }
}

if (!function_exists('windows_os')) {
    /**
     * Determine whether the current environment is Windows based.
     */
    function windows_os(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }
}

if (!function_exists('now')) {
    /**
     * Get the current date and time.
     */
    function now(DateTimeZone|string|null $tz = null): DateTime
    {
        return new DateTime(
          'now',
          $tz instanceof DateTimeZone
            ? $tz
            : new DateTimeZone($tz ?? date_default_timezone_get())
        );
    }
}

if (!function_exists('today')) {
    /**
     * Get today's date.
     */
    function today(DateTimeZone|string|null $tz = null): DateTime
    {
        return now($tz)->setTime(0, 0);
    }
}
