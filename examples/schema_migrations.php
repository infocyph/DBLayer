<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Migration\Migration;
use Infocyph\DBLayer\Migration\MigrationContext;
use Infocyph\DBLayer\Migration\MigrationRunner;
use Infocyph\DBLayer\Migration\SeedContext;
use Infocyph\DBLayer\Migration\Seeder;
use Infocyph\DBLayer\Migration\SeedRunner;
use Infocyph\DBLayer\Schema\Blueprint;
use Infocyph\DBLayer\Schema\SchemaManager;

require dirname(__DIR__) . '/vendor/autoload.php';

DB::addConnection(['driver' => 'sqlite', 'database' => ':memory:']);

$migration = new class implements Migration {
    public function down(SchemaManager $schema, MigrationContext $context): void
    {
        $context->checkpoint();
        $schema->dropIfExists('accounts');
    }

    public function id(): string
    {
        return '20260729000000_create_accounts';
    }

    public function up(SchemaManager $schema, MigrationContext $context): void
    {
        $context->checkpoint();
        $schema->create('accounts', static function (Blueprint $table): void {
            $table->id();
            $table->string('email')->unique();
            $table->enum('state', ['pending', 'active'])->default('pending');
            $table->timestampsTz();
            $table->timestamp('verified_at')->nullable();
        });
    }
};

$runner = new MigrationRunner(DB::connection(), [$migration]);
$runner->run();

var_export($runner->status());

$seeder = new class implements Seeder {
    public function run(Connection $connection, SeedContext $context): void
    {
        $connection->table('accounts')->insert([
            'email' => 'owner@example.test',
            'state' => 'active',
        ]);

        $context->call([
            static fn(Connection $database) => $database->table('accounts')->insert([
                'email' => 'support@example.test',
                'state' => 'pending',
            ]),
        ]);
    }
};

(new SeedRunner(DB::connection()))->run([$seeder]);
