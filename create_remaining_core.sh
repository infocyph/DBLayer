#!/bin/bash
echo "Creating remaining core files..."

# Create helpers.php
cat > src/helpers.php << 'EOFPHP'
<?php
declare(strict_types=1);

if (!function_exists('db')) {
    function db(?string $connection = null): Infocyph\DBLayer\Connection {
        return Infocyph\DBLayer\DB::connection($connection);
    }
}

if (!function_exists('collect')) {
    function collect(array $items = []): Infocyph\DBLayer\Collection {
        return new Infocyph\DBLayer\Collection($items);
    }
}
EOFPHP

echo "✓ Created helpers.php"

# Create DB.php facade
cat > src/DB.php << 'EOFPHP'
<?php
declare(strict_types=1);
namespace Infocyph\DBLayer;

class DB {
    private static ?Connection $connection = null;
    private static array $connections = [];
    private static ?Profiler $profiler = null;
    
    public static function addConnection(array $config, string $name = 'default'): Connection {
        $connection = new Connection($config);
        self::$connections[$name] = $connection;
        if ($name === 'default') {
            self::$connection = $connection;
        }
        return $connection;
    }
    
    public static function connection(?string $name = null): Connection {
        if ($name === null) {
            if (self::$connection === null) {
                throw new ConnectionException("No default connection configured");
            }
            return self::$connection;
        }
        if (!isset(self::$connections[$name])) {
            throw new ConnectionException("Connection '{$name}' not found");
        }
        return self::$connections[$name];
    }
    
    public static function table(string $table): QueryBuilder {
        return (new QueryBuilder(self::connection()))->table($table);
    }
    
    public static function query(): QueryBuilder {
        return new QueryBuilder(self::connection());
    }
    
    public static function select(string $query, array $bindings = []): Collection {
        $executor = new Executor(self::connection());
        return new Collection($executor->select($query, $bindings));
    }
    
    public static function selectOne(string $query, array $bindings = []): ?array {
        $executor = new Executor(self::connection());
        return $executor->selectOne($query, $bindings);
    }
    
    public static function insert(string $query, array $bindings = []): bool {
        $executor = new Executor(self::connection());
        return $executor->insert($query, $bindings);
    }
    
    public static function update(string $query, array $bindings = []): int {
        $executor = new Executor(self::connection());
        return $executor->update($query, $bindings);
    }
    
    public static function delete(string $query, array $bindings = []): int {
        $executor = new Executor(self::connection());
        return $executor->delete($query, $bindings);
    }
    
    public static function statement(string $query, array $bindings = []): bool {
        $executor = new Executor(self::connection());
        return $executor->statement($query, $bindings);
    }
    
    public static function transaction(callable $callback, int $attempts = 1): mixed {
        $transaction = new Transaction(self::connection());
        return $transaction->execute($callback, $attempts);
    }
    
    public static function beginTransaction(): void {
        $transaction = new Transaction(self::connection());
        $transaction->begin();
    }
    
    public static function commit(): void {
        $transaction = new Transaction(self::connection());
        $transaction->commit();
    }
    
    public static function rollBack(?int $toLevel = null): void {
        $transaction = new Transaction(self::connection());
        $transaction->rollback($toLevel);
    }
    
    public static function enableQueryLog(): void {
        if (self::$profiler === null) {
            self::$profiler = new Profiler();
        }
        self::$profiler->enable();
    }
    
    public static function disableQueryLog(): void {
        if (self::$profiler !== null) {
            self::$profiler->disable();
        }
    }
    
    public static function getQueryLog(): array {
        return self::$profiler ? self::$profiler->getQueries() : [];
    }
    
    public static function flushQueryLog(): void {
        if (self::$profiler !== null) {
            self::$profiler->reset();
        }
    }
    
    public static function listen(callable $callback): void {
        Events::listen('query.executed', $callback);
    }
}
EOFPHP

echo "✓ Created DB.php"

echo ""
echo "Core files complete!"
echo ""
