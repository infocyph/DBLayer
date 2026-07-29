<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Lock\FileLockProvider;
use Infocyph\CacheLayer\Cache\Lock\LockHandle;
use Infocyph\CacheLayer\Cache\Lock\LockProviderInterface;
use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Exceptions\MigrationException;
use Infocyph\DBLayer\Exceptions\QueryException;
use Infocyph\DBLayer\Exceptions\SchemaException;
use Infocyph\DBLayer\Migration\Migration;
use Infocyph\DBLayer\Migration\MigrationContext;
use Infocyph\DBLayer\Migration\MigrationRunner;
use Infocyph\DBLayer\Migration\SeedRunner;
use Infocyph\DBLayer\Schema\Blueprint;
use Infocyph\DBLayer\Schema\SchemaGrammar;
use Infocyph\DBLayer\Schema\SchemaManager;

function schemaTestMigration(
    string $id,
    Closure $up,
    Closure $down,
): Migration {
    return new class($id, $up, $down) implements Migration {
        public function __construct(
            private readonly string $migrationId,
            private readonly Closure $apply,
            private readonly Closure $revert,
        ) {}

        public function id(): string
        {
            return $this->migrationId;
        }

        public function up(SchemaManager $schema, MigrationContext $context): void
        {
            ($this->apply)($schema, $context);
        }

        public function down(SchemaManager $schema, MigrationContext $context): void
        {
            ($this->revert)($schema, $context);
        }
    };
}

function schemaTestConnection(): Connection
{
    DB::purge();
    DB::addConnection(['driver' => 'sqlite', 'database' => ':memory:']);

    return DB::connection();
}

it('runs the schema migration lifecycle on every available driver', function (string $driver): void {
    DB::addConnection(dblayerRequireDriver($driver), 'schema_matrix');
    $connection = DB::connection('schema_matrix');
    $table = dblayerTable('schema_matrix_' . $driver);
    $ledger = dblayerTable('migration_ledger_' . $driver);
    $schema = new SchemaManager($connection);
    $migration = schemaTestMigration(
        '20260729000000_' . $table,
        static fn(SchemaManager $schema) => $schema->create(
            $table,
            static function (Blueprint $blueprint): void {
                $blueprint->id();
                $blueprint->string('name')->index();
            },
        ),
        static fn(SchemaManager $schema) => $schema->dropIfExists($table),
    );
    $runner = new MigrationRunner($connection, [$migration], table: $ledger);

    try {
        expect($runner->run())->toBe([$migration->id()])
            ->and($schema->hasTable($table))->toBeTrue()
            ->and($schema->hasColumn($table, 'name'))->toBeTrue()
            ->and($runner->status()[0]['applied'])->toBeTrue()
            ->and($runner->rollback())->toBe([$migration->id()])
            ->and($schema->hasTable($table))->toBeFalse();
    } finally {
        $schema->dropIfExists($table);
        $schema->dropIfExists($ledger);
    }
})->with('dblayer_drivers');

it('creates alters inspects renames and drops sqlite schemas', function (): void {
    $schema = new SchemaManager(schemaTestConnection());

    $schema->create('accounts', static function (Blueprint $table): void {
        $table->id();
        $table->string('email')->unique();
        $table->boolean('active')->default(true);
        $table->timestamp('created_at')->useCurrent();
    });

    expect($schema->hasTable('accounts'))->toBeTrue()
        ->and($schema->hasColumn('accounts', 'email'))->toBeTrue();

    $schema->table('accounts', static function (Blueprint $table): void {
        $table->string('display_name')->nullable();
        $table->renameColumn('active', 'enabled');
        $table->index('display_name');
    });

    expect($schema->hasColumn('accounts', 'display_name'))->toBeTrue()
        ->and($schema->hasColumn('accounts', 'enabled'))->toBeTrue();

    $schema->rename('accounts', 'users');
    expect($schema->hasTable('users'))->toBeTrue();

    $schema->dropIfExists('users');
    expect($schema->hasTable('users'))->toBeFalse();
});

