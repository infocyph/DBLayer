<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Benchmarks;

use Infocyph\DBLayer\DB;
use PDO;
use PhpBench\Attributes as Bench;

#[Bench\BeforeMethods(['setUpBeforeSubject'])]
#[Bench\Iterations(8)]
#[Bench\Revs(50)]
#[Bench\Warmup(2)]
#[Bench\OutputTimeUnit('microseconds', 2)]
final class DBLayerBench
{
    private const SEED_ROWS = 1000;

    private static bool $initialized = false;

    private static bool $sqliteAvailable = false;

    private static int $subjectCounter = 0;

    private int $currentUserId = 1;

    public function benchBuildSelectSql(): void
    {
        DB::table('users')
            ->select('id', 'name', 'email', 'score')
            ->where('active', 1)
            ->whereBetween('score', [100, 5000])
            ->orderByDesc('id')
            ->limit(25)
            ->toSql();
    }

    public function benchSelectByPrimaryKey(): void
    {
        if (! self::$sqliteAvailable) {
            DB::table('users')
                ->where('id', '=', $this->currentUserId)
                ->toSql();

            return;
        }

        DB::table('users')
            ->where('id', '=', $this->currentUserId)
            ->first();
    }

    public function benchTransactionTwoPointReads(): void
    {
        $firstId = $this->currentUserId;
        $secondId = $firstId === self::SEED_ROWS ? 1 : $firstId + 1;

        if (! self::$sqliteAvailable) {
            DB::table('users')->where('id', '=', $firstId)->toSql();
            DB::table('users')->where('id', '=', $secondId)->toSql();

            return;
        }

        DB::transaction(static function () use ($firstId, $secondId): void {
            DB::select('SELECT score FROM users WHERE id = ?', [$firstId]);
            DB::select('SELECT score FROM users WHERE id = ?', [$secondId]);
        });
    }

    public function benchUpdateSingleColumn(): void
    {
        if (! self::$sqliteAvailable) {
            DB::table('users')
                ->select('id')
                ->where('id', '=', $this->currentUserId)
                ->where('score', '<', $this->currentUserId * 10)
                ->toSql();

            return;
        }

        DB::table('users')
            ->where('id', '=', $this->currentUserId)
            ->update([
                'score' => $this->currentUserId * 10,
            ]);
    }

    public function setUpBeforeSubject(): void
    {
        if (! self::$initialized) {
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
    }

    private static function initializeRuntime(): void
    {
        DB::purge();

        self::$sqliteAvailable = \in_array('sqlite', PDO::getAvailableDrivers(), true);

        if (! self::$sqliteAvailable) {
            // Compile-only fallback for environments without pdo_sqlite.
            DB::addConnection([
                'driver' => 'mysql',
                'host' => '127.0.0.1',
                'database' => 'bench',
                'username' => 'bench',
                'password' => 'bench',
            ], 'bench');
            DB::setDefaultConnection('bench');

            return;
        }

        DB::addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'security' => [
                'enabled' => true,
            ],
        ], 'bench');
        DB::setDefaultConnection('bench');

        self::createSchema();
        self::seedUsers();
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
            }
        });
    }
}
