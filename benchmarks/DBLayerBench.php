<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Benchmarks;

require_once __DIR__ . '/RequiresSqlite.php';

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Query\Core\CompiledQuery;
use Infocyph\DBLayer\Query\Core\QueryType;
use PhpBench\Attributes as Bench;

#[Bench\BeforeMethods(['setUpBeforeSubject'])]
#[Bench\Iterations(8)]
#[Bench\Revs(50)]
#[Bench\Warmup(2)]
#[Bench\OutputTimeUnit('microseconds', 2)]
#[RequiresSqlite]
final class DBLayerBench
{
    private const SEED_ROWS = 5000;

    /** @var list<array{id:int,name:string,email:string,active:int,score:int,created_at:string}> */
    private static array $bulkRows100 = [];

    /** @var list<array{id:int,name:string,email:string,active:int,score:int,created_at:string}> */
    private static array $bulkRows1000 = [];

    private static int $cacheMissCounter = 0;

    private static ?string $cursorToken = null;

    private static bool $initialized = false;

    private static int $subjectCounter = 0;

    private int $currentUserId = 1;

    public function benchAfterCommitCacheInvalidation(): void
    {
        DB::transaction(static function (): void {
            DB::invalidateCacheTagsAfterCommit(['table.users']);
        });
    }

    public function benchArrayResultFiftyRows(): void
    {
        DB::table('users')->orderBy('id')->limit(50)->get();
    }

    public function benchCollectFiftyRows(): void
    {
        DB::table('users')->orderBy('id')->limit(50)->collect();
    }

    public function benchCursorPaginateComposite(): void
    {
        DB::table('users')
            ->where('active', '=', 1)
            ->orderBy('created_at', 'desc')
            ->cursorPaginate(25, self::$cursorToken, 'id', 'asc');
    }

    public function benchEventDispatchOff(): void
    {
        DB::connection('bench_events_off')->withoutQueryEvents(function (): void {
            DB::connection('bench_events_off')->select('select ? as value', [$this->currentUserId]);
        });
    }

    public function benchEventDispatchOn(): void
    {
        $this->runConnectionValueSelect('bench_events_on');
    }

    public function benchExecuteRaw(): void
    {
        DB::connection('bench')->execute('select score from users where id = ?', [$this->currentUserId]);
    }

    public function benchFindManyHundredIds(): void
    {
        DB::repository('users')->findMany(range(1, 100));
    }

    public function benchLazyByIdRows(): void
    {
        foreach (DB::table('users')->where('id', '<=', 49)->lazyById(50) as $row) {
            unset($row);
        }
    }

    public function benchLazyCollectionRows(): void
    {
        DB::table('users')->where('id', '<=', 49)->lazyCollection(50)->all();
    }

    public function benchQueryCacheDisabled(): void
    {
        DB::table('users')->where('id', '=', 1)->get();
    }

    public function benchQueryCacheHitFiftyRows(): void
    {
        $this->runCachedRows(50);
    }

    public function benchQueryCacheHitFiveHundredRows(): void
    {
        $this->runCachedRows(500);
    }

    public function benchQueryCacheHitFiveThousandRows(): void
    {
        $this->runCachedRows(5000);
    }

    public function benchQueryCacheHitOneRow(): void
    {
        $this->runCachedRows(1);
    }

    public function benchQueryCacheMiss(): void
    {
        DB::table('users')
            ->where('id', '=', 1)
            ->cacheFor(60)
            ->cacheTags('benchmark')
            ->cacheKey('bench.miss.' . ++self::$cacheMissCounter)
            ->get();
    }

    public function benchRelationLoadTwentyParents(): void
    {
        $parents = DB::table('users')->select(['id'])->limit(20)->get();
        DB::relations(batchSize: 500)->many(
            $parents,
            'id',
            'posts',
            'user_id',
            'posts',
            ['id', 'user_id', 'title'],
        );
    }

    public function benchRepositoryCachedFind(): void
    {
        DB::repository('users')->cacheFor(60)->find(1);
    }

    public function benchSelectByPrimaryKey(): void
    {
        DB::table('users')
            ->where('id', '=', $this->currentUserId)
            ->first();
    }

    public function benchSelectRowsBuffered(): void
    {
        DB::connection('bench')->select('select id, name from users order by id asc limit 50');
    }

    public function benchStatementCacheOff(): void
    {
        $this->runConnectionValueSelect('bench_cache_off');
    }

    public function benchStatementCacheOn(): void
    {
        $this->runConnectionValueSelect('bench_cache_on');
    }

    public function benchStreamRows(): void
    {
        foreach (DB::connection('bench')->stream('select id, name from users order by id asc limit 50') as $row) {
            unset($row);
        }
    }

    public function benchTransactionTwoPointReads(): void
    {
        $firstId = $this->currentUserId;
        $secondId = $firstId === self::SEED_ROWS ? 1 : $firstId + 1;

        DB::transaction(static function () use ($firstId, $secondId): void {
            DB::select('SELECT score FROM users WHERE id = ?', [$firstId]);
            DB::select('SELECT score FROM users WHERE id = ?', [$secondId]);
        });
    }

    public function benchTypedRunCompiled(): void
    {
        $compiled = new CompiledQuery(
            'select id, score from users where id = ?',
            [$this->currentUserId],
            QueryType::SELECT,
        );

        DB::connection('bench')->runCompiled($compiled);
    }

