API: ConnectionConfig
=====================

Class: ``Infocyph\DBLayer\Connection\ConnectionConfig``

``ConnectionConfig`` is immutable and normalized. It merges defaults, resolves
driver aliases, and exposes structured accessors for replica and security
settings used by ``Connection`` and pool modules.

Factory and Access
------------------

- ``__construct(array $config)``
- ``fromArray(array $config): self``
- ``toArray(): array``
- ``get(string $key, mixed $default = null): mixed``
- ``with(string $key, mixed $value): self``

Read/Write Split
----------------

- ``hasReadConfig()``, ``getReadConfig()``, ``getReadConfigs()``
- ``getReadStrategy()`` -> ``random``, ``round_robin``, ``least_latency``, ``weighted``
- ``getReadHealthCooldown()``
- ``hasWriteConfig()``, ``getWriteConfig()``, ``getWriteConfigs()``
- ``isSticky()``

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
- ``raw_sql_policy``, ``raw_sql_allowlist``

Core
----

- ``getDriver()``
- ``getDatabase()``
