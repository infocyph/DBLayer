Schema and Migrations
=====================

DBLayer owns database schema and migration execution. It does not discover
files, render console output, or inspect framework packages at runtime.
Applications and frameworks supply an explicit, already-compiled list of
``Migration`` objects.

Schema Builder
--------------

Resolve the schema manager lazily from the selected connection:

.. code-block:: php

   use Infocyph\DBLayer\DB;
   use Infocyph\DBLayer\Schema\Blueprint;

   DB::schema('primary')->create('accounts', static function (Blueprint $table): void {
       $table->id();
       $table->uuid('public_id')->unique();
       $table->string('email')->unique();
       $table->boolean('active')->default(true);
       $table->json('profile')->nullable();
       $table->timestamp('created_at')->useCurrent();
   });

   DB::schema('primary')->table('accounts', static function (Blueprint $table): void {
       $table->string('display_name')->nullable();
       $table->index(['active', 'created_at']);
       $table->renameColumn('profile', 'metadata');
   });

The manager honors the connection's table prefix. Schema identifiers are
validated before compilation. Supported scalar defaults are quoted by the DDL
grammar. A database expression must be wrapped in
``Infocyph\DBLayer\Query\Expression`` so raw SQL is an explicit developer-owned
trust boundary:

.. code-block:: php

   use Infocyph\DBLayer\Query\Expression;

   $table->json('options')->default(Expression::make('JSON_ARRAY()'));

Schema Manager Operations
-------------------------

``DB::schema()`` creates a manager only when schema work is requested. The
schema and migration classes therefore stay outside the normal query path.
Every operation uses the selected connection and its configured table prefix.

.. list-table::
   :header-rows: 1

   * - Method
     - Contract
   * - ``connection()``
     - Return the resolved connection owned by this manager.
   * - ``create(table, definition)``
     - Create a table from one explicit ``Blueprint`` callback.
   * - ``table(table, definition)``
     - Alter an existing table.
   * - ``hasTable(table)`` / ``hasColumn(table, column)``
     - Inspect the selected database/schema.
   * - ``rename(from, to)``
     - Rename one table.
   * - ``drop(table)`` / ``dropIfExists(table)``
     - Drop one table.
   * - ``tables()``
     - Return the physical user-table names visible to the selected connection.
   * - ``toSql(table, creating, definition)``
     - Compile a non-empty statement list without executing it.
   * - ``supportsTransactionalDdl()``
     - Report DBLayer's transaction policy for the selected driver.
   * - ``dropAllTables(authorized)``
     - Drop all user tables only when passed explicit ``true``.

``dropAllTables()`` is a low-level destructive primitive. Console/Foundation
must perform environment and operator confirmation before authorizing it.

Identifier Storage and Generation
---------------------------------

``uuid()``, ``ulid()``, ``foreignUuid()``, and ``foreignUlid()`` declare
storage only; DBLayer does not generate identifiers and does not require an ID
library. For portable application-side generation, install a suitable package.
The Infocyph stack can use ``infocyph/uid``:

.. code-block:: bash

   composer require infocyph/uid

.. code-block:: php

   use Infocyph\UID\Id;

   $uuid = Id::uuid(); // UUIDv7 by default
   $ulid = Id::ulid();

   DB::table('accounts')->insert([
       'public_id' => $uuid,
       'external_id' => $ulid,
   ]);

Application generation is the portable default: it provides the ID before the
insert and keeps one UUID/ULID policy across MySQL, PostgreSQL, and SQLite.
Always retain a database unique or primary-key constraint.

Database defaults remain an explicit driver/version decision through
``Expression``:

.. code-block:: php

   // PostgreSQL 18+: native time-ordered UUIDv7.
   $table->uuid('id')
       ->default(Expression::make('uuidv7()'))
       ->primary();

   // PostgreSQL: native random UUIDv4.
   $table->uuid('id')
       ->default(Expression::make('gen_random_uuid()'))
       ->primary();

   // MySQL 8.0.13+: UUID() returns a string UUID; parentheses are required
   // for a non-literal default expression.
   $table->uuid('id')
       ->default(Expression::make('(UUID())'))
       ->primary();

