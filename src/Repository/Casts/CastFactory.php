<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Repository\Casts;

use InvalidArgumentException;

final class CastFactory
{
    private function __construct()
    {
        throw new InvalidArgumentException('CastFactory cannot be instantiated.');
    }

    public static function compile(string $cast): string|AttributeCast
    {
        $normalized = strtolower(trim($cast));

        if (preg_match('/^decimal:(\d+)$/D', $normalized, $matches) === 1) {
            return new DecimalCast((int) $matches[1]);
        }

        return match ($normalized) {
            'object' => new JsonObjectCast(),
            'immutable_date', 'date_immutable' => new ImmutableDateCast(),
            default => $cast,
        };
    }
}
