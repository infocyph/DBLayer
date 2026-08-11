<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Lock\LockHandle;
use Infocyph\CacheLayer\Cache\Lock\LockProviderInterface;
use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Exceptions\MigrationException;
use Infocyph\DBLayer\Migration\ConditionalMigration;
use Infocyph\DBLayer\Migration\Migration;
use Infocyph\DBLayer\Migration\MigrationContext;
use Infocyph\DBLayer\Migration\MigrationRunner;
use Infocyph\DBLayer\Schema\Blueprint;
use Infocyph\DBLayer\Schema\SchemaManager;

function migrationEdgeConnection(string $name = 'default'): Connection
{
    DB::purge();
    DB::addConnection(['driver' => 'sqlite', 'database' => ':memory:'], $name);

    return DB::connection($name);
}

function migrationEdgeDefinition(
    string $id,
    Closure $up,
    ?Closure $down = null,
): Migration {
    return new class($id, $up, $down ?? static fn() => null) implements Migration {
        public function __construct(
            private readonly string $migrationId,
            private readonly Closure $apply,
            private readonly Closure $revert,
        ) {}

        public function down(SchemaManager $schema, MigrationContext $context): void
        {
            ($this->revert)($schema, $context);
        }

        public function id(): string
        {
            return $this->migrationId;
        }

        public function up(SchemaManager $schema, MigrationContext $context): void
        {
            ($this->apply)($schema, $context);
        }
    };
}

function migrationEdgeCreateTable(string $id, string $table): Migration
{
    return migrationEdgeDefinition(
        $id,
        static fn(SchemaManager $schema) => $schema->create(
            $table,
            static fn(Blueprint $blueprint) => $blueprint->id(),
        ),
        static fn(SchemaManager $schema) => $schema->dropIfExists($table),
    );
}

it('rejects empty and duplicate migration identifiers', function (): void {
    $connection = migrationEdgeConnection();
    $empty = migrationEdgeDefinition('   ', static fn() => null);
    $first = migrationEdgeDefinition('20260729000000_duplicate', static fn() => null);
    $second = migrationEdgeDefinition('20260729000000_duplicate', static fn() => null);

    expect(fn() => new MigrationRunner($connection, [$empty]))
        ->toThrow(MigrationException::class, 'must not be empty')
        ->and(fn() => new MigrationRunner($connection, [$first, $second]))
        ->toThrow(MigrationException::class, 'Duplicate');
});

it('fails rollback and reset when an applied migration is absent from the manifest', function (): void {
    $connection = migrationEdgeConnection();
    $migration = migrationEdgeCreateTable('20260729000000_manifest_entry', 'manifest_items');
    (new MigrationRunner($connection, [$migration]))->run();
    $incomplete = new MigrationRunner($connection, []);

    expect(fn(): array => $incomplete->rollback())
        ->toThrow(MigrationException::class, 'not present')
        ->and(fn(): array => $incomplete->reset(true))
        ->toThrow(MigrationException::class, 'not present');
});

it('rolls back only the requested newest batches', function (): void {
    $connection = migrationEdgeConnection();
    $first = migrationEdgeCreateTable('20260729000000_first_batch', 'first_batch_items');
    $second = migrationEdgeCreateTable('20260729000001_second_batch', 'second_batch_items');
    $schema = new SchemaManager($connection);

    expect((new MigrationRunner($connection, [$first]))->run())->toBe([$first->id()]);

    $runner = new MigrationRunner($connection, [$first, $second]);

    expect($runner->run())->toBe([$second->id()])
        ->and($runner->rollback(0))->toBe([])
        ->and($runner->rollback(1))->toBe([$second->id()])
        ->and($schema->hasTable('first_batch_items'))->toBeTrue()
        ->and($schema->hasTable('second_batch_items'))->toBeFalse()
        ->and($runner->rollback(1))->toBe([$first->id()])
        ->and($schema->hasTable('first_batch_items'))->toBeFalse();
});

it('supports callable destructive authorization with the operation name', function (): void {
    $connection = migrationEdgeConnection();
    $migration = migrationEdgeCreateTable('20260729000000_authorized', 'authorized_items');
    $runner = new MigrationRunner($connection, [$migration]);
    $operations = [];

    $runner->run();

    expect($runner->reset(static function (string $operation) use (&$operations): bool {
        $operations[] = $operation;

        return true;
    }))->toBe([$migration->id()])
        ->and($runner->fresh(static function (string $operation) use (&$operations): bool {
            $operations[] = $operation;

            return true;
        }))->toBe([$migration->id()])
        ->and($operations)->toBe(['reset', 'fresh']);
});

