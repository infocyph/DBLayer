# DBLayer - High-Performance PHP Database Layer

A robust, secure, and feature-rich database abstraction layer for PHP 8.4+ with multi-driver compatibility.

## Features

### Core Features
- ✅ **Query Builder** - Fluent, Laravel-like API
- ✅ **Repository Layer** - Thin model-style repositories on top of Query Builder
- ✅ **Connection Manager** - Connection pooling + read replicas
- ✅ **Replica Strategies** - `random`, `round_robin`, `least_latency`
- ✅ **Multi-Driver** - MySQL, PostgreSQL, SQLite
- ✅ **Security** - Multi-layer SQL injection protection
- ✅ **Transactions** - Nested transactions with savepoints
- ✅ **Caching** - Query result caching
- ✅ **Profiling** - Performance monitoring
- ✅ **Events** - Lifecycle hooks
- ✅ **Telemetry** - Query + transaction observability export
- ✅ **Pagination** - Length-aware, simple, and cursor pagination

### Performance
- Microsecond-level benchmark results for core query-builder and transaction paths
- Connection pooling for reuse
- Memory-efficient cursor mode for large datasets

### Security
- Automatic parameterization (all values bound)
- Identifier validation & escaping
- Operator whitelist
- SQL injection pattern detection
- Rate limiting
- Audit logging

## Installation

```bash
composer require infocyph/dblayer
```

## Quick Start

### Basic Configuration

```php
use Infocyph\DBLayer\DB;

// Single connection
DB::addConnection([
    'driver' => 'mysql',
    'host' => 'localhost',
    'port' => 3306,
    'database' => 'myapp',
    'username' => 'root',
    'password' => 'secret',
    'charset' => 'utf8mb4',
]);

// Read replicas
DB::addConnection([
    'driver' => 'mysql',
    'read_strategy' => 'round_robin', // random | round_robin | least_latency
    'read' => [
        ['host' => 'replica1.example.com'],
        ['host' => 'replica2.example.com'],
    ],
    'database' => 'myapp',
    'username' => 'root',
    'password' => 'secret',
]);
```

### Query Controls

```php
// Per-query timeout budget (milliseconds)
DB::withQueryTimeout(500, function () {
    DB::select('select * from users');
});

// Absolute deadline relative to now (seconds)
DB::withQueryDeadline(0.25, function () {
    DB::select('select * from users');
});

// Cooperative cancellation check
DB::withQueryCancellation(
    fn () => false,
    fn () => DB::select('select 1')
);
```

### Telemetry

```php
DB::enableTelemetry();

DB::select('select 1');
DB::beginTransaction();
DB::rollBack();

$snapshot = DB::telemetry();      // read buffer
$exported = DB::flushTelemetry(); // read + clear
```

### Query Builder

```php
// SELECT
$users = DB::table('users')
    ->select('id', 'name', 'email')
    ->where('active', true)
    ->where('age', '>=', 18)
    ->orderBy('created_at', 'desc')
    ->limit(10)
    ->get();

// INSERT
$id = DB::table('users')->insertGetId([
    'name' => 'John Doe',
    'email' => 'john@example.com',
]);

// UPDATE
DB::table('users')
    ->where('id', $id)
    ->update(['name' => 'Jane Doe']);

// DELETE
DB::table('users')->where('id', $id)->delete();

// Complex queries
$orders = DB::table('orders as o')
    ->join('users as u', 'o.user_id', '=', 'u.id')
    ->leftJoin('products as p', 'o.product_id', '=', 'p.id')
    ->where('o.status', 'completed')
    ->where(function($q) {
        $q->where('o.total', '>', 1000)
          ->orWhere('u.vip', true);
    })
    ->select('o.*', 'u.name as user_name', 'p.name as product_name')
    ->get();

// Aggregates
$count = DB::table('users')->count();
$total = DB::table('orders')->sum('amount');
$average = DB::table('products')->avg('price');
```

