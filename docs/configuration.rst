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
- ``security`` limits and transport policy

Read/Write Config Shape
-----------------------

``read`` and ``write`` can be provided as:

- Single associative array
- List of associative arrays
- Host-array variant (expanded internally)

That allows compact config in small projects and explicit lists in production.

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
       'raw_sql_policy' => 'allow', // allow | deny | allowlist
       'raw_sql_allowlist' => [],
   ]

Environment-Sensitive Hardening
-------------------------------

- ``APP_ENV=production`` (or ``prod``) activates production transport rules.
- For MySQL/PostgreSQL, TLS is required by default in production-like environments.
- ``security.require_tls=true`` forces TLS in every environment.
- ``security.require_tls=false`` is blocked in production unless
  ``DBLAYER_ALLOW_INSECURE_TRANSPORT=1`` is set.
- ``security.enabled=false`` is blocked outside local/testing unless
  ``DBLAYER_ALLOW_INSECURE_SECURITY_MODE=1`` is set.

Facade helpers:

- ``DB::setSecurityDefaults([...])`` enforces defaults over connection-level values.
- ``DB::hardenProduction()`` applies production-oriented defaults (strict identifiers,
  and TLS requirement when running in production-like environment).

Production Guidance
-------------------

- Keep ``security.enabled`` true unless you have a controlled benchmark-only use case.
- Set explicit query limits for multi-tenant workloads.
- In high-trust production environments, set ``raw_sql_policy`` to ``allowlist`` and explicitly list permitted fragments.
- Set explicit TLS parameters (``sslmode`` and/or driver TLS keys) for all remote MySQL/PostgreSQL links.
- Use named connections for operational clarity (``primary``, ``reporting``, etc.).

.. note::

   Keep config values environment-driven in deployed environments. Treat
   connection arrays in source code as examples, not secret storage.
