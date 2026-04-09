API: ConnectionConfig
=====================

Class: ``Infocyph\DBLayer\Connection\ConnectionConfig``

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
