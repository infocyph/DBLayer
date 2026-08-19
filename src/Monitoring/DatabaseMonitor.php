<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Monitoring;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Exceptions\ConnectionException;
use Infocyph\DBLayer\Monitoring\Drivers\AbstractDatabaseMonitor;
use Infocyph\DBLayer\Monitoring\Drivers\MariaDBMonitor;
use Infocyph\DBLayer\Monitoring\Drivers\MySQLMonitor;
use Infocyph\DBLayer\Monitoring\Drivers\PostgreSQLMonitor;
use Infocyph\DBLayer\Monitoring\Drivers\SQLServerMonitor;
use Infocyph\DBLayer\Monitoring\Drivers\SQLiteMonitor;
use Throwable;

/**
 * Explicit, on-demand database-system monitoring surface.
 *
 * Construction is cheap. Database inspection queries execute only when one of
 * the monitoring methods is called.
 */
final class DatabaseMonitor
{
    private readonly AbstractDatabaseMonitor $driverMonitor;

    public function __construct(private readonly Connection $connection)
    {
        $this->driverMonitor = match ($connection->getDriverName()) {
            'mysql' => new MySQLMonitor($connection),
            'mariadb' => new MariaDBMonitor($connection),
            'mssql' => new SQLServerMonitor($connection),
            'pgsql' => new PostgreSQLMonitor($connection),
            'sqlite' => new SQLiteMonitor($connection),
            default => throw ConnectionException::unsupportedDriver($connection->getDriverName()),
        };
    }

    /**
     * Lightweight database/server status. Status intentionally lives under the
     * monitor surface instead of adding another DB facade shortcut.
     *
     * @return array<string,mixed>
     */
    public function status(): array
    {
        $server = $this->driverMonitor->status();

        return [
            'driver' => $this->connection->getDriverName(),
            'database' => $this->connection->getDatabaseName(),
            'connected' => $this->connection->isConnected(),
            'transaction_level' => $this->connection->managedTransactionLevel(),
            'sticky_write' => $this->connection->hasStickyWrite(),
            'connection_stats' => $this->connection->getStats(),
            'replica' => $this->connection->getReadReplicaInfo(),
            'server' => $server,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function sessions(): array
    {
        return $this->driverMonitor->sessions();
    }

    /** @return list<array<string,mixed>> */
    public function longRunningQueries(int $seconds = 10): array
    {
        return $this->driverMonitor->longRunningQueries(max(1, $seconds));
    }

    /** @return list<array<string,mixed>> */
    public function locks(): array
    {
        return $this->driverMonitor->locks();
    }

    /** @return list<array<string,mixed>> */
    public function tableMetrics(): array
    {
        return $this->driverMonitor->tableMetrics();
    }

    /** @return list<array<string,mixed>> */
    public function indexMetrics(): array
    {
        return $this->driverMonitor->indexMetrics();
    }

    /** @return list<array<string,mixed>> */
    public function replication(): array
    {
        return $this->driverMonitor->replication();
    }

    /** @return list<array<string,mixed>> */
    public function maintenance(): array
    {
        return $this->driverMonitor->maintenance();
    }

    /**
     * Collect a resilient operational snapshot. A permission failure in one
     * engine-specific section does not hide the other available sections.
     *
     * Maintenance can be more expensive (for example SQL Server physical index
     * inspection), so it is opt-in even inside a full snapshot.
     *
     * @return array<string,mixed>
     */
    public function snapshot(int $longRunningSeconds = 10, bool $includeMaintenance = false): array
    {
        $errors = [];
        $sections = [
            'status' => fn(): array => $this->status(),
            'sessions' => fn(): array => $this->sessions(),
            'long_running_queries' => fn(): array => $this->longRunningQueries($longRunningSeconds),
            'locks' => fn(): array => $this->locks(),
            'table_metrics' => fn(): array => $this->tableMetrics(),
            'index_metrics' => fn(): array => $this->indexMetrics(),
            'replication' => fn(): array => $this->replication(),
        ];

        if ($includeMaintenance) {
            $sections['maintenance'] = fn(): array => $this->maintenance();
        }

        $snapshot = [
            'collected_at' => gmdate(DATE_ATOM),
            'driver' => $this->connection->getDriverName(),
            'database' => $this->connection->getDatabaseName(),
        ];

        foreach ($sections as $name => $collector) {
            try {
                $snapshot[$name] = $collector();
            } catch (Throwable $exception) {
                $snapshot[$name] = null;
                $errors[$name] = [
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        $snapshot['errors'] = $errors;

        return $snapshot;
    }
}