it('rejects invalid migration lock timing', function (
    float $wait,
    float $lease,
): void {
    expect(fn() => new MigrationRunner(
        migrationEdgeConnection(),
        [],
        lockWaitSeconds: $wait,
        leaseSeconds: $lease,
    ))->toThrow(InvalidArgumentException::class);
})->with([
    'negative wait' => [-0.1, 30.0],
    'zero lease' => [0.0, 0.0],
    'negative lease' => [0.0, -1.0],
]);

it('keeps status read-only when no migration ledger exists', function (): void {
    $connection = migrationEdgeConnection();
    $runner = new MigrationRunner(
        $connection,
        [migrationEdgeCreateTable('20260729000000_pending', 'pending_items')],
    );

    expect($runner->status()[0])->toMatchArray([
        'applied' => false,
        'batch' => null,
    ])->and((new SchemaManager($connection))->hasTable('migrations'))->toBeFalse();
});

it('requires explicit approval before dropping all sqlite user tables', function (): void {
    $connection = migrationEdgeConnection();
    $schema = new SchemaManager($connection);
    $schema->create('first_drop_target', static fn(Blueprint $table) => $table->id());
    $schema->create('second_drop_target', static fn(Blueprint $table) => $table->id());

    expect(fn() => $schema->dropAllTables())
        ->toThrow(MigrationException::class, 'explicit authorization')
        ->and($schema->hasTable('first_drop_target'))->toBeTrue()
        ->and($schema->hasTable('second_drop_target'))->toBeTrue();

    $schema->dropAllTables(true);

    expect($schema->tables())->toBe([]);
});

it('always releases migration ownership after a failure', function (): void {
    $connection = migrationEdgeConnection();
    $provider = new class implements LockProviderInterface {
        public int $releases = 0;

        public function acquire(string $key, float $waitSeconds, float $leaseSeconds = 30.0): LockHandle
        {
            return new LockHandle($key, (string) $waitSeconds, leaseSeconds: $leaseSeconds);
        }

        public function refresh(?LockHandle $handle, float $leaseSeconds): bool
        {
            return $handle instanceof LockHandle && $leaseSeconds > 0.0;
        }

        public function release(?LockHandle $handle): void
        {
            if ($handle instanceof LockHandle) {
                $this->releases++;
            }
        }
    };
    $migration = migrationEdgeDefinition(
        '20260729000000_failure',
        static fn() => throw new RuntimeException('expected migration failure'),
    );

    expect(fn(): array => (new MigrationRunner($connection, [$migration], $provider))->run())
        ->toThrow(MigrationException::class, 'expected migration failure')
        ->and($provider->releases)->toBe(1);
});

it('rolls back down work when the migration lease is lost after execution', function (): void {
    $connection = migrationEdgeConnection();
    $provider = new class implements LockProviderInterface {
        public int $refreshes = 0;

        public function acquire(string $key, float $waitSeconds, float $leaseSeconds = 30.0): LockHandle
        {
            return new LockHandle($key, (string) $waitSeconds, leaseSeconds: $leaseSeconds);
        }

        public function refresh(?LockHandle $handle, float $leaseSeconds): bool
        {
            $this->refreshes++;

            return $handle instanceof LockHandle && $leaseSeconds > 0.0 && $this->refreshes < 4;
        }

        public function release(?LockHandle $handle): void {}
    };
    $migration = migrationEdgeCreateTable('20260729000000_down_lease', 'down_lease_items');
    $runner = new MigrationRunner($connection, [$migration], $provider);
    expect($runner->run())->toBe(['20260729000000_down_lease']);

    expect(fn(): array => $runner->rollback())
        ->toThrow(MigrationException::class, 'lease')
        ->and((new SchemaManager($connection))->hasTable('down_lease_items'))->toBeTrue()
        ->and($runner->status()[0]['applied'])->toBeTrue();
});

