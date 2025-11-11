<?php

declare(strict_types=1);

/**
 * DBLayer Helper Functions
 *
 * Global helper functions for database operations.
 * These functions provide convenient shortcuts for common database tasks.
 *
 * @package Infocyph\DBLayer
 * @author Hasan
 */

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\ORM\Collection;
use Infocyph\DBLayer\Query\QueryBuilder;

if (!function_exists('db')) {
    /**
     * Get a database connection instance or query builder
     *
     * @param string|null $table Table name for query builder
     * @param string|null $connection Connection name
     * @return Connection|QueryBuilder
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
     * Execute a callback within a database transaction
     *
     * @param callable $callback Callback to execute
     * @param int $attempts Number of attempts for deadlock recovery
     * @param string|null $connection Connection name
     * @return mixed
     * @throws Throwable
     */
    function db_transaction(callable $callback, int $attempts = 1, ?string $connection = null): mixed
    {
        return DB::connection($connection)->transaction($callback, $attempts);
    }
}

if (!function_exists('db_select')) {
    /**
     * Execute a select query
     *
     * @param string $query SQL query
     * @param array $bindings Query bindings
     * @param string|null $connection Connection name
     * @return array
     */
    function db_select(string $query, array $bindings = [], ?string $connection = null): array
    {
        return DB::connection($connection)->select($query, $bindings);
    }
}

if (!function_exists('db_select_one')) {
    /**
     * Execute a select query and return the first result
     *
     * @param string $query SQL query
     * @param array $bindings Query bindings
     * @param string|null $connection Connection name
     * @return mixed
     */
    function db_select_one(string $query, array $bindings = [], ?string $connection = null): mixed
    {
        $results = db_select($query, $bindings, $connection);
        return $results[0] ?? null;
    }
}

if (!function_exists('db_insert')) {
    /**
     * Execute an insert query
     *
     * @param string $query SQL query
     * @param array $bindings Query bindings
     * @param string|null $connection Connection name
     * @return bool
     */
    function db_insert(string $query, array $bindings = [], ?string $connection = null): bool
    {
        return DB::connection($connection)->insert($query, $bindings);
    }
}

if (!function_exists('db_update')) {
    /**
     * Execute an update query
     *
     * @param string $query SQL query
     * @param array $bindings Query bindings
     * @param string|null $connection Connection name
     * @return int Number of affected rows
     */
    function db_update(string $query, array $bindings = [], ?string $connection = null): int
    {
        return DB::connection($connection)->update($query, $bindings);
    }
}

if (!function_exists('db_delete')) {
    /**
     * Execute a delete query
     *
     * @param string $query SQL query
     * @param array $bindings Query bindings
     * @param string|null $connection Connection name
     * @return int Number of affected rows
     */
    function db_delete(string $query, array $bindings = [], ?string $connection = null): int
    {
        return DB::connection($connection)->delete($query, $bindings);
    }
}

if (!function_exists('db_statement')) {
    /**
     * Execute a general statement
     *
     * @param string $query SQL query
     * @param array $bindings Query bindings
     * @param string|null $connection Connection name
     * @return bool
     */
    function db_statement(string $query, array $bindings = [], ?string $connection = null): bool
    {
        return DB::connection($connection)->statement($query, $bindings);
    }
}

if (!function_exists('db_unprepared')) {
    /**
     * Execute an unprepared statement
     *
     * @param string $query SQL query
     * @param string|null $connection Connection name
     * @return bool
     */
    function db_unprepared(string $query, ?string $connection = null): bool
    {
        return DB::connection($connection)->unprepared($query);
    }
}

if (!function_exists('db_table')) {
    /**
     * Get a query builder for a table
     *
     * @param string $table Table name
     * @param string|null $connection Connection name
     * @return QueryBuilder
     */
    function db_table(string $table, ?string $connection = null): QueryBuilder
    {
        return DB::table($table, $connection);
    }
}

if (!function_exists('db_raw')) {
    /**
     * Create a raw database expression
     *
     * @param mixed $value Raw value
     * @return mixed
     */
    function db_raw(mixed $value): mixed
    {
        return DB::raw($value);
    }
}

if (!function_exists('collect')) {
    /**
     * Create a collection from the given value
     *
     * @param mixed $value
     * @return Collection
     */
    function collect(mixed $value = []): Collection
    {
        return new Collection(is_array($value) ? $value : [$value]);
    }
}

if (!function_exists('value')) {
    /**
     * Return the default value of the given value
     *
     * @param mixed $value
     * @return mixed
     */
    function value(mixed $value): mixed
    {
        return $value instanceof Closure ? $value() : $value;
    }
}

if (!function_exists('tap')) {
    /**
     * Call the given Closure with the given value then return the value
     *
     * @param mixed $value
     * @param callable|null $callback
     * @return mixed
     */
    function tap(mixed $value, ?callable $callback = null): mixed
    {
        if (is_null($callback)) {
            return $value;
        }

        $callback($value);

        return $value;
    }
}

