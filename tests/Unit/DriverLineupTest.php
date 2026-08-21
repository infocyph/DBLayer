<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\DBLayer\Driver\MariaDB\MariaDBCompiler;
use Infocyph\DBLayer\Driver\MariaDB\MariaDBDriver;
use Infocyph\DBLayer\Driver\MySQL\MySQLCompiler;
use Infocyph\DBLayer\Driver\MySQL\MySQLDriver;
use Infocyph\DBLayer\Driver\PostgreSQL\PostgreSQLDriver;
use Infocyph\DBLayer\Driver\SQLite\SQLiteDriver;
use Infocyph\DBLayer\Driver\SQLServer\SQLServerCompiler;
use Infocyph\DBLayer\Driver\SQLServer\SQLServerDriver;
use Infocyph\DBLayer\Driver\Support\DriverProfile;
use Infocyph\DBLayer\Driver\Support\DriverRegistry;
use Infocyph\DBLayer\Query\Core\QueryPayload;
use Infocyph\DBLayer\Query\Core\QueryType;
use Infocyph\DBLayer\Schema\Blueprint;
use Infocyph\DBLayer\Schema\SchemaGrammar;

it('keeps five built-in engines on distinct concrete pathways', function (): void {
    expect(DriverRegistry::resolve('mysql'))->toBeInstanceOf(MySQLDriver::class)
        ->and(DriverRegistry::resolve('mariadb'))->toBeInstanceOf(MariaDBDriver::class)
        ->and(DriverRegistry::resolve('pgsql'))->toBeInstanceOf(PostgreSQLDriver::class)
        ->and(DriverRegistry::resolve('mssql'))->toBeInstanceOf(SQLServerDriver::class)
        ->and(DriverRegistry::resolve('sqlite'))->toBeInstanceOf(SQLiteDriver::class)
        ->and(DriverRegistry::resolve('mysql'))->not->toBeInstanceOf(MariaDBDriver::class)
        ->and(DriverRegistry::resolve('mariadb'))->not->toBeInstanceOf(MySQLDriver::class);
});

it('normalizes aliases without collapsing engine identity', function (): void {
    $maria = ConnectionConfig::fromArray(['driver' => 'mariadb', 'database' => 'app', 'username' => 'app']);
    $mssql = ConnectionConfig::fromArray(['driver' => 'sqlsrv', 'database' => 'app', 'username' => 'app']);
    $pgsql = ConnectionConfig::fromArray(['driver' => 'psql', 'database' => 'app', 'username' => 'app']);

    expect($maria->getDriver())->toBe('mariadb')
        ->and($mssql->getDriver())->toBe('mssql')
        ->and($pgsql->getDriver())->toBe('pgsql')
        ->and(DriverRegistry::resolve('pdo_mysql'))->toBeInstanceOf(MySQLDriver::class)
        ->and(DriverRegistry::resolve('pdo_pgsql'))->toBeInstanceOf(PostgreSQLDriver::class)
        ->and(DriverRegistry::resolve('pdo_sqlsrv'))->toBeInstanceOf(SQLServerDriver::class)
        ->and(DriverRegistry::resolve('pdo_sqlite'))->toBeInstanceOf(SQLiteDriver::class);
});

it('keeps MySQL and MariaDB upsert syntax vendor-specific', function (): void {
    $payload = new QueryPayload(QueryType::INSERT, 'users', [], [], [], [], [], [], null, null, [], null, null, [], [['id' => 1, 'email' => 'a@example.test']], [], insertMode: 'upsert', uniqueBy: ['id'], upsertUpdate: ['email']);
    $mysql = (new MySQLCompiler())->compile($payload)->sql;
    $maria = (new MariaDBCompiler())->compile($payload->with(['returning' => ['id']]))->sql;

    expect($mysql)->toContain('AS new_row ON DUPLICATE KEY UPDATE `email` = new_row.`email`')
        ->not->toContain('VALUES(`email`)')
        ->and($maria)->toContain('ON DUPLICATE KEY UPDATE `email` = VALUES(`email`)')
        ->toEndWith('RETURNING `id`');
});

it('compiles SQL Server TOP pagination locks MERGE and OUTPUT natively', function (): void {
    $compiler = new SQLServerCompiler();
    $select = new QueryPayload(QueryType::SELECT, 'users', ['id', 'email'], [], [], [], [], [], 25, null, [], null, null, []);
    $paged = $select->with(['limit' => 25, 'offset' => 50, 'orders' => [['column' => 'id', 'direction' => 'asc']]]);
    $locked = $select->with(['limit' => null, 'lock' => 'update']);
    $upsert = new QueryPayload(QueryType::INSERT, 'users', [], [], [], [], [], [], null, null, [], null, null, [], [['id' => 1, 'email' => 'a@example.test']], [], insertMode: 'upsert', uniqueBy: ['id'], upsertUpdate: ['email'], returning: ['id']);

    expect($compiler->compile($select)->sql)->toBe('SELECT TOP (25) [id], [email] FROM [users]')
        ->and($compiler->compile($paged)->sql)->toContain('ORDER BY [id] ASC OFFSET 50 ROWS FETCH NEXT 25 ROWS ONLY')
        ->and($compiler->compile($locked)->sql)->toContain('FROM [users] WITH (UPDLOCK, ROWLOCK)')
        ->and($compiler->compile($upsert)->sql)->toStartWith('MERGE INTO [users] WITH (HOLDLOCK)')->toContain('OUTPUT INSERTED.[id];');
});

it('declares SQL Server parameter and savepoint semantics explicitly', function (): void {
    expect((new SQLServerDriver())->maxBindParameters())->toBe(2100)
        ->and(DriverProfile::createSavepointSql('mssql', 'trans_1'))->toBe('SAVE TRANSACTION trans_1')
        ->and(DriverProfile::releaseSavepointSql('mssql', 'trans_1'))->toBeNull()
        ->and(DriverProfile::rollbackToSavepointSql('mssql', 'trans_1'))->toBe('ROLLBACK TRANSACTION trans_1');
});

it('parses PHPForge service DSNs without collapsing database identities', function (): void {
    expect(dblayerDsnOptions('mysql:host=127.0.0.1;port=3308;dbname=dblayer;charset=utf8mb4'))
        ->toMatchArray([
            'host' => '127.0.0.1',
            'port' => '3308',
            'dbname' => 'dblayer',
        ])
        ->and(dblayerDsnOptions('sqlsrv:Server=tcp:127.0.0.1,1433;TrustServerCertificate=1'))
        ->toMatchArray([
            'server' => 'tcp:127.0.0.1,1433',
            'trustservercertificate' => '1',
        ])
        ->and(dblayerMsSqlServer('tcp:127.0.0.1,1433'))
        ->toBe(['127.0.0.1', '1433']);
});

it('uses separate schema dialects for all five engines', function (): void {
    foreach (['mysql', 'mariadb', 'pgsql', 'mssql', 'sqlite'] as $driver) {
        $table = new Blueprint('users', true);
        $table->id();
        $table->string('name');
        $sql = (new SchemaGrammar($driver))->compile($table)[0];
        expect($sql)->not->toBe('');
    }
});
