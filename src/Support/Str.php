<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Support;

/**
 * String Helpers
 *
 * String manipulation utilities:
 * - Case conversion
 * - Pluralization (very naive, English-only)
 * - Slug generation
 * - Random strings
 *
 * Kept intentionally small and framework-agnostic.
 */
final class Str
{
    /**
     * Convert a string to camelCase.
     */
    public static function camel(string $value): string
    {
        $value = static::studly($value);

        return lcfirst($value);
    }

    /**
     * Determine if a string contains any of the given needles.
     *
     * @param string|array<int, string> $needles
     */
    public static function contains(string $haystack, string|array $needles): bool
    {
        foreach ((array) $needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if a string ends with any of the given needles.
     *
     * @param string|array<int, string> $needles
     */
    public static function endsWith(string $haystack, string|array $needles): bool
    {
        foreach ((array) $needles as $needle) {
            if ($needle !== '' && str_ends_with($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Convert a string to kebab-case.
     */
    public static function kebab(string $value): string
    {
        return static::snake($value, '-');
    }

    /**
     * Limit the number of characters in a string.
     */
    public static function limit(string $value, int $limit = 100, string $end = '...'): string
    {
        if ($limit <= 0) {
            return $end;
        }

        if (mb_strlen($value, 'UTF-8') <= $limit) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, $limit, 'UTF-8')) . $end;
    }

    /**
     * Very naive pluralization helper.
     * (Enough for table names / trivial cases; not a full inflector.)
     */
    public static function plural(string $value, int $count = 2): string
    {
        if ($count === 1) {
            return $value;
        }

        if (
          str_ends_with($value, 'y')
          && ! str_ends_with($value, 'ay')
          && ! str_ends_with($value, 'ey')
          && ! str_ends_with($value, 'iy')
          && ! str_ends_with($value, 'oy')
          && ! str_ends_with($value, 'uy')
        ) {
            return substr($value, 0, -1) . 'ies';
        }

        if (! str_ends_with($value, 's')) {
            return $value . 's';
        }

        return $value;
    }

    /**
     * Generate a random alpha-numeric string.
     */
    public static function random(int $length = 16): string
    {
        if ($length <= 0) {
            return '';
        }

        $pool = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $max  = strlen($pool) - 1;

        $result = '';

        for ($i = 0; $i < $length; $i++) {
            $result .= $pool[random_int(0, $max)];
        }

        return $result;
    }

    /**
     * Very naive singularization helper.
     */
    public static function singular(string $value): string
    {
        if (str_ends_with($value, 'ies')) {
            return substr($value, 0, -3) . 'y';
        }

        if (str_ends_with($value, 's')) {
            return substr($value, 0, -1);
        }

        return $value;
    }

    /**
     * Generate a URL friendly "slug" from a given string.
     */
    public static function slug(string $title, string $separator = '-'): string
    {
        // Replace non letters or digits by separator.
        $title = preg_replace('/[^\pL\d]+/u', $separator, $title) ?? '';

        // Trim.
        $title = trim($title, $separator);

        // Lowercase.
        $title = mb_strtolower($title, 'UTF-8');

        // Remove unwanted characters (just in case).
        $title = preg_replace(
          '/[^' . preg_quote($separator, '/') . '\w]+/u',
          '',
          $title
        ) ?? '';

        return $title;
    }

    /**
     * Convert a string to snake_case.
     */
    public static function snake(string $value, string $delimiter = '_'): string
    {
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/\s+/u', '', $value) ?? $value;
        $value = preg_replace('/(.)(?=[A-Z])/u', '$1' . $delimiter, $value) ?? $value;
        $value = mb_strtolower($value, 'UTF-8');

        $value = str_replace(['-', '_'], $delimiter, $value);

        // Collapse multiple delimiters.
        $value = preg_replace('/' . preg_quote($delimiter, '/') . '+/', $delimiter, $value) ?? $value;

        return $value;
    }

    /**
     * Determine if a string starts with any of the given needles.
     *
     * @param string|array<int, string> $needles
     */
    public static function startsWith(string $haystack, string|array $needles): bool
    {
        foreach ((array) $needles as $needle) {
            if ($needle !== '' && str_starts_with($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Convert a string to StudlyCase.
     */
    public static function studly(string $value): string
    {
        $value = str_replace(['-', '_'], ' ', $value);
        $value = ucwords($value);

        return str_replace(' ', '', $value);
    }
}
