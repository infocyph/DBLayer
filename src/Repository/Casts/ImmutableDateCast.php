<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Repository\Casts;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Date-only immutable cast.
 */
final readonly class ImmutableDateCast implements AttributeCast
{
    #[\Override]
    public function get(mixed $value, array $row): mixed
    {
        unset($row);

        if ($value === null || $value instanceof DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)->setTime(0, 0);
        }

        if (!is_string($value) || trim($value) === '') {
            return $value;
        }

        return (new DateTimeImmutable($value))->setTime(0, 0);
    }

    #[\Override]
    public function set(mixed $value, array $attributes): mixed
    {
        unset($attributes);

        return $value instanceof DateTimeInterface ? $value->format('Y-m-d') : $value;
    }
}
