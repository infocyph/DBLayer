<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Migration;

/**
 * Opt-in contract for migrations controlled by an explicit feature decision.
 */
interface ConditionalMigration extends Migration
{
    public function shouldRun(): bool;
}
