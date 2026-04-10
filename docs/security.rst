Security
========

Introduction
------------

DBLayer includes layered SQL safety checks for query text and bindings. Security
is configured globally by mode and can be hardened or tuned per connection.

.. contents:: On This Page
   :depth: 2
   :local:

Security Mode
-------------

Available modes:

- ``SecurityMode::OFF``
- ``SecurityMode::NORMAL``
- ``SecurityMode::STRICT``

.. code-block:: php

   use Infocyph\DBLayer\Security\Security;
   use Infocyph\DBLayer\Security\SecurityMode;

   Security::setMode(SecurityMode::STRICT);

Mode Semantics
--------------

- ``OFF``: disables automatic SQL validation.
- ``NORMAL``: injection/binding validation with practical defaults.
- ``STRICT``: adds more aggressive pattern checks and tighter policies.

Environment Guardrails
----------------------

- ``SecurityMode::OFF`` is blocked outside local/testing environments.
- ``security.enabled = false`` is also blocked outside local/testing.
- Override for controlled cases: ``DBLAYER_ALLOW_INSECURE_SECURITY_MODE=1``.

DBLayer treats ``APP_ENV=production`` and ``APP_ENV=prod`` as production-like.

Validation Coverage
-------------------

- SQL injection pattern checks
- Query length checks
- Parameter count and size checks
- Additional dangerous-pattern scans in strict mode

The validator is defense-in-depth. You should still use parameterized queries
and avoid concatenating untrusted input into SQL fragments.

Per-Connection Security Config
------------------------------

.. code-block:: php

   'security' => [
       'enabled' => true,
       'max_sql_length' => 8000,
       'max_params' => 500,
       'max_param_bytes' => 4096,
       'queries_per_second' => 0,
       'queries_per_minute' => 0,
       'rate_limit_key' => null,
       'rate_limit_callback' => null,
       'strict_identifiers' => true,
       'require_tls' => null,
       'raw_sql_policy' => 'allow',
       'raw_sql_allowlist' => [],
   ]

Transport / TLS Policy
----------------------

- In production-like environments, MySQL and PostgreSQL connections require TLS by default.
- ``security.require_tls = true`` enforces TLS in any environment.
- ``security.require_tls = false`` is blocked in production unless
  ``DBLAYER_ALLOW_INSECURE_TRANSPORT=1`` is set.

Driver requirements:

- MySQL: provide secure transport via ``ssl_ca`` / ``ssl_cert`` / ``ssl_key`` or a secure ``sslmode``.
- PostgreSQL: set ``sslmode`` to ``require``, ``verify-ca``, or ``verify-full``.

Raw SQL Fragment Policy
-----------------------

Raw entry points (for example ``whereRaw()``, ``selectRaw()``, string
``fromSub()``, and string CTE bodies) are controlled by:

- ``raw_sql_policy = allow``: default behavior.
- ``raw_sql_policy = deny``: block all raw fragments.
- ``raw_sql_policy = allowlist``: only allow fragments matching
  ``raw_sql_allowlist`` patterns.

Allowlist rules support plain substring rules and regex rules such as
``'/^id\\s*=\\s*\\?$/i'``.

Facade-Level Defaults
---------------------

For consistent policy across many connections, you can apply global defaults
through the facade:

.. code-block:: php

   use Infocyph\DBLayer\DB;

   DB::setSecurityDefaults([
       'strict_identifiers' => true,
       'queries_per_second' => 250,
   ]);

   // Convenience profile (enables strict identifiers; requires TLS in production).
   DB::hardenProduction();

These values are applied as enforced facade policy across registered and future
connections.

Rate Limiting and Confirmation
------------------------------

Security utilities also include rate-limit checks and dangerous-operation
confirmation gates:

.. code-block:: php

   Security::checkRateLimit('tenant:42');
   Security::requireConfirmation('drop table users', confirmed: true);

Error and Log Hygiene
---------------------

- Query failure exceptions expose statement type and SQL fingerprint, not full SQL text.
- Logger redacts binding values by default.

.. code-block:: php

   DB::enableLogger('/tmp/dblayer.log');
   DB::logger()->setRedactBindings(true); // default
   // DB::logger()->setRedactBindings(false); // opt out only for controlled local debugging

.. warning::

   Security mode ``OFF`` disables automatic SQL validation. Use it only in
   controlled internal scenarios, never as a default in shared environments.