MySQL's built-in ``UUID()`` is UUIDv1 and has replication/security trade-offs.
SQLite exposes random bytes but no portable native UUID or ULID generator.
ULID generation should therefore remain application-side unless the deployment
installs and owns a database extension or function. Raw default expressions are
trusted migration code and must never contain request data.

References:

- PostgreSQL UUID functions:
  https://www.postgresql.org/docs/current/functions-uuid.html
- MySQL UUID and conversion functions:
  https://dev.mysql.com/doc/refman/8.4/en/miscellaneous-functions.html
- MySQL expression defaults:
  https://dev.mysql.com/doc/refman/8.0/en/data-type-defaults.html
- SQLite core functions:
  https://www.sqlite.org/lang_corefunc.html

Column Type Matrix
------------------

.. list-table::
   :header-rows: 1

   * - Blueprint type
     - MySQL/MariaDB
     - PostgreSQL
     - SQLite
   * - ``tinyIncrements`` / ``smallIncrements`` / ``mediumIncrements`` /
       ``increments`` / ``bigIncrements`` / ``id``
     - native unsigned integer auto increment
     - smallserial/serial/bigserial
     - integer primary key autoincrement
   * - ``char(length)`` / ``string(length)``
     - char/varchar
     - char/varchar
     - text
   * - ``tinyText`` / ``text`` / ``mediumText`` / ``longText``
     - native text sizes
     - text
     - text
   * - ``tinyInteger`` / ``smallInteger`` / ``mediumInteger`` / ``integer`` /
       ``bigInteger``
     - native integer types
     - smallint/integer/bigint equivalents
     - integer affinity
   * - ``unsignedTinyInteger`` / ``unsignedSmallInteger`` /
       ``unsignedMediumInteger`` / ``unsignedInteger`` / ``unsignedBigInteger``
     - native unsigned integer types
     - signed equivalents
     - integer affinity
   * - ``boolean``
     - tinyint(1)
     - boolean
     - boolean affinity
   * - ``decimal(precision, scale)``
     - decimal
     - decimal
     - decimal affinity
   * - ``float`` / ``double``
     - float/double
     - float/double precision
     - real
   * - ``date``
     - date
     - date
     - date affinity
   * - ``dateTime`` / ``timestamp`` / ``time``
     - native types with optional fractional precision
     - without-time-zone native types
     - text
   * - ``dateTimeTz`` / ``timestampTz`` / ``timeTz``
     - native types without retained timezone semantics
     - with-time-zone native types
     - text
   * - ``timestamps`` / ``timestampsTz`` / ``softDeletes`` / ``softDeletesTz``
     - convenience columns using the types above
     - convenience columns using the types above
     - convenience columns using the types above
   * - ``year``
     - year
     - smallint
     - integer
   * - ``json`` / ``jsonb``
     - json
     - json/jsonb
     - text
   * - ``binary``
     - blob
     - bytea
     - blob
   * - ``uuid`` / ``ulid``
     - char(36)/char(26)
     - uuid/char(26)
     - text
   * - ``ipAddress`` / ``macAddress``
     - varchar(45)/varchar(17)
     - inet/macaddr
     - text
   * - ``enum``
     - native enum
     - varchar with check constraint
     - text with check constraint
   * - ``set``
     - native set
     - explicit unsupported error
     - explicit unsupported error
   * - ``geometry``
     - native spatial subtype/SRID
     - PostGIS geometry subtype/SRID
     - explicit unsupported error
   * - ``geography``
     - explicit unsupported error
     - PostGIS geography subtype/SRID
     - explicit unsupported error
   * - ``vector(dimensions)``
     - explicit unsupported error
     - pgvector type
     - explicit unsupported error