### Repository Layer

```php
use Infocyph\DBLayer\DB;

$users = DB::repository('users');

$all = $users->all();
$one = $users->find(1);
$active = $users->get(fn ($q) => $q->where('active', 1));
```

### Choosing APIs (DB vs QueryBuilder vs Repository)

- Use `DB` for infrastructure concerns: connections, transactions, retries, telemetry, pooling.
- Use `DB::table()` / `QueryBuilder` for ad-hoc SQL shaping: joins, CTEs, dynamic filters, reporting.
- Use `DB::repository()` for reusable table-level rules: tenant scope, soft deletes, optimistic locking, hooks, casts.

If the same table rules appear in multiple call sites, move that logic into a repository-oriented class.

### TableModel (Model-Like, Non-ORM)

```php
use Infocyph\DBLayer\Model\TableModel;
use Infocyph\DBLayer\Query\QueryBuilder;
use Infocyph\DBLayer\Query\Repository;

final class User extends TableModel
{
    protected static string $table = 'users';
    protected static ?string $connection = 'main';

    protected static function configureRepository(Repository $repository): Repository
    {
        return $repository->enableSoftDeletes()->setDefaultOrder('id', 'desc');
    }

    protected static function configureQuery(QueryBuilder $query): QueryBuilder
    {
        return $query->where('active', '=', 1);
    }
}

$one = User::find(1);                              // Repository method
$rows = User::where('active', '=', 1)->get();     // QueryBuilder method
$stats = User::stats();                            // DB facade method
$reportRows = User::query('reporting')->get();     // Per-call connection override
```

### Transactions

```php
// Automatic transaction
DB::transaction(function() {
    DB::table('accounts')->where('id', 1)->update(['balance' => 900]);
    DB::table('accounts')->where('id', 2)->update(['balance' => 1100]);
    DB::table('transactions')->insert(['amount' => 100]);
});

// Manual transaction
DB::beginTransaction();
try {
    // ... operations
    DB::commit();
} catch (\Exception $e) {
    DB::rollBack();
    throw $e;
}
```

## Testing

```bash
composer tests
composer test:code
composer test:static:strict
composer test:security:strict
composer release:audit
```

Test execution is driver-aware:

- SQLite-only environments run the base test set.
- If MySQL/PostgreSQL are available (via ``DBLAYER_MYSQL_*`` / ``DBLAYER_PGSQL_*`` env vars),
  matrix tests automatically run for those drivers too.

So total test count increases when more drivers are available.

## Benchmarking

```bash
composer bench:run
composer bench:quick
composer bench:chart
```

## Benchmarks

Latest `composer bench:quick` sample output:

| Subject | Mode | RSD |
|---------|------|-----|
| `benchBuildSelectSql` | `11.79μs` | `±0.80%` |
| `benchSelectByPrimaryKey` | `28.20μs` | `±1.27%` |
| `benchTransactionTwoPointReads` | `20.66μs` | `±1.61%` |
| `benchUpdateSingleColumn` | `23.45μs` | `±4.21%` |

Environment for this sample: PHP 8.5.4, xdebug disabled, opcache disabled, 10 revs x 3 iterations.

## Security

DBLayer implements multiple layers of security:

1. **Parameterization** - All values automatically bound
2. **Identifier Validation** - Table/column names validated
3. **Operator Whitelist** - Only safe operators allowed
4. **Injection Detection** - Scans for suspicious patterns
5. **Rate Limiting** - Prevents query flooding
6. **Audit Logging** - Tracks all queries

## Requirements

- PHP 8.4+
- ext-pdo
- ext-pdo_mysql (for MySQL)
- ext-pdo_pgsql (for PostgreSQL)
- ext-pdo_sqlite (for SQLite)

## License

MIT License

## Author

Hasan - [Infocyph](https://infocyph.com)

## Contributing

Contributions are welcome via pull requests and issues.
