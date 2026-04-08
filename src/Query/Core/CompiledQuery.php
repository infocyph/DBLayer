<?php

// src/Query/Core/CompiledQuery.php

declare(strict_types=1);

namespace Infocyph\DBLayer\Query\Core;

/**
 * Result of driver compilation: raw SQL + bound values + type.
 */
final readonly class CompiledQuery
{
    /**
     * @param list<mixed> $bindings
     */
    public function __construct(
        public string $sql,
        public array $bindings,
        public QueryType $type,
    ) {
    }
}
