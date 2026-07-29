<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Migration;

use Infocyph\DBLayer\Connection\Connection;

/**
 * Explicit seed unit. DBLayer executes it but does not discover or retain it.
 */
interface Seeder
{
    public function run(Connection $connection, SeedContext $context): void;
}
