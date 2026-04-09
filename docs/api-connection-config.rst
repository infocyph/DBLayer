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

Core
----

- ``getDriver()``
- ``getDatabase()``
