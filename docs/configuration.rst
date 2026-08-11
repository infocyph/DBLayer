Configuration
=============

Introduction
------------

Configuration is normalized through ``ConnectionConfig``. You can pass plain
arrays, and DBLayer applies driver defaults, alias normalization, and basic
validation before creating the connection.

.. contents:: On This Page
   :depth: 2
   :local:

Connection Setup
----------------

.. code-block:: php

   DB::addConnection([
       'driver' => 'mysql',
       'host' => '127.0.0.1',
       'port' => 3306,
       'database' => 'app_db',
       'username' => 'app_user',
       'password' => 'secret',
       'charset' => 'utf8mb4',
       'collation' => 'utf8mb4_unicode_ci',
   ], 'mysql_main');

Default Behavior
----------------

- Driver aliases are normalized (for example ``postgresql`` -> ``pgsql``).
- Driver-specific defaults are applied when values are missing.
- Security settings are merged with safe defaults.

Effective Configuration Surface
-------------------------------

DBLayer rejects built-in settings used with the wrong driver instead of
silently accepting values that PDO never applies.

================  ============================================================
Scope             Effective keys
================  ============================================================
All drivers       ``database``, ``prefix``, ``options``, ``timeout``,
                  ``persistent``, ``read``, ``write``, replica selection and
                  timing, statement caching, query comments, ``sticky``, and
                  SQL ``security``
MySQL/MariaDB     ``host``, ``port``, ``username``, ``password``, ``charset``,
                  ``collation``, ``unix_socket``, ``ssl_ca``, ``ssl_cert``,
                  ``ssl_key``, ``ssl_verify_server_cert``
PostgreSQL        ``host``, ``port``, ``username``, ``password``, ``charset``,
                  ``schema``, ``sslmode``
SQLite            ``database``; network, credential, charset, collation,
                  schema, and TLS settings are rejected
================  ============================================================

MySQL ``collation`` becomes the connection initialization command. Its TLS file
settings become ``Pdo\Mysql::ATTR_SSL_*`` constructor attributes. MySQL does
not support the PostgreSQL ``sslmode`` key.

PostgreSQL maps ``charset``, ``schema``, ``timeout``, and ``sslmode`` to libpq
``client_encoding``, startup ``search_path``, ``connect_timeout``, and
``sslmode`` DSN parameters. Supported SSL modes are ``disable``, ``allow``,
``prefer``, ``require``, ``verify-ca``, and ``verify-full``.

``timeout`` uses PDO's timeout attribute for MySQL/SQLite and libpq
``connect_timeout`` for PostgreSQL. Native clients can impose additional
driver- and version-specific behavior.

Table Prefix Semantics
----------------------

``prefix`` maps logical application table names to physical names once during
query or schema compilation:

.. code-block:: php

   DB::addConnection([
       'driver' => 'sqlite',
       'database' => '/srv/app/database.sqlite',
       'prefix' => 'tenant_',
   ], 'tenant');

   // Reads tenant_users and joins tenant_roles. Qualified logical columns are
   // rewritten consistently.
   $rows = DB::table('users', 'tenant')
       ->join('roles', 'users.role_id', '=', 'roles.id')
       ->select('users.id', 'roles.name')
       ->get();

The mapping covers select, insert, update, delete, truncate, joins, subqueries,
relation loading, schema operations, and migration ledgers. CTE names and
derived-table aliases remain logical and are not prefixed. Already-prefixed and
schema-qualified table names are not rewritten. Raw SQL and raw expressions
remain caller-owned and are never parsed to inject a prefix.

Use the same named connection for schema, migrations, relations, and queries.
Do not manually add the configured prefix to normal logical table names.

Read/Write Config Shape
-----------------------

``read`` and ``write`` can be provided as:

- Single associative array
- List of associative arrays
- Host-array variant (expanded internally)

That allows compact config in small projects and explicit lists in production.

Replica + Cache + Comment Defaults
----------------------------------

- ``read_latency_ttl``: ``15`` seconds.
- ``read_probe_sample_size``: ``0`` (probe all eligible replicas).
- ``read_session_read_only``: ``false``.
- ``statement_cache_enabled``: ``false`` (default is intentionally conservative).
- ``statement_cache_size``: ``64``.
- ``query_comment_enabled``: ``false``.
- ``query_comment_max_length``: ``160``.
- ``query_comment_context``: ``[]``.

