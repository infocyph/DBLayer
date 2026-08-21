API: ConnectionConfig
=====================

Class: ``Infocyph\DBLayer\Connection\ConnectionConfig``

``ConnectionConfig`` is immutable and normalized. It merges defaults, resolves
driver aliases, and exposes structured accessors for replica and security
settings used by ``Connection`` and pool modules. The selected driver owns its
defaults and validates its effective settings once during construction.

Factory and Access
------------------

- ``__construct(array $config)``
- ``fromArray(array $config): self``
- ``toArray(): array``
- ``toSafeArray(): array`` (recursively redacts sensitive keys)
- ``get(string $key, mixed $default = null): mixed``
- ``with(string $key, mixed $value): self``

Read/Write Split
----------------

- ``hasReadConfig()``, ``getReadConfig()``, ``getReadConfigs()``
- ``getReadStrategy()`` -> ``random``, ``round_robin``, ``least_latency``, ``weighted``
- ``getReadHealthCooldown()``
- ``getLeastLatencyCacheTtl()``
- ``getReadProbeSampleSize()``
- ``shouldEnforceReadSessionReadOnly()``
- ``hasWriteConfig()``, ``getWriteConfig()``, ``getWriteConfigs()``
- ``isSticky()``

Statement Cache + Query Comment
-------------------------------

- ``shouldUseStatementCache()``
- ``statementCacheSize()``
- ``shouldUseQueryComments()``
- ``getQueryCommentMaxLength()``
- ``getQueryCommentContext()``

Security
--------

- ``isSecurityEnabled()``
- ``securityConfig()``

Security config keys currently supported:

- ``enabled``
- ``max_sql_length``
- ``max_params``
- ``max_param_bytes``
- ``queries_per_second``, ``queries_per_minute``
- ``rate_limit_key``, ``rate_limit_callback``
- ``strict_identifiers``
- ``require_tls``
- ``allow_insecure``
- ``raw_sql_policy``, ``raw_sql_allowlist``

Core
----

- ``getDriver()``
- ``getDatabase()``

Driver Settings
---------------

- MySQL/MariaDB: ``host``, ``port``, credentials, ``charset``, ``collation``,
  ``unix_socket``, and MySQL TLS keys.
- PostgreSQL: ``host``, ``port``, credentials, ``charset``, ``schema``, and
  ``sslmode``.
- Microsoft SQL Server: ``host``, ``port``, credentials, ``encrypt``,
  ``trust_server_certificate``, ``application_intent``, and ``login_timeout``.
- SQLite: ``database`` only; network, credential, charset, schema, and TLS keys
  are rejected.

Recognized built-in keys that do not belong to the selected driver cause
``ConnectionException`` instead of being retained as ineffective config.