it('compiles driver-specific column types and rejects unsafe ddl', function (string $driver): void {
    $grammar = new SchemaGrammar($driver);
    $blueprint = new Blueprint('events', true);
    $blueprint->id();
    $blueprint->uuid('public_id')->unique();
    $blueprint->json('payload');
    $blueprint->decimal('amount', 12, 4)->default(0);
    $blueprint->timestamp('created_at')->useCurrent();

    $sql = $grammar->compile($blueprint);

    expect($sql)->not->toBeEmpty()
        ->and(implode(' ', $sql))->toContain('CREATE TABLE');

    if ($driver === 'pgsql') {
        expect($sql[0])->toContain('BIGSERIAL')->toContain('"payload" JSON');
    } elseif ($driver === 'mysql') {
        expect($sql[0])->toContain('AUTO_INCREMENT')->toContain('JSON');
    } else {
        expect($sql[0])->toContain('AUTOINCREMENT')->toContain('"payload" TEXT');
    }
})->with(['mysql', 'pgsql', 'sqlite']);

it('fails explicitly for sqlite foreign-key alteration', function (): void {
    $blueprint = new Blueprint('children');
    $blueprint->foreign('parent_id')->references('id')->on('parents');

    expect(fn(): array => (new SchemaGrammar('sqlite'))->compile($blueprint))
        ->toThrow(SchemaException::class, 'add foreign key');
});

it('runs ordered migrations reports status and rolls back by batch', function (): void {
    $connection = schemaTestConnection();
    $first = schemaTestMigration(
        '20260101000000_create_users',
        static fn(SchemaManager $schema) => $schema->create(
            'users',
            static function (Blueprint $table): void {
                $table->id();
                $table->string('name');
            },
        ),
        static fn(SchemaManager $schema) => $schema->dropIfExists('users'),
    );
    $second = schemaTestMigration(
        '20260101000001_add_email',
        static fn(SchemaManager $schema) => $schema->table(
            'users',
            static fn(Blueprint $table) => $table->string('email')->nullable(),
        ),
        static fn(SchemaManager $schema) => $schema->table(
            'users',
            static fn(Blueprint $table) => $table->dropColumn('email'),
        ),
    );
    $lockDirectory = sys_get_temp_dir() . '/dblayer-migration-locks-' . bin2hex(random_bytes(4));
    $runner = new MigrationRunner(
        $connection,
        [$second, $first],
        new FileLockProvider($lockDirectory),
    );

    expect($runner->run())->toBe([$first->id(), $second->id()])
        ->and($runner->run())->toBe([])
        ->and($runner->status())->sequence(
            fn($row) => $row->id->toBe($first->id())->applied->toBeTrue()->batch->toBe(1),
            fn($row) => $row->id->toBe($second->id())->applied->toBeTrue()->batch->toBe(1),
        )
        ->and($runner->rollback())->toBe([$second->id(), $first->id()])
        ->and((new SchemaManager($connection))->hasTable('users'))->toBeFalse();
});

it('rolls back failed transactional ddl without writing the ledger', function (): void {
    $connection = schemaTestConnection();
    $migration = schemaTestMigration(
        '20260101000000_fail_atomically',
        static function (SchemaManager $schema): void {
            $schema->create('temporary_data', static fn(Blueprint $table) => $table->id());
            throw new RuntimeException('expected failure');
        },
        static fn() => null,
    );
    $runner = new MigrationRunner($connection, [$migration]);

    expect(fn(): array => $runner->run())
        ->toThrow(MigrationException::class, 'expected failure')
        ->and((new SchemaManager($connection))->hasTable('temporary_data'))->toBeFalse()
        ->and($runner->status()[0]['applied'])->toBeFalse();
});

it('requires authorization for destructive migration operations', function (): void {
    $runner = new MigrationRunner(schemaTestConnection(), []);

    expect(fn(): array => $runner->reset())
        ->toThrow(MigrationException::class, 'explicit authorization')
        ->and(fn(): array => $runner->fresh())
        ->toThrow(MigrationException::class, 'explicit authorization');
});

it('resets and freshly rebuilds an explicitly authorized schema', function (): void {
    $connection = schemaTestConnection();
    $migration = schemaTestMigration(
        '20260101000000_create_records',
        static fn(SchemaManager $schema) => $schema->create(
            'records',
            static fn(Blueprint $table) => $table->id(),
        ),
        static fn(SchemaManager $schema) => $schema->dropIfExists('records'),
    );
    $runner = new MigrationRunner($connection, [$migration]);
    $schema = new SchemaManager($connection);

    expect($runner->run())->toBe([$migration->id()])
        ->and($runner->reset(true))->toBe([$migration->id()])
        ->and($schema->hasTable('records'))->toBeFalse()
        ->and($runner->fresh(true))->toBe([$migration->id()])
        ->and($schema->hasTable('records'))->toBeTrue()
        ->and($runner->status()[0]['applied'])->toBeTrue();
});

