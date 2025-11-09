# DBLayer - High-Performance PHP Database Layer

A robust, secure, and feature-rich database abstraction layer for PHP 8.2+ with ORM capabilities, async support, and multi-driver compatibility.

## Features

### Core Features
- ✅ **Query Builder** - Fluent, Laravel-like API
- ✅ **Connection Manager** - Connection pooling + read/write split
- ✅ **Multi-Driver** - MySQL, PostgreSQL, SQLite
- ✅ **Security** - Multi-layer SQL injection protection
- ✅ **Transactions** - Nested transactions with savepoints
- ✅ **Schema Builder** - Full DDL support
- ✅ **Migrations** - Database versioning
- ✅ **ORM** - Active Record pattern with relationships
- ✅ **Async Support** - Swoole, ReactPHP, Amp, Revolt
- ✅ **Caching** - Query result caching
- ✅ **Profiling** - Performance monitoring
- ✅ **Events** - Lifecycle hooks

### Performance
- Near-PDO performance (<5% overhead for simple queries)
- Connection pooling for reuse
- Prepared statement caching
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

// Read/Write split
DB::addConnection([
    'driver' => 'mysql',
    'write' => [
        'host' => 'master.example.com',
    ],
    'read' => [
        ['host' => 'replica1.example.com'],
        ['host' => 'replica2.example.com'],
    ],
    'database' => 'myapp',
    'username' => 'root',
    'password' => 'secret',
    'sticky' => true,
]);
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

### Transactions

```php
// Automatic transaction
DB::transaction(function() {
    DB::table('accounts')->where('id', 1)->decrement('balance', 100);
    DB::table('accounts')->where('id', 2)->increment('balance', 100);
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

### Schema & Migrations

```php
use Infocyph\DBLayer\Schema\Schema;

// Create table
Schema::create('users', function($table) {
    $table->id();
    $table->string('name', 100);
    $table->string('email')->unique();
    $table->boolean('active')->default(true);
    $table->timestamps();
    
    $table->index(['email', 'active']);
});

// Modify table
Schema::table('users', function($table) {
    $table->string('phone', 20)->nullable();
});
```

### ORM (Active Record)

```php
use Infocyph\DBLayer\ORM\Model;

class User extends Model
{
    protected $fillable = ['name', 'email'];
    protected $hidden = ['password'];
    
    public function posts()
    {
        return $this->hasMany(Post::class);
    }
    
    public function profile()
    {
        return $this->hasOne(Profile::class);
    }
}

// Usage
$user = User::create([
    'name' => 'John Doe',
    'email' => 'john@example.com',
]);

$user = User::find(1);
$user->name = 'Jane Doe';
$user->save();

// Eager loading (prevents N+1)
$users = User::with('posts', 'profile')->get();

// Query scopes
$activeUsers = User::where('active', true)->get();
```

### Async Queries

```php
use Swoole\Coroutine;
use Infocyph\DBLayer\Async\AsyncConnection;

Coroutine\run(function() {
    $async = new AsyncConnection(DB::connection(), 'swoole');
    
    // Single async query
    $async->selectAsync('SELECT * FROM users WHERE id = ?', [1])
        ->then(function($users) {
            print_r($users);
        });
    
    // Parallel queries
    $async->parallel([
        ['sql' => 'SELECT * FROM users'],
        ['sql' => 'SELECT * FROM posts'],
        ['sql' => 'SELECT * FROM comments']
    ])->then(function($results) {
        [$users, $posts, $comments] = $results;
    });
});
```

## Testing

```bash
composer test
composer test-coverage
```

## Benchmarks

| Operation | DBLayer | Raw PDO | Laravel | Overhead |
|-----------|---------|---------|---------|----------|
| Simple SELECT | 0.52ms | 0.50ms | 0.68ms | +4% |
| Complex JOIN | 2.15ms | 2.10ms | 2.45ms | +2.4% |
| 1000 Inserts (batch) | 125ms | 110ms | 165ms | +13.6% |
| Transaction (10 ops) | 3.2ms | 3.0ms | 4.1ms | +6.7% |

## Security

DBLayer implements multiple layers of security:

1. **Parameterization** - All values automatically bound
2. **Identifier Validation** - Table/column names validated
3. **Operator Whitelist** - Only safe operators allowed
4. **Injection Detection** - Scans for suspicious patterns
5. **Rate Limiting** - Prevents query flooding
6. **Audit Logging** - Tracks all queries

## Requirements

- PHP 8.2+
- ext-pdo
- ext-pdo_mysql (for MySQL)
- ext-pdo_pgsql (for PostgreSQL)
- ext-pdo_sqlite (for SQLite)

## License

MIT License

## Author

Hasan - [Infocyph](https://infocyph.com)

## Contributing

Contributions are welcome! Please see CONTRIBUTING.md for details.