Modifiers are ``nullable()``, ``default()``, ``unsigned()``,
``autoIncrement()``, ``primary()``, ``unique()``, ``index()``, and
``useCurrent()``. ``useCurrentOnUpdate()`` is MySQL-only. ``storedAs()`` creates
a stored generated column on all three drivers; ``virtualAs()`` is supported by
MySQL and SQLite. Generated expressions accept ``Expression`` or a
developer-owned SQL string and must never contain request data.

``unsigned`` changes SQL only on MySQL/MariaDB because the other engines do not
implement that modifier. The explicit unsigned helper methods intentionally
compile to each other driver's normal integer affinity/type.

``foreignId()`` declares an unsigned big integer, while ``foreignUuid()`` and
``foreignUlid()`` use the corresponding UUID/ULID storage type. These helpers
create columns only; call ``foreign()`` to define the actual referential
constraint.

``geometry``, ``geography``, and ``vector`` compile the native type but do not
install database extensions. PostgreSQL deployments must provision PostGIS or
pgvector before migrations using those types run.

Definition Validation
---------------------

Schema definitions are validated before execution:

- names must be dot-separated SQL identifiers composed of letters, digits, and
  underscores, with each segment starting with a letter or underscore
- ``char``/``string`` lengths and vector dimensions must be positive
- decimal precision must be positive and scale must be between zero and
  precision
- temporal precision must be from ``0`` through ``6``
- enum/set choices must be non-empty, unique, non-empty strings
- spatial subtype names are identifier-like and SRIDs cannot be negative
- auto increment is restricted to integer column types
- ``useCurrent()``/``useCurrentOnUpdate()`` are restricted to date-time and
  timestamp columns

``default()`` accepts ``null``, booleans, integers, finite floats, strings, or
an explicit ``Expression``. ``primary()`` makes a column non-nullable.
``useCurrent()`` replaces an explicit default. Generated-column modifiers
replace defaults and current-time modifiers because the database owns their
value.

Indexes and Foreign Keys
------------------------

Column modifiers create single-column indexes. Blueprint-level methods support
single or composite constraints:

.. code-block:: php

   $table->primary(['tenant_id', 'id'], 'accounts_primary');
   $table->index(['state', 'created_at'], 'accounts_state_created_index');
   $table->unique(['tenant_id', 'email'], 'accounts_tenant_email_unique');

   $table->foreign('tenant_id', 'accounts_tenant_foreign')
       ->references('id')
       ->on('tenants')
       ->onUpdate('CASCADE')
       ->onDelete('RESTRICT');

   $table->foreign(['tenant_id', 'owner_id'])
       ->references(['tenant_id', 'id'])
       ->on('users');

Supported foreign-key actions are ``CASCADE``, ``NO ACTION``, ``RESTRICT``,
``SET DEFAULT``, and ``SET NULL``. DBLayer validates the action but the selected
database remains responsible for accepting it for the concrete schema.

Alter-table commands are ``dropColumn()``, ``renameColumn()``, ``dropIndex()``,
``dropUnique()``, ``renameIndex()``, ``dropPrimary()``, and ``dropForeign()``.
Convenience methods are ``rememberToken()``, ``dropRememberToken()``,
``dropTimestamps()``, and ``dropSoftDeletes()``.

Unnamed index and foreign-key names are deterministic. Name a constraint
explicitly when a later migration must drop or rename it, especially across
database engines.

DDL Support Matrix
------------------