if (!function_exists('with')) {
    /**
     * Return the given value, optionally passed through the given callback
     *
     * @param mixed $value
     * @param callable|null $callback
     * @return mixed
     */
    function with(mixed $value, ?callable $callback = null): mixed
    {
        return is_null($callback) ? $value : $callback($value);
    }
}

if (!function_exists('data_get')) {
    /**
     * Get an item from an array or object using "dot" notation
     *
     * @param mixed $target
     * @param string|array|int|null $key
     * @param mixed $default
     * @return mixed
     */
    function data_get(mixed $target, string|array|int|null $key, mixed $default = null): mixed
    {
        if (is_null($key)) {
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
     * Set an item on an array or object using dot notation
     *
     * @param mixed $target
     * @param string|array $key
     * @param mixed $value
     * @param bool $overwrite
     * @return mixed
     */
    function data_set(mixed &$target, string|array $key, mixed $value, bool $overwrite = true): mixed
    {
        $segments = is_array($key) ? $key : explode('.', $key);
        $segment = array_shift($segments);

        if (empty($segments)) {
            if (!$overwrite && isset($target[$segment])) {
                return $target;
            }

            $target[$segment] = $value;
        } else {
            if (!isset($target[$segment])) {
                $target[$segment] = [];
            }

            data_set($target[$segment], $segments, $value, $overwrite);
        }

        return $target;
    }
}

if (!function_exists('array_accessible')) {
    /**
     * Determine whether the given value is array accessible
     *
     * @param mixed $value
     * @return bool
     */
    function array_accessible(mixed $value): bool
    {
        return is_array($value) || $value instanceof ArrayAccess;
    }
}

if (!function_exists('class_basename')) {
    /**
     * Get the class "basename" of the given object / class
     *
     * @param string|object $class
     * @return string
     */
    function class_basename(string|object $class): string
    {
        $class = is_object($class) ? get_class($class) : $class;

        return basename(str_replace('\\', '/', $class));
    }
}

if (!function_exists('class_uses_recursive')) {
    /**
     * Returns all traits used by a class, its parent classes and trait of their traits
     *
     * @param object|string $class
     * @return array
     */
    function class_uses_recursive(object|string $class): array
    {
        if (is_object($class)) {
            $class = get_class($class);
        }

        $results = [];

        foreach (array_reverse(class_parents($class)) + [$class => $class] as $class) {
            $results += trait_uses_recursive($class);
        }

        return array_unique($results);
    }
}

if (!function_exists('trait_uses_recursive')) {
    /**
     * Returns all traits used by a trait and its traits
     *
     * @param string $trait
     * @return array
     */
    function trait_uses_recursive(string $trait): array
    {
        $traits = class_uses($trait);

        foreach ($traits as $trait) {
            $traits += trait_uses_recursive($trait);
        }

        return $traits;
    }
}

if (!function_exists('blank')) {
    /**
     * Determine if the given value is "blank"
     *
     * @param mixed $value
     * @return bool
     */
    function blank(mixed $value): bool
    {
        if (is_null($value)) {
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
     * Determine if a value is "filled"
     *
     * @param mixed $value
     * @return bool
     */
    function filled(mixed $value): bool
    {
        return !blank($value);
    }
}

if (!function_exists('optional')) {
    /**
     * Provide access to optional objects
     *
     * @param mixed $value
     * @param callable|null $callback
     * @return mixed
     */
    function optional(mixed $value = null, ?callable $callback = null): mixed
    {
        if (is_null($callback)) {
            return $value;
        }

        if (!is_null($value)) {
            return $callback($value);
        }

        return null;
    }
}

if (!function_exists('retry')) {
    /**
     * Retry an operation a given number of times
     *
     * @param int $times
     * @param callable $callback
     * @param int $sleep Milliseconds to sleep between attempts
     * @param callable|null $when
     * @return mixed
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

            if ($sleep) {
                usleep($sleep * 1000);
            }

            goto beginning;
        }
    }
}

if (!function_exists('rescue')) {
    /**
     * Catch a potential exception and return a default value
     *
     * @param callable $callback
     * @param mixed $rescue
     * @param bool $report
     * @return mixed
     */
    function rescue(callable $callback, mixed $rescue = null, bool $report = true): mixed
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            if ($report) {
                // Could log here if logger is available
            }

            return value($rescue);
        }
    }
}

if (!function_exists('transform')) {
    /**
     * Transform the given value if it is present
     *
     * @param mixed $value
     * @param callable $callback
     * @param mixed $default
     * @return mixed
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
     * Determine whether the current environment is Windows based
     *
     * @return bool
     */
    function windows_os(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }
}

if (!function_exists('now')) {
    /**
     * Get the current date and time
     *
     * @param DateTimeZone|string|null $tz
     * @return DateTime
     */
    function now(DateTimeZone|string|null $tz = null): DateTime
    {
        return new DateTime('now', $tz instanceof DateTimeZone ? $tz : new DateTimeZone($tz ?? date_default_timezone_get()));
    }
}

if (!function_exists('today')) {
    /**
     * Get today's date
     *
     * @param DateTimeZone|string|null $tz
     * @return DateTime
     */
    function today(DateTimeZone|string|null $tz = null): DateTime
    {
        return now($tz)->setTime(0, 0);
    }
}
