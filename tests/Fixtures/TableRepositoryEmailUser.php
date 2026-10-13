<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Tests\Fixtures;

final class TableRepositoryEmailUser extends TableRepositoryBoundUser
{
    protected static string $primaryKey = 'email';
}
