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

Important Keys
--------------

- ``driver``, ``host``, ``port``, ``database``, ``username``, ``password``
- ``read`` / ``write`` split
- ``read_strategy``, ``read_health_cooldown``, ``sticky``
- ``read_latency_ttl``, ``read_probe_sample_size``, ``read_session_read_only``
- ``statement_cache_enabled``, ``statement_cache_size``
- ``query_comment_enabled``, ``query_comment_max_length``, ``query_comment_context``
- ``security`` limits and transport policy

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
   ]

Config-Driven Hardening
-----------------------

- ``security.require_tls=true`` forces TLS for MySQL/PostgreSQL.
- ``security.require_tls=false`` requires ``security.allow_insecure=true``.
- ``security.enabled=false`` requires ``security.allow_insecure=true``.

Facade helpers:

- ``DB::setSecurityDefaults([...])`` enforces defaults over connection-level values.
- ``DB::hardenProduction()`` applies hardened defaults
  (``enabled=true``, ``strict_identifiers=true``, ``require_tls=true``).

Production Guidance
-------------------

- Keep ``security.enabled`` true unless you have a controlled benchmark-only use case.
- Set explicit query limits for multi-tenant workloads.
- In high-trust production environments, set ``raw_sql_policy`` to ``allowlist`` and explicitly list permitted fragments.
- Set explicit TLS parameters (``sslmode`` and/or driver TLS keys) for all remote MySQL/PostgreSQL links.
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
