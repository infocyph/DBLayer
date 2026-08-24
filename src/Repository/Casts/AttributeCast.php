<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Repository\Casts;

/**
 * Explicit bidirectional repository attribute cast.
 */
interface AttributeCast
{
    /**
     * Transform a persisted value for repository reads.
     *
     * @param array<string,mixed> $row
     */
    public function get(mixed $value, array $row): mixed;

    /**
     * Transform an application value for persistence.
     *
     * @param array<string,mixed> $attributes
     */
    public function set(mixed $value, array $attributes): mixed;
}