.. list-table::
   :header-rows: 1

   * - Operation
     - MySQL/MariaDB
     - PostgreSQL
     - SQLite
   * - Create, rename, drop table
     - yes
     - yes
     - yes
   * - Add, rename, drop column
     - yes
     - yes
     - yes on SQLite versions supporting the native statement
   * - Change column type/modifiers
     - modify column
     - alter type/null/default
     - explicit unsupported error
   * - Create/drop normal and unique index
     - yes
     - yes
     - yes
   * - Rename index
     - yes
     - yes
     - explicit unsupported error
   * - Drop primary key
     - yes
     - yes
     - explicit unsupported error
   * - Primary key in create
     - yes
     - yes
     - yes
   * - Add primary key to existing table
     - yes
     - yes
     - explicit unsupported error
   * - Foreign key in create
     - yes
     - yes
     - yes
   * - Add/drop foreign key on existing table
     - yes
     - yes
     - explicit unsupported error
   * - Transactional DDL
     - no guarantee
     - yes
     - yes

DBLayer emits native statements and fails explicitly when its grammar cannot
represent an operation safely. It does not rebuild SQLite tables behind the
application’s back.

Migration Contract
------------------

.. code-block:: php

   use Infocyph\DBLayer\Migration\Migration;
   use Infocyph\DBLayer\Migration\MigrationContext;
   use Infocyph\DBLayer\Schema\Blueprint;
   use Infocyph\DBLayer\Schema\SchemaManager;

   final class CreateAccounts implements Migration
   {
       public function id(): string
       {
           return '20260729120000_create_accounts';
       }

       public function up(SchemaManager $schema, MigrationContext $context): void
       {
           $schema->create('accounts', static function (Blueprint $table): void {
               $table->id();
               $table->string('email')->unique();
           });
       }

       public function down(SchemaManager $schema, MigrationContext $context): void
       {
           $schema->dropIfExists('accounts');
       }
   }

Identifiers are sorted lexically and must be globally unique across application
and package sources. Foundation should compile package manifests and application
paths during its cache/optimize phase, instantiate those classes in console
startup, and pass the resulting iterable to ``MigrationRunner``. DBLayer never
scans those paths.

A migration that should be controlled by a feature decision may implement
``ConditionalMigration``. Its ``shouldRun()`` method is evaluated by both
``run()`` and ``pretend()``; returning ``false`` leaves the migration pending.
The decision must be deterministic for the deployment configuration; changing
it after a migration has run does not remove the applied ledger entry.

.. code-block:: php

   use Infocyph\DBLayer\Migration\ConditionalMigration;
   use Infocyph\DBLayer\Migration\MigrationContext;
   use Infocyph\DBLayer\Schema\Blueprint;
   use Infocyph\DBLayer\Schema\SchemaManager;

   final class AddSearchIndex implements ConditionalMigration
   {
       public function __construct(private readonly bool $searchEnabled)
       {
       }

       public function id(): string
       {
           return '20260730090000_add_accounts_search_index';
       }

       public function shouldRun(): bool
       {
           return $this->searchEnabled;
       }

       public function up(SchemaManager $schema, MigrationContext $context): void
       {
           $schema->table('accounts', static function (Blueprint $table): void {
               $table->index('display_name', 'accounts_display_name_index');
           });
       }

       public function down(SchemaManager $schema, MigrationContext $context): void
       {
           $schema->table('accounts', static function (Blueprint $table): void {
               $table->dropIndex('accounts_display_name_index');
           });
       }
   }

Runner Lifecycle
----------------

.. code-block:: php

   use Infocyph\CacheLayer\Cache\Lock\RedisLockProvider;
   use Infocyph\DBLayer\DB;
   use Infocyph\DBLayer\Migration\MigrationRunner;

   $runner = new MigrationRunner(
       connection: DB::connection('primary'),
       migrations: $compiledMigrations,
       locks: $redisLockProvider,
       table: 'migrations',
       lockWaitSeconds: 10.0,
       leaseSeconds: 300.0,
   );

   $applied = $runner->run();
   $stepped = $runner->run(step: true);
   $status = $runner->status();
   $sql = $runner->pretend();
   $rolledBack = $runner->rollback(batches: 1);
   $exactBatch = $runner->rollbackBatch(batch: 3);
   $refreshed = $runner->refresh(authorized: true);

