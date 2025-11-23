<?php

// src/Query/Core/DriverResult.php

declare(strict_types=1);

namespace Infocyph\DBLayer\Query\Core;

/**
 * Normalised driver execution result.
 *
 * Core never touches PDOStatement directly; it only ever sees this DTO.
 */
final readonly class DriverResult
{
    /**
     * @param list<array<string,mixed>>|null $rows
     */
    public function __construct(
      public ?array $rows,
      public int $rowCount,
      public ?string $lastInsertId = null,
    ) {
    }

    public function hasRows(): bool
    {
        return $this->rows !== null && $this->rows !== [];
    }
}
