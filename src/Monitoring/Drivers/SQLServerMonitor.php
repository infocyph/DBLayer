<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Monitoring\Drivers;

final class SQLServerMonitor extends AbstractDatabaseMonitor
{
    #[\Override]
    public function indexMetrics(): array
    {
        return $this->query(
            'SELECT s.name AS schema_name, t.name AS table_name, i.name AS index_name, i.type_desc, i.is_unique, COALESCE(u.user_seeks, 0) AS user_seeks, COALESCE(u.user_scans, 0) AS user_scans, COALESCE(u.user_lookups, 0) AS user_lookups, COALESCE(u.user_updates, 0) AS user_updates, u.last_user_seek, u.last_user_scan, u.last_user_lookup, u.last_user_update FROM sys.indexes i JOIN sys.tables t ON t.object_id = i.object_id JOIN sys.schemas s ON s.schema_id = t.schema_id LEFT JOIN sys.dm_db_index_usage_stats u ON u.database_id = DB_ID() AND u.object_id = i.object_id AND u.index_id = i.index_id WHERE i.index_id > 0 AND i.name IS NOT NULL ORDER BY (COALESCE(u.user_seeks, 0) + COALESCE(u.user_scans, 0) + COALESCE(u.user_lookups, 0)) DESC, s.name, t.name, i.name',
        );
    }

    #[\Override]
    public function locks(): array
    {
        return $this->query(
            'SELECT l.request_session_id AS session_id, l.resource_type, l.resource_database_id, l.resource_associated_entity_id, l.request_mode, l.request_status, r.blocking_session_id, r.wait_type, r.wait_time, txt.text AS query FROM sys.dm_tran_locks l LEFT JOIN sys.dm_exec_requests r ON r.session_id = l.request_session_id OUTER APPLY sys.dm_exec_sql_text(r.sql_handle) txt WHERE l.resource_database_id IN (0, DB_ID()) ORDER BY l.request_session_id, l.resource_type',
        );
    }

    #[\Override]
    public function longRunningQueries(int $seconds): array
    {
        return $this->query(
            'SELECT s.session_id, s.login_name, s.host_name, s.program_name, r.status, r.command, r.wait_type, r.wait_time, r.blocking_session_id, r.cpu_time, r.total_elapsed_time, r.reads, r.writes, txt.text AS query FROM sys.dm_exec_requests r JOIN sys.dm_exec_sessions s ON s.session_id = r.session_id OUTER APPLY sys.dm_exec_sql_text(r.sql_handle) txt WHERE r.session_id <> @@SPID AND r.total_elapsed_time >= ? ORDER BY r.total_elapsed_time DESC',
            [max(1, $seconds) * 1_000],
        );
    }

    #[\Override]
    public function maintenance(): array
    {
        return $this->query(
            "SELECT s.name AS schema_name, t.name AS table_name, i.name AS index_name, ps.index_type_desc, ps.avg_fragmentation_in_percent, ps.page_count, ps.record_count FROM sys.dm_db_index_physical_stats(DB_ID(), NULL, NULL, NULL, 'LIMITED') ps JOIN sys.tables t ON t.object_id = ps.object_id JOIN sys.schemas s ON s.schema_id = t.schema_id JOIN sys.indexes i ON i.object_id = ps.object_id AND i.index_id = ps.index_id WHERE ps.index_id > 0 AND ps.page_count >= 1000 ORDER BY ps.avg_fragmentation_in_percent DESC, ps.page_count DESC",
        );
    }

    #[\Override]
    public function replication(): array
    {
        return $this->query(
            'SELECT DB_NAME(database_id) AS database_name, synchronization_state_desc, synchronization_health_desc, database_state_desc, is_suspended, suspend_reason_desc, is_commit_participant, log_send_queue_size, log_send_rate, redo_queue_size, redo_rate, last_commit_time FROM sys.dm_hadr_database_replica_states WHERE is_local = 1 ORDER BY database_id',
        );
    }

    #[\Override]
    public function sessions(): array
    {
        return $this->query(
            'SELECT s.session_id, s.login_name, s.host_name, s.program_name, s.status AS session_status, s.open_transaction_count, r.status AS request_status, r.command, r.wait_type, r.wait_time, r.blocking_session_id, r.cpu_time, r.total_elapsed_time, txt.text AS query FROM sys.dm_exec_sessions s LEFT JOIN sys.dm_exec_requests r ON r.session_id = s.session_id OUTER APPLY sys.dm_exec_sql_text(r.sql_handle) txt WHERE s.is_user_process = 1 AND s.session_id <> @@SPID ORDER BY COALESCE(r.total_elapsed_time, 0) DESC, s.session_id',
        );
    }

    #[\Override]
    public function status(): array
    {
        return $this->query(
            "SELECT CAST(SERVERPROPERTY('ProductVersion') AS nvarchar(128)) AS server_version, CAST(SERVERPROPERTY('ProductLevel') AS nvarchar(128)) AS product_level, CAST(SERVERPROPERTY('Edition') AS nvarchar(128)) AS edition, DB_NAME() AS database_name, @@SPID AS connection_id, CAST(DATABASEPROPERTYEX(DB_NAME(), 'Status') AS nvarchar(128)) AS database_status, CAST(DATABASEPROPERTYEX(DB_NAME(), 'Updateability') AS nvarchar(128)) AS updateability, (SELECT SUM(CAST(size AS bigint)) * 8192 FROM sys.database_files) AS database_bytes",
        )[0] ?? [];
    }

    #[\Override]
    public function tableMetrics(): array
    {
        return $this->query(
            'SELECT s.name AS schema_name, t.name AS table_name, SUM(CASE WHEN p.index_id IN (0,1) THEN p.rows ELSE 0 END) AS row_count, SUM(a.total_pages) * 8192 AS total_bytes, SUM(a.used_pages) * 8192 AS used_bytes, SUM(a.data_pages) * 8192 AS data_bytes FROM sys.tables t JOIN sys.schemas s ON s.schema_id = t.schema_id JOIN sys.indexes i ON i.object_id = t.object_id JOIN sys.partitions p ON p.object_id = i.object_id AND p.index_id = i.index_id JOIN sys.allocation_units a ON a.container_id = p.partition_id GROUP BY s.name, t.name ORDER BY total_bytes DESC, s.name, t.name',
        );
    }
}