it('previews pending sql without applying it', function (): void {
    $connection = schemaTestConnection();
    $migration = schemaTestMigration(
        '20260101000000_preview',
        static fn(SchemaManager $schema) => $schema->create(
            'previewed',
            static fn(Blueprint $table) => $table->id(),
        ),
        static fn(SchemaManager $schema) => $schema->dropIfExists('previewed'),
    );
    $runner = new MigrationRunner($connection, [$migration]);

    $preview = $runner->pretend();

    expect($preview[$migration->id()][0]['sql'])->toContain('CREATE TABLE')
        ->and((new SchemaManager($connection))->hasTable('previewed'))->toBeFalse()
        ->and((new SchemaManager($connection))->hasTable('migrations'))->toBeFalse();
});

it('honors connection table prefixes throughout schema and ledger operations', function (): void {
    DB::purge();
    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => 'app_',
    ]);
    $schema = DB::schema();
    $schema->create('groups', static fn(Blueprint $table) => $table->id());
    $schema->create('items', static function (Blueprint $table): void {
        $table->id();
        $table->bigInteger('group_id')->unsigned();
        $table->string('name');
    });

    expect($schema->hasTable('items'))->toBeTrue()
        ->and($schema->tables())->toContain('app_items')
        ->and(DB::table('groups')->insert(['id' => 10]))->toBeTrue()
        ->and(DB::table('items')->insert(['id' => 1, 'group_id' => 10, 'name' => 'before']))->toBeTrue()
        ->and(DB::table('items')->where('id', 1)->value('name'))->toBe('before')
        ->and(DB::table('items')->where('id', 1)->update(['name' => 'after']))->toBe(1)
        ->and(DB::table('items')->select('items.name')->first()['name'])->toBe('after')
        ->and(
            DB::table('items')
                ->join('groups', 'items.group_id', '=', 'groups.id')
                ->where('items.id', 1)
                ->count(),
        )->toBe(1)
        ->and(DB::table('items')->where('id', 1)->delete())->toBe(1)
        ->and(DB::table('items')->count())->toBe(0);

    $schema->drop('items');
    expect($schema->hasTable('items'))->toBeFalse();
});

it('refuses concurrent migration ownership', function (): void {
    $connection = schemaTestConnection();
    $directory = sys_get_temp_dir() . '/dblayer-migration-locks-' . bin2hex(random_bytes(4));
    $provider = new FileLockProvider($directory);
    $key = sprintf(
        'dblayer:migrations:sqlite:%s:migrations',
        hash('xxh3', ':memory:'),
    );
    $handle = $provider->acquire($key, 0.0, 30.0);
    $runner = new MigrationRunner(
        $connection,
        [],
        $provider,
        lockWaitSeconds: 0.0,
    );

    try {
        expect(fn(): array => $runner->run())
            ->toThrow(MigrationException::class, 'Unable to acquire');
    } finally {
        $provider->release($handle);
    }
});

it('stops a migration when its distributed lease is lost', function (): void {
    $connection = schemaTestConnection();
    $provider = new class implements LockProviderInterface {
        public function acquire(string $key, float $waitSeconds, float $leaseSeconds = 30.0): LockHandle
        {
            return new LockHandle($key, (string) $waitSeconds, leaseSeconds: $leaseSeconds);
        }

        public function refresh(?LockHandle $handle, float $leaseSeconds): bool
        {
            return $handle === null && $leaseSeconds > 0.0;
        }

        public function release(?LockHandle $handle): void
        {
            if ($handle === null) {
                return;
            }
        }
    };
    $migration = schemaTestMigration(
        '20260101000000_never_applied',
        static fn(SchemaManager $schema) => $schema->create(
            'lease_lost',
            static fn(Blueprint $table) => $table->id(),
        ),
        static fn() => null,
    );
    $runner = new MigrationRunner($connection, [$migration], $provider);

    expect(fn(): array => $runner->run())
        ->toThrow(MigrationException::class, 'lease')
        ->and((new SchemaManager($connection))->hasTable('lease_lost'))->toBeFalse();
});

