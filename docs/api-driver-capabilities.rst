API: Driver Capabilities
========================

Class: ``Infocyph\DBLayer\Driver\Support\Capabilities``

Capabilities make SQL feature branching explicit. Instead of guessing by driver
name in application code, query capability flags and choose the correct path.

Read capabilities via:

.. code-block:: php

   $caps = DB::capabilities();

Fields
------

- ``supportsReturning``
- ``supportsInsertIgnore``
- ``supportsUpsert``
- ``supportsSavepoints``
- ``supportsSchemas``
- ``supportsJson``
- ``supportsWindowFunctions``

Built-in Driver Matrix
----------------------

.. list-table::
   :header-rows: 1

   * - Driver
     - Returning
     - Insert Ignore
     - Upsert
     - Savepoints
     - Schemas
     - JSON
     - Window Functions
   * - MySQL
     - no
     - yes
     - yes
     - yes
     - yes
     - yes
     - yes
   * - MariaDB 10.5+
     - yes
     - yes
     - yes
     - yes
     - yes
     - yes
     - yes
   * - PostgreSQL
     - yes
     - no
     - yes
     - yes
     - yes
     - yes
     - yes
   * - Microsoft SQL Server
     - yes (``OUTPUT``)
     - no
     - yes (``MERGE``)
     - yes
     - yes
     - yes
     - yes
   * - SQLite
     - no
     - yes
     - yes
     - yes
     - no
     - yes
     - yes

The public ``Returning`` capability is semantic. PostgreSQL/MariaDB emit
``RETURNING`` while SQL Server emits its native ``OUTPUT INSERTED`` form. The
five canonical built-in pathways are ``mysql``, ``mariadb``, ``pgsql``,
``mssql``, and ``sqlite``.

Custom Driver Contract
----------------------

Custom ``DriverInterface`` implementations compile their native plan syntax
through ``compileExplain()``. Unsupported option combinations must be rejected
explicitly rather than silently ignored. The method receives the SELECT SQL, the
``analyze``, ``buffers``, and ``verbose`` booleans, and an optional server
version. SQL Server is the one built-in exception: its session-scoped
SHOWPLAN/STATISTICS modes require connection-level coordination rather than one
portable compiled statement.

The base ``DriverInterface`` remains compatible with DBLayer 4.0. Managed
transactions use the native PDO lifecycle, so custom drivers do not need a
separate transaction-start capability.
