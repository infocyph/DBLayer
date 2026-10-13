<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Tests\Fixtures;

use Infocyph\DBLayer\Query\Repository;
use Infocyph\DBLayer\Repository\TableRepository;

class TableRepositoryBoundUser extends TableRepository
{
    protected static ?string $connection = 'table_repository_conn';

    protected static array $creatable = ['email', 'name', 'active'];

    protected static string $table = 'users';

    protected static array $updatable = ['name', 'active'];

    public static int $constructions = 0;

    /** @param array<string,mixed> $attributes */
    public function __construct(array $attributes = [])
    {
        self::$constructions++;
        parent::__construct($attributes);
    }

    protected static function casts(): array
    {
        return ['active' => 'integer'];
    }

    protected static function configureRepository(Repository $repository): Repository
    {
        return $repository->forTenant(10)->enableSoftDeletes();
    }
}
