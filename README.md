# DBLayer - High-Performance PHP Database Layer

[![Security & Standards](https://github.com/infocyph/DBLayer/actions/workflows/security-standards.yml/badge.svg)](https://github.com/infocyph/DBLayer/actions/workflows/security-standards.yml)
![Packagist Downloads](https://img.shields.io/packagist/dt/infocyph/DBLayer?color=green\&link=https%3A%2F%2Fpackagist.org%2Fpackages%2Finfocyph%2FDBLayer)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](https://opensource.org/licenses/MIT)
![Packagist Version](https://img.shields.io/packagist/v/infocyph/DBLayer)
![Packagist PHP Version](https://img.shields.io/packagist/dependency-v/infocyph/DBLayer/php)
![GitHub Code Size](https://img.shields.io/github/languages/code-size/infocyph/DBLayer)
[![Documentation](https://img.shields.io/badge/Documentation-DBLayer-blue?logo=readthedocs&logoColor=white)](https://docs.infocyph.com/projects/DBLayer/)

A high-performance, secure database layer for PHP 8.4+. DBLayer combines a
QueryBuilder, reusable repository policies, explicit static table ergonomics,
multi-driver execution, and operational controls without becoming an ORM.

## Features

### Core Features
- **Query Builder** - Fluent, Laravel-like API
- **Repository Layer** - Reusable table policies, casts, hooks, tenancy, soft deletes, and optimistic locking
- **TableRepository** - Static repository and QueryBuilder ergonomics with explicit infrastructure access
- **Connection Manager** - Connection pooling + read replicas
- **Replica Strategies** - `random`, `round_robin`, `least_latency`, `weighted`
- **Multi-Driver** - MySQL, PostgreSQL, SQLite
- **Security** - Multi-layer SQL injection protection
- **Transactions** - Nested transactions with savepoints
- **Caching** - Opt-in CacheLayer 3 query results with tags and commit-safe invalidation
- **Profiling** - Performance monitoring
- **Events** - Lifecycle hooks
- **Telemetry** - Query + transaction observability export
- **Performance diagnostics** - Native execution plans and query-shape reports
- **Pagination** - Offset, composite keyset, opaque next/previous cursors, and resumable chunks
- **Schema & Migrations** - Portable type catalog plus explicit driver-specific types, generated/spatial columns, deterministic ledger, dry runs, leases, conditional/stepped execution, rollback/reset/refresh/fresh
- **Seeding** - Explicit transactional seed trees with synchronous nested composition
- **Relations** - Explicit bounded one, many, and many-to-many array projection without ORM behavior

Schema UUID/ULID helpers define storage only. Applications may generate
portable UUIDv7/ULID values with `infocyph/uid`, or deliberately configure a
driver-specific database expression default. DBLayer does not add an identifier
generator to the normal query path.

### Performance
- Reproducible PHPBench scenarios for relative hot-path comparisons
- Connection pooling for reuse
- Bounded `lazyById()` batches and driver-aware unbuffered streaming
- Bounded query-log, profiler, telemetry, and local rate-limit state for persistent workers

### Security
- Automatic parameterization for Query Builder values; explicit raw SQL APIs remain available
- Identifier validation & escaping
- Operator whitelist
- SQL injection pattern detection
- Config-driven hardening with TLS policy controls
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
    'collation' => 'utf8mb4_unicode_ci',
]);

// Read replicas
DB::addConnection([
    'driver' => 'mysql',
    'read_strategy' => 'round_robin', // random | round_robin | least_latency | weighted
    'read' => [
        ['host' => 'replica1.example.com'],
        ['host' => 'replica2.example.com'],
    ],
    'database' => 'myapp',
    'username' => 'root',
    'password' => 'secret',
]);
```

### Effective Connection Configuration

`ConnectionConfig` normalizes aliases and applies defaults once. Built-in
driver settings are validated before PDO is opened; a recognized setting used
with the wrong driver throws instead of being silently ignored.

| Scope | Effective keys |
| --- | --- |
| All drivers | `database`, `prefix`, `options`, `timeout`, `persistent`, `write`, `read`, replica selection/timing, statement caching, query comments, `sticky`, and SQL `security` |
| MySQL/MariaDB | `host`, `port`, `username`, `password`, `charset`, `collation`, `unix_socket`, `ssl_ca`, `ssl_cert`, `ssl_key`, `ssl_verify_server_cert` |
| PostgreSQL | `host`, `port`, `username`, `password`, `charset`, `schema`, `sslmode` |
| SQLite | `database`; network, credential, schema, charset, collation, and TLS settings are rejected |

MySQL TLS files are translated to `Pdo\Mysql::ATTR_SSL_*` constructor
attributes. `collation` is applied with the connection initialization command.
MySQL does not accept PostgreSQL's `sslmode`; use the MySQL TLS keys above and
set `security.require_tls=true` when encryption is mandatory.

PostgreSQL `charset`, `schema`, `timeout`, and `sslmode` are written into the
libpq DSN as `client_encoding`, startup `search_path`, `connect_timeout`, and
`sslmode`. Supported SSL modes are `disable`, `allow`, `prefer`, `require`,
`verify-ca`, and `verify-full`.

`timeout` maps to the native connection-time mechanism: PDO timeout attributes
for MySQL/SQLite and `connect_timeout` for PostgreSQL. PDO and native-client
versions may still impose driver-specific timeout and persistent-connection
semantics.

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
$shapes = DB::queryShapeReport(); // grouped by parameterized SQL fingerprint
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
$orders = DB::table('orders')->as('o')
    ->joinAs('users', 'u', 'o.user_id', '=', 'u.id')
    ->leftJoinAs('products', 'p', 'o.product_id', '=', 'p.id')
    ->where('o.status', 'completed')
    ->where(function($q) {
        $q->where('o.total', '>', 1000)
          ->orWhere('u.vip', true);
    })
    ->select('o.*')
    ->addSelectAs('u.name', 'user_name')
    ->addSelectAs('p.name', 'product_name')
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

### Collections and Result Caching

Array results remain the default. Opt into DBLayer 5 collection APIs or
bounded lazy transformations explicitly:

```php
$collection = DB::table('users')->where('active', '=', 1)->collect();
$lazy = DB::table('users')->orderBy('id')->lazyCollection(chunkSize: 500);
```

Query result caching is also explicit. Cache misses are resolved through
CacheLayer 3 `remember()`; writes invalidate conservative table tags only after
the surrounding transaction commits.

```php
use Infocyph\CacheLayer\Cache\Cache;

DB::setCache(Cache::memory('application'));

$active = DB::table('users')
    ->where('active', '=', 1)
    ->cacheFor(120)
    ->cacheTags('users')
    ->get();
```

Transactions, sticky read-after-write state, locking reads, cursors, streaming,
unsupported bindings, and unsafe raw shapes bypass shared query caching.

### Choosing APIs (DB vs QueryBuilder vs Repository)

- Use `DB` for infrastructure concerns: connections, transactions, retries, telemetry, pooling.
- Use `DB::table()` / `QueryBuilder` for ad-hoc SQL shaping: joins, CTEs, dynamic filters, reporting.
- Use `DB::repository()` for reusable table-level rules: tenant scope, soft deletes, optimistic locking, hooks, casts.

If the same table rules appear in multiple call sites, move that logic into a repository-oriented class.

### TableRepository (Repository-Oriented, Non-ORM)

```php
use Infocyph\DBLayer\Repository\TableRepository;
use Infocyph\DBLayer\Query\QueryBuilder;
use Infocyph\DBLayer\Query\Repository;

final class User extends TableRepository
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
$stats = DB::stats('main');                        // Infrastructure stays explicit
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
composer ic:tests
composer ic:test:code
composer ic:test:static
composer ic:test:security
composer ic:release:guard
```

Test execution is driver-aware:

- SQLite-only environments run the base test set.
- If MySQL/PostgreSQL are available (via ``DBLAYER_MYSQL_*`` / ``DBLAYER_PGSQL_*`` env vars),
  matrix tests automatically run for those drivers too.

So total test count increases when more drivers are available.

## Benchmarking

```bash
composer ic:bench:run
composer ic:bench:quick
composer ic:bench:chart
```

## Benchmarks

Use repeated runs on the same production-representative environment. The
included PHPBench subjects compare component hot paths; they do not establish
end-to-end application RPM. See `docs/benchmarks.rst` for interpretation and
reporting requirements.

## Security

DBLayer implements multiple layers of security:

1. **Parameterization** - Query Builder values are bound; raw SQL remains explicit
2. **Identifier Validation** - Table/column names validated
3. **Operator Whitelist** - Only safe operators allowed
4. **Injection Detection** - Scans for suspicious patterns
5. **Rate Limiting** - Prevents query flooding
6. **Audit Logging** - Optional, bounded query logging

Hardening controls:

- `DB::hardenProduction()` sets `enabled=true`, `strict_identifiers=true`, `require_tls=true`.
- `SecurityMode::OFF` is blocked by default (allow explicitly with `Security::allowInsecureMode(true)`).
- `security.enabled=false` and `security.require_tls=false` require `security.allow_insecure=true`.

## Requirements

- PHP 8.4+
- ext-pdo
- Composer installs `infocyph/DBLayer ^5.1`, `infocyph/cachelayer ^3.1`, and
  `psr/log ^3.0.2`
- ext-pdo_mysql (for MySQL)
- ext-pdo_pgsql (for PostgreSQL)
- ext-pdo_sqlite (for SQLite)

## Security

Do not disclose suspected vulnerabilities in a public issue, discussion or pull request. Follow [SECURITY.md](SECURITY.md) and use [GitHub private vulnerability reporting](https://github.com/infocyph/DBLayer/security/advisories/new).

DBLayer is protected by [PHPForge](https://github.com/infocyph/PHPForge), which provides automated tests, static and taint analysis, dependency auditing, architecture checks and release-readiness gates. Automated controls do not replace responsible disclosure or manual review.


---

<div align="center">
  <sub><strong>Made with ❤️ for the PHP community</strong></sub><br />
  <sub><a href="LICENSE">MIT Licensed</a></sub><br />
  <a href="https://docs.infocyph.com/projects/DBLayer/">Documentation</a> •
  <a href="SECURITY.md">Security</a> •
  <a href="CODE_OF_CONDUCT.md">Code of Conduct</a> •
  <a href="CONTRIBUTING.md">Contributing</a><br />
  <span title="Issue templates" aria-label="Issue templates">🗂️</span>
  <a href="https://github.com/infocyph/DBLayer/issues/new?template=bug_report.yml">Bug</a> •
  <a href="https://github.com/infocyph/DBLayer/issues/new?template=feature_request.yml">Feature</a> •
  <a href="https://github.com/infocyph/DBLayer/issues/new?template=docs_improvement.yml">Documentation</a> •
  <a href="https://github.com/infocyph/DBLayer/issues/new?template=question.yml">Question</a> •
  <a href="https://github.com/infocyph/DBLayer/issues/new?template=ci_failure.yml">CI failure</a><br />
  <span title="Pull request templates" aria-label="Pull request templates">🔀</span>
  <a href="https://github.com/infocyph/DBLayer/compare/main...HEAD?quick_pull=1&amp;template=PULL_REQUEST_TEMPLATE.md">General</a> •
  <a href="https://github.com/infocyph/DBLayer/compare/main...HEAD?quick_pull=1&amp;template=bug_fix.md">Bug fix</a> •
  <a href="https://github.com/infocyph/DBLayer/compare/main...HEAD?quick_pull=1&amp;template=feature.md">Feature</a> •
  <a href="https://github.com/infocyph/DBLayer/compare/main...HEAD?quick_pull=1&amp;template=refactor.md">Refactor</a> •
  <a href="https://github.com/infocyph/DBLayer/compare/main...HEAD?quick_pull=1&amp;template=performance.md">Performance</a> •
  <a href="https://github.com/infocyph/DBLayer/compare/main...HEAD?quick_pull=1&amp;template=security_reliability.md">Security &amp; reliability</a> •
  <a href="https://github.com/infocyph/DBLayer/compare/main...HEAD?quick_pull=1&amp;template=documentation.md">Documentation</a> •
  <a href="https://github.com/infocyph/DBLayer/compare/main...HEAD?quick_pull=1&amp;template=maintenance.md">Maintenance</a>
</div>