    public function benchUnbufferedStreamRows(): void
    {
        foreach (DB::connection('bench')->unbufferedStream(
            'select id, name from users order by id asc limit 50',
            fetchSize: 10,
        ) as $row) {
            unset($row);
        }
    }

    public function benchUpdateSingleColumn(): void
    {
        DB::table('users')
            ->where('id', '=', $this->currentUserId)
            ->update([
                'score' => $this->currentUserId * 10,
            ]);
    }

    public function benchUpsertChunkingHundredRows(): void
    {
        DB::table('users')->upsert(self::$bulkRows100, ['id'], ['score']);
    }

    public function benchWithLeastLatencyCachedReplica(): void
    {
        $this->runConnectionSelect('bench_least_latency_cached', 'select 1 as ok');
    }

    public function benchWithLeastLatencyUncachedReplica(): void
    {
        $this->runConnectionSelect('bench_least_latency_uncached', 'select 1 as ok');
    }

    public function benchWithQueryCommentDisabled(): void
    {
        $this->runConnectionValueSelect('bench_comment_off');
    }

    public function benchWithQueryCommentEnabled(): void
    {
        $this->runConnectionValueSelect('bench_comment_on');
    }

    public function setUpBeforeSubject(): void
    {
        if (!self::$initialized) {
            self::initializeRuntime();
            self::$initialized = true;
        }

        self::$subjectCounter++;
        $id = self::$subjectCounter % self::SEED_ROWS;
        $this->currentUserId = $id === 0 ? self::SEED_ROWS : $id;
    }

    private static function createSchema(): void
    {
        DB::statement('DROP TABLE IF EXISTS users');
        DB::statement(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL,
                email TEXT NOT NULL UNIQUE,
                active INTEGER NOT NULL,
                score INTEGER NOT NULL,
                created_at TEXT NOT NULL
            )',
        );
        DB::statement('CREATE INDEX idx_users_active_score ON users (active, score)');
        DB::statement(
            'CREATE TABLE posts (
                id INTEGER PRIMARY KEY,
                user_id INTEGER NOT NULL,
                title TEXT NOT NULL
            )',
        );
        DB::statement('CREATE INDEX idx_posts_user_id ON posts (user_id)');
    }

    private static function initializeRuntime(): void
    {
        DB::purge();

        DB::addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'security' => [
                'enabled' => true,
            ],
        ], 'bench');
        DB::addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'statement_cache_enabled' => false,
        ], 'bench_cache_off');
        DB::addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'statement_cache_enabled' => true,
            'statement_cache_size' => 64,
        ], 'bench_cache_on');
        DB::addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'query_comment_enabled' => false,
        ], 'bench_comment_off');
        DB::addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'query_comment_enabled' => true,
            'query_comment_context' => [
                'app' => 'bench',
                'route' => 'users.list',
            ],
        ], 'bench_comment_on');
        DB::addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ], 'bench_events_on');
        DB::addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ], 'bench_events_off');
        DB::addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'read_strategy' => 'least_latency',
            'read_latency_ttl' => 30,
            'read' => [
                ['database' => ':memory:'],
                ['database' => ':memory:'],
            ],
        ], 'bench_least_latency_cached');
        DB::addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'read_strategy' => 'least_latency',
            'read_latency_ttl' => 0,
            'read' => [
                ['database' => ':memory:'],
                ['database' => ':memory:'],
            ],
        ], 'bench_least_latency_uncached');
        DB::setDefaultConnection('bench');

        self::createSchema();
        self::seedUsers();
        DB::setCache(Cache::memory('dblayer-benchmark'));
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
        foreach ([1, 50, 500, 5000] as $rowCount) {
            DB::table('users')
                ->orderBy('id')
                ->limit($rowCount)
                ->cacheFor(60)
                ->cacheKey('bench.hit.' . $rowCount)
                ->get();
        }
        DB::repository('users')->cacheFor(60)->find(1);
        self::$cursorToken = DB::table('users')
            ->where('active', '=', 1)
            ->orderBy('created_at', 'desc')
            ->cursorPaginate(25, null, 'id', 'asc')
            ->nextCursor();
    }

    private static function seedUsers(): void
    {
        DB::transaction(static function (): void {
            for ($id = 1; $id <= self::SEED_ROWS; $id++) {
                DB::statement(
                    'INSERT INTO users (id, name, email, active, score, created_at)
                     VALUES (?, ?, ?, ?, ?, ?)',
                    [
                        $id,
                        'User ' . $id,
                        'user' . $id . '@example.test',
                        $id % 2,
                        $id * 10,
                        '2026-01-01 00:00:00',
                    ],
                );
                DB::statement(
                    'INSERT INTO posts (id, user_id, title) VALUES (?, ?, ?)',
                    [$id, $id, 'Post ' . $id],
                );
            }
        });
    }

    private function runCachedRows(int $rowCount): void
    {
        DB::table('users')
            ->orderBy('id')
            ->limit($rowCount)
            ->cacheFor(60)
            ->cacheKey('bench.hit.' . $rowCount)
            ->get();
    }

    /**
     * @param array<int,mixed> $bindings
     */
    private function runConnectionSelect(string $connection, string $sql, array $bindings = []): void
    {
        DB::connection($connection)->select($sql, $bindings);
    }

    private function runConnectionValueSelect(string $connection): void
    {
        $this->runConnectionSelect($connection, 'select ? as value', [$this->currentUserId]);
    }
}
