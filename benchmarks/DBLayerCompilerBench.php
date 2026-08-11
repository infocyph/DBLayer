<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Benchmarks;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\DBLayer\Schema\Blueprint;
use Infocyph\DBLayer\Schema\SchemaGrammar;
use PhpBench\Attributes as Bench;

#[Bench\BeforeMethods(['setUpBeforeSubject'])]
#[Bench\Iterations(8)]
#[Bench\Revs(50)]
#[Bench\Warmup(2)]
#[Bench\OutputTimeUnit('microseconds', 2)]
final class DBLayerCompilerBench
{
    /** @var list<array{id:int,name:string,email:string,active:int,score:int,created_at:string}> */
    private static array $bulkRows100 = [];

    /** @var list<array{id:int,name:string,email:string,active:int,score:int,created_at:string}> */
    private static array $bulkRows1000 = [];

    private static ?Connection $connection = null;

    public function benchBuildSelectSql(): void
    {
        self::connection()
            ->table('users')
            ->select('id', 'name', 'email', 'score')
            ->where('active', 1)
            ->whereBetween('score', [100, 5000])
            ->orderByDesc('id')
            ->limit(25)
            ->toSql();
    }

    public function benchBulkInsertCompileHundredRows(): void
    {
        $connection = self::connection();
        $connection->getCompiler()->compile(
            $connection->table('users')->toInsertPayload(self::$bulkRows100),
        );
    }

    public function benchBulkInsertCompileThousandRows(): void
    {
        $connection = self::connection();
        $connection->getCompiler()->compile(
            $connection->table('users')->toInsertPayload(self::$bulkRows1000),
        );
    }

    public function benchSchemaCompileCreate(): void
    {
        $blueprint = new Blueprint('benchmark_records', true);
        $blueprint->id();
        $blueprint->uuid('public_id')->unique();
        $blueprint->string('name')->index();
        $blueprint->json('payload')->nullable();
        $blueprint->timestamp('created_at')->useCurrent();

        (new SchemaGrammar('sqlite'))->compile($blueprint);
    }

    public function setUpBeforeSubject(): void
    {
        if (self::$bulkRows1000 !== []) {
            return;
        }

        self::$bulkRows1000 = array_map(
            static fn(int $id): array => [
                'id' => $id,
                'name' => 'Bulk ' . $id,
                'email' => 'bulk' . $id . '@example.test',
                'active' => $id % 2,
                'score' => $id * 10,
                'created_at' => '2026-01-01 00:00:00',
            ],
            range(2_001, 3_000),
        );
        self::$bulkRows100 = array_slice(self::$bulkRows1000, 0, 100);
    }

    private static function connection(): Connection
    {
        return self::$connection ??= new Connection(
            new ConnectionConfig([
                'driver' => 'sqlite',
                'database' => ':memory:',
            ]),
            'compiler-benchmark',
        );
    }
}