``run`` creates the ledger and applies pending migrations as one new batch.
With ``step: true``, every pending migration receives its own batch.
``rollback`` reverts the newest batch count. ``reset`` reverts all registered
migrations. ``rollbackBatch`` targets one exact positive batch. ``refresh``
rolls back and reruns the manifest. ``fresh`` drops every user table and reruns
the manifest. ``reset``, ``refresh``, and ``fresh`` require an explicit ``true``
value or an authorization callback. A framework must add its own production
environment confirmation before passing that approval.

The manifest is normalized once in lexical ID order. Empty or duplicate IDs are
rejected. ``status()`` returns ``id``, ``applied``, and nullable ``batch`` for
every registered migration. ``pretend()`` returns statements and bindings
grouped by pending migration ID; it executes no SQL. During a preview,
``$context->pretending`` is ``true`` so data migrations can avoid non-database
side effects while still declaring their SQL.

Rollback requires every selected applied migration to remain present in the
explicit manifest. This prevents DBLayer from guessing how to reverse unknown
ledger entries.

Locking and Long Operations
---------------------------

Pass any CacheLayer ``LockProviderInterface`` implementation: file, PDO,
Redis/Valkey, or Memcached. The runner uses CacheLayer's acquire/refresh/release
lease directly and releases it in ``finally``. No second locking abstraction is
introduced.

For long data migrations, work in bounded chunks and call
``$context->checkpoint()`` between chunks. A failed refresh stops execution
before the next chunk. Set the lease longer than the maximum time between
checkpoints. Do not use a process-local file lock when deployment nodes do not
share the same filesystem.

Atomicity and Failure Recovery
------------------------------

PostgreSQL and SQLite execute each migration and its ledger write in one
transaction. MySQL/MariaDB DDL may implicitly commit, so DBLayer executes the
migration without claiming atomic rollback. A failure identifies the migration
and preserves the original exception. Inspect the database, repair or
idempotently complete partial DDL, then rerun with the same identifier.

Never edit an already-deployed migration. Add a new migration for the repair.
Before destructive deployment:

1. Back up and verify restore.
2. Run ``pretend`` against the target driver/configuration.
3. Acquire a distributed lease shared by every deployment node.
4. Run forward migrations.
5. Verify ``status`` and application readiness.
6. Release the previous application version only after rollback compatibility
   is no longer required.

Seeding
-------

``SeedRunner`` executes explicit ``Seeder`` instances or callables in order. It
is transactional by default. A ``Seeder`` receives the resolved connection and
one ``SeedContext``. ``SeedContext::call()`` executes child seeders immediately,
preserves declared order, and keeps the complete tree inside the root
transaction:

.. code-block:: php

   use Infocyph\DBLayer\Connection\Connection;
   use Infocyph\DBLayer\Migration\Seeder;
   use Infocyph\DBLayer\Migration\SeedContext;

   final class DatabaseSeeder implements Seeder
   {
       public function run(Connection $connection, SeedContext $context): void
       {
           $context->call([
               new UserSeeder(),
               new PermissionSeeder(),
           ]);
       }
   }

Callables receive the same two arguments:

.. code-block:: php

   use Infocyph\DBLayer\Connection\Connection;
   use Infocyph\DBLayer\Migration\SeedContext;

   $count = (new SeedRunner(DB::connection('primary')))->run([
       static function (Connection $connection, SeedContext $context): void {
           $connection->table('roles')->insert(['name' => 'admin']);
           $context->call([new PermissionSeeder()]);
       },
   ]);

The returned count includes parent and child seeders. Pass
``transactional: false`` only when partial writes after failure are intentional.
Nested ``call()`` operations never open a second transaction and never defer
execution. ``SeedContext::connection()`` returns the same resolved connection
when a nested coordinator needs it. Invalid manifest entries fail before their
level begins.
DBLayer has no ORM, so model factories, mass-assignment bypasses, and model-event
muting remain application or ORM concerns. Production confirmation belongs to
the Console/Foundation command boundary rather than this execution library.
