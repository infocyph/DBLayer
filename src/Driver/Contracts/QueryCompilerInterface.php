<?php

// src/Driver/Contracts/QueryCompilerInterface.php

declare(strict_types=1);

namespace Infocyph\DBLayer\Driver\Contracts;

use Infocyph\DBLayer\Query\Core\CompiledQuery;
use Infocyph\DBLayer\Query\Core\QueryPayload;

/**
 * Driver-specific compiler that turns a QueryPayload into SQL.
 */
interface QueryCompilerInterface
{
    public function compile(QueryPayload $payload): CompiledQuery;
}