Statement cache is disabled by default. Enable it only after validating behavior
for your driver/workload and benchmarking repeated prepared SQL shapes.

Security Block
--------------

.. code-block:: php

   'security' => [
       'enabled' => true,
       'max_sql_length' => 16384,
       'max_params' => 512,
       'max_param_bytes' => 1024,
       'queries_per_second' => 0,
       'queries_per_minute' => 0,
       'rate_limit_key' => null,
       'rate_limit_callback' => null,
       'strict_identifiers' => true,
       'require_tls' => null,
       'allow_insecure' => false,
       'raw_sql_policy' => 'allow', // allow | deny | allowlist
       'raw_sql_allowlist' => [],
       'cursor_signing_key' => null, // null or a stable secret of at least 32 bytes
   ]

Config-Driven Hardening
-----------------------

- ``security.require_tls=true`` forces TLS for MySQL/PostgreSQL and is not a
  valid SQLite connection setting.
- ``require_tls`` means encrypted transport is required; it does not by itself
  claim verified server identity. Configure a trusted CA and hostname
  verification (for PostgreSQL, normally ``sslmode=verify-full``) when identity
  verification is required.
- ``security.require_tls=false`` requires ``security.allow_insecure=true``.
- ``security.enabled=false`` requires ``security.allow_insecure=true``.
- ``security.cursor_signing_key`` signs opaque pagination cursors with HMAC-SHA256.
  Use the same secret on every application node and during rolling deploys.
  ``null`` leaves cursors unsigned; any configured string must contain at least
  32 bytes. Safe configuration exports always redact this value.

Facade helpers:

- ``DB::setSecurityDefaults([...])`` enforces defaults over connection-level values.
- ``DB::hardenProduction()`` applies hardened defaults
  (``enabled=true``, ``strict_identifiers=true``, ``require_tls=true``).

Production Guidance
-------------------

- Keep ``security.enabled`` true unless you have a controlled benchmark-only use case.
- Set explicit query limits for multi-tenant workloads.
- In high-trust production environments, set ``raw_sql_policy`` to ``allowlist`` and explicitly list permitted fragments.
- For MySQL, set ``ssl_ca`` / ``ssl_cert`` / ``ssl_key`` as needed.
- For PostgreSQL, set ``sslmode`` explicitly for every remote link.
- Use named connections for operational clarity (``primary``, ``reporting``, etc.).

Recommended Production Baseline
-------------------------------

Use this as an opinionated starting point and tune limits for your workload:

.. code-block:: php

   DB::addConnection([
       'driver' => 'mysql',
       'host' => env('DB_HOST', '127.0.0.1'),
       'port' => (int) env('DB_PORT', 3306),
       'database' => env('DB_DATABASE', 'app'),
       'username' => env('DB_USERNAME', 'app'),
       'password' => env('DB_PASSWORD', ''),
       'charset' => 'utf8mb4',
       'collation' => 'utf8mb4_unicode_ci',

       'security' => [
           'enabled' => true,
           'strict_identifiers' => true,
           'require_tls' => true,
           'raw_sql_policy' => 'allowlist',
           'raw_sql_allowlist' => [
               '/^id\\s*=\\s*\\?$/i',
               'count(*)',
           ],
           'max_sql_length' => 16384,
           'max_params' => 512,
           'max_param_bytes' => 2048,
       ],

       // Keep conservative defaults unless profiling proves benefit.
       'statement_cache_enabled' => false,
       'statement_cache_size' => 64,

       // Useful for traceability when comment context is sanitized and bounded.
       'query_comment_enabled' => true,
       'query_comment_max_length' => 160,
       'query_comment_context' => [
           'app' => 'api',
           'env' => 'prod',
       ],
   ], 'primary');

Why this baseline:

- keeps SQL validation and strict identifiers on
- enforces TLS by default
- blocks unrestricted raw SQL fragments
- keeps statement cache opt-in until benchmarked
- enables low-risk SQL comment trace context

.. note::

   Keep config values environment-driven in deployed environments. Treat
   connection arrays in source code as examples, not secret storage.