it('executes explicit seeders transactionally', function (): void {
    $connection = schemaTestConnection();
    $schema = new SchemaManager($connection);
    $schema->create('labels', static function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });

    $count = (new SeedRunner($connection))->run([
        static fn(Connection $db) => $db->table('labels')->insert(['name' => 'first']),
        static fn(Connection $db) => $db->table('labels')->insert(['name' => 'second']),
    ]);

    expect($count)->toBe(2)
        ->and($connection->table('labels')->count())->toBe(2);
});

it('rolls back all seed writes when a transactional seeder fails', function (): void {
    $connection = schemaTestConnection();
    $schema = new SchemaManager($connection);
    $schema->create('labels', static function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });

    expect(fn(): int => (new SeedRunner($connection))->run([
        static fn(Connection $db) => $db->table('labels')->insert(['name' => 'discarded']),
        static fn() => throw new RuntimeException('seed failure'),
    ]))->toThrow(RuntimeException::class, 'seed failure')
        ->and($connection->table('labels')->count())->toBe(0);
});

it('creates and enforces the extended portable schema catalog on sqlite', function (): void {
    $connection = schemaTestConnection();
    $schema = new SchemaManager($connection);

    $schema->create('extended_catalog', static function (Blueprint $table): void {
        $table->id();
        $table->char('country', 2);
        $table->tinyInteger('tiny_value');
        $table->mediumInteger('medium_value');
        $table->tinyText('tiny_text');
        $table->mediumText('medium_text');
        $table->longText('long_text');
        $table->double('double_value');
        $table->time('time_value', 3);
        $table->timeTz('time_tz_value', 3);
        $table->dateTimeTz('datetime_tz_value', 3);
        $table->timestampTz('timestamp_tz_value', 3);
        $table->year('year_value');
        $table->jsonb('jsonb_value');
        $table->enum('state', ['new', 'ready']);
        $table->ipAddress('ip_value');
        $table->macAddress('mac_value');
        $table->ulid('ulid_value');
        $table->integer('price');
        $table->integer('quantity');
        $table->integer('total')->storedAs('price * quantity');
        $table->integer('total_virtual')->virtualAs('price * quantity');
    });

    expect($connection->table('extended_catalog')->insert([
        'country' => 'BD',
        'tiny_value' => 1,
        'medium_value' => 2,
        'tiny_text' => 'tiny',
        'medium_text' => 'medium',
        'long_text' => 'long',
        'double_value' => 12.5,
        'time_value' => '08:00:00.000',
        'time_tz_value' => '08:00:00+06:00',
        'datetime_tz_value' => '2026-07-29 08:00:00+06:00',
        'timestamp_tz_value' => '2026-07-29 08:00:00+06:00',
        'year_value' => 2026,
        'jsonb_value' => '{"ready":true}',
        'state' => 'ready',
        'ip_value' => '127.0.0.1',
        'mac_value' => '00:11:22:33:44:55',
        'ulid_value' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        'price' => 7,
        'quantity' => 6,
    ]))->toBeTrue()
        ->and($connection->table('extended_catalog')->value('total'))->toBe(42)
        ->and($connection->table('extended_catalog')->value('total_virtual'))->toBe(42);

    expect(fn(): bool => $connection->table('extended_catalog')->insert([
        'country' => 'BD',
        'tiny_value' => 1,
        'medium_value' => 2,
        'tiny_text' => 'tiny',
        'medium_text' => 'medium',
        'long_text' => 'long',
        'double_value' => 12.5,
        'time_value' => '08:00:00',
        'time_tz_value' => '08:00:00',
        'datetime_tz_value' => '2026-07-29 08:00:00',
        'timestamp_tz_value' => '2026-07-29 08:00:00',
        'year_value' => 2026,
        'jsonb_value' => '{}',
        'state' => 'invalid',
        'ip_value' => '127.0.0.1',
        'mac_value' => '00:11:22:33:44:55',
        'ulid_value' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        'price' => 1,
        'quantity' => 1,
    ]))->toThrow(QueryException::class);
});
