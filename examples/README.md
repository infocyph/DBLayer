# Connection deployment examples

`bootstrap.php` registers named configurations without opening database handles.
It keeps `mysql_main` as the default and returns optional worker pool settings.
Use the relevant profile; registering all examples does not provision proxies,
replication, listeners, database users, or TLS certificates.

| Connection name | Deployment | Endpoint defaults |
| --- | --- | --- |
| `mysql_main` | Direct MySQL | `127.0.0.1:3306` |
| `pgsql_reporting` | Direct PostgreSQL | `127.0.0.1:5432` |
| `sqlite_local` | SQLite file | `examples/database.sqlite` |
| `mysql_split` | Direct writer plus two read replicas | Writer from `mysql_main`; replicas `replica1.internal`, `replica2.internal` |
| `mysql_proxy` | ProxySQL owns routing/backend pooling | `127.0.0.1:6033` |
| `mariadb_proxy` | MaxScale owns routing/backend pooling | `127.0.0.1:4006` |
| `pgsql_pooler` | PgBouncer, using session pooling | `127.0.0.1:6432` |
| `pgsql_middleware` | Pgpool-II owns routing/backend pooling | `127.0.0.1:9999` |
| `mssql_listener` | Always On listener, with read-only routing | `ag-listener.internal:1433` |

Endpoint defaults are examples and can be changed to the deployment's actual
ports. ProxySQL and MaxScale can both serve MySQL/MariaDB-compatible traffic;
use the driver matching the backend SQL dialect. Separate reader/writer proxy
endpoints can be placed in `read`/`write`, while a single routing endpoint should
leave those options unset.

The direct and proxy MySQL profiles share `DBLAYER_MYSQL_DATABASE`,
`DBLAYER_MYSQL_USERNAME`, and `DBLAYER_MYSQL_PASSWORD`. PostgreSQL profiles share
the corresponding `DBLAYER_PGSQL_*` credentials/database. The MariaDB and SQL
Server profiles use `DBLAYER_MARIADB_*` and `DBLAYER_MSSQL_*` respectively.
The `secret` passwords are local demonstration placeholders; supply actual
credentials through the host environment or secret configuration.

| Endpoint settings | Environment variables |
| --- | --- |
| Direct MySQL | `DBLAYER_MYSQL_HOST`, `DBLAYER_MYSQL_PORT` |
| Direct PostgreSQL | `DBLAYER_PGSQL_HOST`, `DBLAYER_PGSQL_PORT` |
| Direct MySQL replicas | `DBLAYER_MYSQL_REPLICA_1_HOST`, `DBLAYER_MYSQL_REPLICA_2_HOST` |
| ProxySQL | `DBLAYER_PROXYSQL_HOST`, `DBLAYER_PROXYSQL_PORT` |
| MaxScale | `DBLAYER_MAXSCALE_HOST`, `DBLAYER_MAXSCALE_PORT` |
| PgBouncer | `DBLAYER_PGBOUNCER_HOST`, `DBLAYER_PGBOUNCER_PORT` |
| Pgpool-II | `DBLAYER_PGPOOL_HOST`, `DBLAYER_PGPOOL_PORT` |
| SQL Server listener | `DBLAYER_MSSQL_HOST`, `DBLAYER_MSSQL_PORT` |
| SQLite file | `DBLAYER_SQLITE_PATH` |

Direct PostgreSQL retains the local `sslmode=prefer` example. For production,
configure TLS and server identity verification on the actual client-facing
endpoint, normally `sslmode=verify-full` for PostgreSQL and verified CA/hostname
settings for MySQL/MariaDB. SQL Server uses encryption with certificate
verification enabled. See [configuration](../docs/configuration.rst).

## Worker-local pooling

Run the bounded three-task simulation with a file-backed SQLite database:

```sh
DBLAYER_SQLITE_PATH=/tmp/dblayer-example.sqlite php examples/pooled_worker.php
```

To target a configured proxy instead:

```sh
php examples/pooled_worker.php mysql_proxy
```

The bootstrap pool settings warm **2** primary handles, allow up to **10**
connection objects, expire idle objects after **60 seconds**, rotate handles
after **3600 seconds**, and probe health at **30-second** intervals. Warmup is
explicit; it does not create a background replenishment loop. Set zero only
when intentionally disabling the corresponding expiry/lifetime/probe control.
One connection object may hold a writer and a reader PDO, so budget native
sessions across every PHP worker and any external proxy pools.

The MySQL/PostgreSQL/SQL Server profiles also show a native connection timeout
of **5 seconds**. This is separate from pool idle expiry and maximum lifetime.
Query deadlines/cancellation are execution controls; they do not promise an
instant kill of a blocking PDO call or a safe automatic replay of failed writes.

In a real host, retain one manager per worker generation, create/warm it after
fork, and close it during drain after all active leases finish. The example uses
`PoolManager::using()` to release each task's exclusive lease even on failure.
Do not enable PDO `persistent` merely to use DBLayer's pool; its live handles
already survive between tasks while the worker owns the pool.

The example's `runPooledTask()` accepts the host's existing Runwire objects:

```php
$value = runPooledTask($manager, $activeRuntime, $activeRequest, $activeScope);
```

Copy the task helper into the host's service with its namespace/imports. Pass
the same objects through intermediate libraries. Omit the runtime for the
ordinary PDO path; Runwire is optional. The helper does not create a runtime or
own request/task completion, workers, listeners, or event loops. PDO stays
synchronous. The CLI simulation uses the ordinary path.

## Replica routing and SQLite

```sh
php examples/read_replicas.php
```

This self-contained example creates an owned temporary SQLite file, explicitly
enables WAL, writes one row, and reads it through two read handles. It reconnects
the read channel to demonstrate round-robin selection, then closes handles and
removes its temporary files. All handles share one file; this demonstrates
routing, not a replicated SQLite cluster. WAL still permits only one writer at
a time, and independent `:memory:` handles contain independent databases.

For an actual replicated server deployment, select `mysql_split`, provision
replication externally, and account for replica lag. Its `sticky=true` routes
reads to the writer after a write within the execution scope. Read-replica
failover does not perform primary promotion or health-aware writer failover.

PgBouncer transaction pooling requires explicit prepared-statement and
session-state validation; disabling the statement cache alone does not solve
that. Its profile retains the PostgreSQL `schema=public` default, which sends
startup `search_path`. An empty/null schema also receives that default; it does
not disable the startup parameter. Configure PgBouncer to accept and preserve
the intended parameter using its version's [parameter tracking](https://www.pgbouncer.org/config.html#track_extra_parameters)
support. SQL Server's listener
profile requires configured read-only routing and does not expose
`MultiSubnetFailover`; ODBC pooling is configured separately on Linux/macOS.
See the [deployment guide](../docs/connections.rst#external-proxies-and-database-listeners)
for configuration limits and expected compatibility rather than blanket
certification of every proxy mode or HA topology.
