<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Repository;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Query\Repository;
use Infocyph\DBLayer\Query\ResultProcessor;

/**
 * Concrete Repository used by TableRepository metadata.
 *
 * Keeps table and primary-key configuration immutable per repository instance
 * without introducing model/entity state.
 */
final class TableQueryRepository extends Repository
{
    public function __construct(
        Connection $connection,
        private readonly string $tableName,
        private readonly string $primaryKeyName,
        ResultProcessor $results,
    ) {
        parent::__construct(
            $connection,
            $connection->getExecutorInstance(),
            $results,
        );
    }

    #[\Override]
    protected function primaryKey(): string
    {
        return $this->primaryKeyName;
    }

    #[\Override]
    protected function table(): string
    {
        return $this->tableName;
    }
}
