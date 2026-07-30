<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Tests\Fixtures;

use Infocyph\DBLayer\Query\Repository;
use Infocyph\DBLayer\Repository\TableRepository;

final class TableRepositoryUser extends TableRepository
{
    protected static ?string $connection = 'table_repository_conn';

    protected static string $table = 'users';

    protected static function configureRepository(Repository $repository): Repository
    {
        return $repository
            ->forTenant(10)
            ->enableSoftDeletes()
            ->setDefaultOrder('id', 'asc');
    }
}
