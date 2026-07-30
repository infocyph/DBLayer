<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Tests\Fixtures;

final class RepositoryUserDto
{
    public function __construct(
        public int $id,
        public int $tenant_id,
        public string $email,
        public string $name,
        public int $active = 1,
    ) {
    }
}