it('preserves the primary migration failure when lock release also fails', function (): void {
    $connection = migrationEdgeConnection();
    $provider = new class implements LockProviderInterface {
        public function acquire(string $key, float $waitSeconds, float $leaseSeconds = 30.0): LockHandle
        {
            return new LockHandle($key, (string) $waitSeconds, leaseSeconds: $leaseSeconds);
        }

        public function refresh(?LockHandle $handle, float $leaseSeconds): bool
        {
            return $handle instanceof LockHandle && $leaseSeconds > 0.0;
        }

        public function release(?LockHandle $handle): void
        {
            if ($handle === null) {
                return;
            }

            throw new RuntimeException('release failure');
        }
    };
    $migration = migrationEdgeDefinition(
        '20260729000000_primary_and_release_failure',
        static fn() => throw new RuntimeException('primary migration failure'),
    );

    expect(fn(): array => (new MigrationRunner($connection, [$migration], $provider))->run())
        ->toThrow(MigrationException::class, 'primary migration failure Cleanup also failed: release failure');
});

it('documents mysql partial ddl failure behavior when the driver is available', function (): void {
    $config = dblayerRequireDriver('mysql');
    DB::addConnection($config, 'mysql_partial_ddl');
    $connection = DB::connection('mysql_partial_ddl');
    $table = dblayerTable('mysql_partial_ddl');
    $ledger = dblayerTable('mysql_partial_ledger');
    $schema = new SchemaManager($connection);
    $migration = migrationEdgeDefinition(
        '20260729000000_' . $table,
        static function (SchemaManager $schema) use ($table): void {
            $schema->create($table, static fn(Blueprint $blueprint) => $blueprint->id());
            throw new RuntimeException('mysql ddl failure');
        },
        static fn(SchemaManager $schema) => $schema->dropIfExists($table),
    );
    $runner = new MigrationRunner($connection, [$migration], table: $ledger);

    try {
        expect(fn(): array => $runner->run())
            ->toThrow(MigrationException::class, 'mysql ddl failure')
            ->and($schema->hasTable($table))->toBeTrue()
            ->and($runner->status()[0]['applied'])->toBeFalse();
    } finally {
        $schema->dropIfExists($table);
        $schema->dropIfExists($ledger);
    }
});

it('skips conditional migrations without applying or previewing them', function (): void {
    $connection = migrationEdgeConnection();
    $migration = new class implements ConditionalMigration {
        public function down(SchemaManager $schema, MigrationContext $context): void {}

        public function id(): string
        {
            return '20260729000000_disabled_feature';
        }

        public function shouldRun(): bool
        {
            return false;
        }

        public function up(SchemaManager $schema, MigrationContext $context): void
        {
            $context->checkpoint();
            $schema->create('disabled_feature', static fn(Blueprint $table) => $table->id());
        }
    };
    $runner = new MigrationRunner($connection, [$migration]);

    expect($runner->pretend())->toBe([])
        ->and($runner->run())->toBe([])
        ->and($runner->status()[0])->toMatchArray(['applied' => false, 'batch' => null])
        ->and((new SchemaManager($connection))->hasTable('disabled_feature'))->toBeFalse();
});

it('supports stepped batches and exact batch rollback', function (): void {
    $connection = migrationEdgeConnection();
    $first = migrationEdgeCreateTable('20260729000000_step_first', 'step_first');
    $second = migrationEdgeCreateTable('20260729000001_step_second', 'step_second');
    $runner = new MigrationRunner($connection, [$first, $second]);
    $schema = new SchemaManager($connection);

    expect($runner->run(true))->toBe([$first->id(), $second->id()])
        ->and(array_column($runner->status(), 'batch'))->toBe([1, 2])
        ->and($runner->rollbackBatch(1))->toBe([$first->id()])
        ->and($schema->hasTable('step_first'))->toBeFalse()
        ->and($schema->hasTable('step_second'))->toBeTrue()
        ->and($runner->rollbackBatch(2))->toBe([$second->id()])
        ->and($schema->hasTable('step_second'))->toBeFalse();
});

it('rejects invalid exact migration batches', function (): void {
    $runner = new MigrationRunner(migrationEdgeConnection(), []);

    expect(fn(): array => $runner->rollbackBatch(0))
        ->toThrow(InvalidArgumentException::class, 'positive');
});

it('refreshes the schema only after explicit authorization', function (): void {
    $connection = migrationEdgeConnection();
    $migration = migrationEdgeCreateTable('20260729000000_refresh', 'refresh_items');
    $runner = new MigrationRunner($connection, [$migration]);
    $schema = new SchemaManager($connection);
    $runner->run();

    expect(fn(): array => $runner->refresh())
        ->toThrow(MigrationException::class, 'explicit authorization')
        ->and($runner->refresh(true))->toBe([$migration->id()])
        ->and($schema->hasTable('refresh_items'))->toBeTrue()
        ->and($runner->status()[0]['applied'])->toBeTrue();
});
