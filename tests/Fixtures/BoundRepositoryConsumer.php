<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Tests\Fixtures;

final readonly class BoundRepositoryConsumer
{
    public function __construct(public TableRepositoryBoundUser $user) {}
}
