<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Query;

use Infocyph\DBLayer\Connection\Connection;

/**
 * Instance-first Repository base for scoped runtimes.
 *
 * Higher-level runtimes only need to own a Connection. DBLayer derives the
 * connection-bound Executor and a stateless ResultProcessor internally, while
 * still allowing explicit overrides for specialized consumers.
 */
abstract class ConnectionRepository extends Repository
{
    public function __construct(
        Connection $connection,
        ?Executor $executor = null,
        ?ResultProcessor $results = null,
    ) {
        parent::__construct(
            $connection,
            $executor ?? $connection->getExecutorInstance(),
            $results ?? new ResultProcessor(),
        );
    }
}
