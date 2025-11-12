<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Support;

/**
 * String Helpers
 *
 * String manipulation utilities:
 * - Case conversion
 * - Pluralization
 * - Slug generation
 * - Random strings
 *
 * @package Infocyph\DBLayer\Support
 * @author Hasan
 */
class Str
{
    public static function camel(string $value): string
    {
        return lcfirst(static::studly($value));
    }

    public static function contains(string $haystack, string|array $needles): bool
    {
        return array_any((array) $needles, fn ($needle) => $needle !== '' && str_contains($haystack, $needle));
    }

    public static function endsWith(string $haystack, string|array $needles): bool
    {
        return array_any((array) $needles, fn ($needle) => $needle !== '' && str_ends_with($haystack, $needle));
    }

    public static function kebab(string $value): string
    {
        return static::snake($value, '-');
    }

    public static function limit(string $value, int $limit = 100, string $end = '...'): string
    {
        if (mb_strwidth($value, 'UTF-8') <= $limit) {
            return $value;
        }

        return rtrim(mb_strimwidth($value, 0, $limit, '', 'UTF-8')) . $end;
    }

    public static function plural(string $value, int $count = 2): string
    {
        if ($count === 1) {
            return $value;
        }

        return $value . 's'; // Simple pluralization
    }

    public static function random(int $length = 16): string
    {
        return bin2hex(random_bytes($length / 2));
    }

    public static function singular(string $value): string
    {
        if (str_ends_with($value, 's')) {
            return substr($value, 0, -1);
        }

        return $value;
    }

    public static function slug(string $title, string $separator = '-'): string
    {
        $title = preg_replace('/[^\pL\d]+/u', $separator, $title);
        $title = trim($title, $separator);
        return mb_strtolower($title, 'UTF-8');
    }

    public static function snake(string $value, string $delimiter = '_'): string
    {
        if (!ctype_lower($value)) {
            $value = preg_replace('/\s+/u', '', ucwords($value));
            $value = preg_replace('/(.)(?=[A-Z])/u', '$1' . $delimiter, $value);
            $value = mb_strtolower($value, 'UTF-8');
        }

        return $value;
    }

    public static function startsWith(string $haystack, string|array $needles): bool
    {
        return array_any((array) $needles, fn ($needle) => $needle !== '' && str_starts_with($haystack, $needle));
    }

    public static function studly(string $value): string
    {
        $value = ucwords(str_replace(['-', '_'], ' ', $value));
        return str_replace(' ', '', $value);
    }
}