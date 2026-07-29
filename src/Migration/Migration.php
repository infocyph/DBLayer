<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Migration;

use Infocyph\DBLayer\Schema\SchemaManager;

/**
 * One explicitly registered, ordered schema change.
 *
 * Identifiers should be sortable and globally unique, for example
 * "20260729123000_create_accounts".
 */
interface Migration
{
    public function down(SchemaManager $schema, MigrationContext $context): void;

    public function id(): string;

    public function up(SchemaManager $schema, MigrationContext $context): void;
}
