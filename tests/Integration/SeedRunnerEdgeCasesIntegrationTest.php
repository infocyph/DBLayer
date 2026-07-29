<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Migration\Seeder;
use Infocyph\DBLayer\Migration\SeedContext;
use Infocyph\DBLayer\Migration\SeedRunner;
use Infocyph\DBLayer\Schema\Blueprint;
use Infocyph\DBLayer\Schema\SchemaManager;

function seedEdgeConnection(): Connection
{
    DB::purge();
    DB::addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $connection = DB::connection();
    (new SchemaManager($connection))->create('seed_items', static function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });

    return $connection;
}

it('executes Seeder objects and generator manifests in order', function (): void {
    $connection = seedEdgeConnection();
    $objectSeeder = new class implements Seeder {
        public function run(Connection $connection, SeedContext $context): void
        {
            if ($context->connection() !== $connection) {
                throw new LogicException('Seed context connection mismatch.');
            }

            $connection->table('seed_items')->insert(['name' => 'object']);
        }
    };
    $manifest = static function () use ($objectSeeder): Generator {
        yield $objectSeeder;
        yield static fn(Connection $db) => $db->table('seed_items')->insert(['name' => 'callable']);
    };

    $count = (new SeedRunner($connection))->run($manifest());

    expect($count)->toBe(2)
        ->and(array_column($connection->table('seed_items')->orderBy('id')->get(), 'name'))
        ->toBe(['object', 'callable']);
});

it('retains earlier writes when non-transactional seeding fails', function (): void {
    $connection = seedEdgeConnection();

    expect(fn(): int => (new SeedRunner($connection))->run([
        static fn(Connection $db) => $db->table('seed_items')->insert(['name' => 'retained']),
        static fn() => throw new RuntimeException('non-transactional failure'),
    ], false))->toThrow(RuntimeException::class, 'non-transactional failure')
        ->and($connection->table('seed_items')->count())->toBe(1)
        ->and($connection->table('seed_items')->value('name'))->toBe('retained');
});

it('accepts an empty seed manifest', function (): void {
    $connection = seedEdgeConnection();

    expect((new SeedRunner($connection))->run([]))->toBe(0)
        ->and($connection->table('seed_items')->count())->toBe(0);
});

it('rejects invalid seed definitions without executing later entries', function (): void {
    $connection = seedEdgeConnection();

    expect(fn(): int => (new SeedRunner($connection))->run([
        new stdClass(),
        static fn(Connection $db) => $db->table('seed_items')->insert(['name' => 'never']),
    ]))->toThrow(InvalidArgumentException::class, 'Seeder or be callable')
        ->and($connection->table('seed_items')->count())->toBe(0);
});

it('executes nested seeders synchronously in one seed context', function (): void {
    $connection = seedEdgeConnection();
    $parent = new class implements Seeder {
        public function run(Connection $connection, SeedContext $context): void
        {
            $connection->table('seed_items')->insert(['name' => 'parent-before']);
            $count = $context->call([
                static fn(Connection $db) => $db->table('seed_items')->insert(['name' => 'child']),
            ]);
            if ($count !== 1) {
                throw new LogicException('Nested seeder count mismatch.');
            }

            $connection->table('seed_items')->insert(['name' => 'parent-after']);
        }
    };

    $count = (new SeedRunner($connection))->run([
        $parent,
        static fn(Connection $db) => $db->table('seed_items')->insert(['name' => 'sibling']),
    ]);

    expect($count)->toBe(3)
        ->and(array_column($connection->table('seed_items')->orderBy('id')->get(), 'name'))
        ->toBe(['parent-before', 'child', 'parent-after', 'sibling']);
});

it('rolls back parent and child seed writes together', function (): void {
    $connection = seedEdgeConnection();
    $parent = new class implements Seeder {
        public function run(Connection $connection, SeedContext $context): void
        {
            $connection->table('seed_items')->insert(['name' => 'parent']);
            $context->call([
                static fn(Connection $db) => $db->table('seed_items')->insert(['name' => 'child']),
                static fn() => throw new RuntimeException('nested seed failure'),
            ]);
        }
    };

    expect(fn(): int => (new SeedRunner($connection))->run([$parent]))
        ->toThrow(RuntimeException::class, 'nested seed failure')
        ->and($connection->table('seed_items')->count())->toBe(0);
});
