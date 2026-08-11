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

Policy Guardrails
-----------------

- ``SecurityMode::OFF`` is blocked by default.
- ``security.enabled = false`` is blocked unless you set
  ``security.allow_insecure = true``.
- Intentional global override for advanced local diagnostics:

.. code-block:: php

   use Infocyph\DBLayer\Security\Security;

   Security::allowInsecureMode(true);

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
       'allow_insecure' => false,
       'raw_sql_policy' => 'allow',
       'raw_sql_allowlist' => [],
       'cursor_signing_key' => null,
   ]

Cursor Integrity
----------------

``cursorPaginate()`` always returns an opaque, versioned token bound to the
query filters and exact ordering. Set ``security.cursor_signing_key`` to add an
HMAC-SHA256 signature when cursors cross a trust boundary, such as a public
HTTP API.

The value is either ``null`` (unsigned) or a secret string of at least 32 bytes.
It must be identical across application nodes. Rotating it immediately
invalidates cursors issued with the previous key, so coordinate rotation with
clients when uninterrupted navigation is required.

.. code-block:: php

   'security' => [
       // Example only: load a random 32+ byte value from secret storage.
       'cursor_signing_key' => $_ENV['DB_CURSOR_SIGNING_KEY'],
   ]

Cursor positions reject null and non-scalar ordered values. Safe configuration
exports redact the signing key.

Transport / TLS Policy
----------------------

- ``security.require_tls = true`` enforces encrypted transport for
  MySQL/PostgreSQL. It does not alone guarantee verified server identity.
- ``security.require_tls = false`` requires ``security.allow_insecure = true``.
- ``DB::hardenProduction()`` sets ``require_tls = true`` for MySQL/PostgreSQL
  connections. SQLite receives the remaining hardening defaults without a TLS
  setting.

Driver requirements:

- MySQL: provide a trusted ``ssl_ca`` and enable server-certificate verification
  when identity verification is required; client ``ssl_cert`` / ``ssl_key``
  configure mutual TLS when applicable.
- PostgreSQL: use ``sslmode=verify-full`` with a trusted CA for hostname and
  certificate verification. ``require`` encrypts without that identity claim.
- SQLite: ``security.require_tls`` is rejected because SQLite has no network
  transport.

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

   // Convenience profile (enables strict identifiers and require_tls=true).
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

The built-in limiter is process-local and keeps a bounded set of active time
buckets. It removes expired buckets lazily and fails closed if active-key
cardinality exhausts its capacity. Use ``rate_limit_callback`` for a shared,
distributed limit across workers or hosts.

Error and Log Hygiene
---------------------

- Query failure exceptions expose statement type and SQL fingerprint, not full SQL text.
- Logger redacts binding values by default.
- Logger can mirror structured entries to any PSR-3 backend via ``DB::setPsrLogger()``.

.. code-block:: php

   DB::enableLogger('/tmp/dblayer.log');
   DB::logger()->setRedactBindings(true); // default
   // DB::logger()->setRedactBindings(false); // opt out only for controlled local debugging

.. warning::

   Security mode ``OFF`` disables automatic SQL validation. Use it only in
   controlled internal scenarios, never as a default in shared environments.
