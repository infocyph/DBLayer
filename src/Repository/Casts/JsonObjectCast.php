<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Repository\Casts;

/**
 * JSON object cast for repository rows.
 */
final readonly class JsonObjectCast implements AttributeCast
{
    #[\Override]
    public function get(mixed $value, array $row): mixed
    {
        unset($row);

        if ($value === null || is_object($value)) {
            return $value;
        }

        if (is_array($value)) {
            return (object) $value;
        }

        return is_string($value)
            ? json_decode($value, false, 512, JSON_THROW_ON_ERROR)
            : $value;
    }

    #[\Override]
    public function set(mixed $value, array $attributes): mixed
    {
        unset($attributes);

        if ($value === null || is_string($value)) {
            return $value;
        }

        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}
