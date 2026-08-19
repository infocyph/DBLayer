<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Monitoring\Drivers;

final class PostgreSQLMonitor extends AbstractDatabaseMonitor
{
    #[\Override]
    public function status(): array
    {
        return $this->query(
            "SELECT version() AS server_version, current_database() AS database_name, EXTRACT(EPOCH FROM (clock_timestamp() - pg_postmaster_start_time()))::bigint AS uptime_seconds, pg_database_size(current_database()) AS database_bytes, (SELECT count(*) FROM pg_stat_activity WHERE datname = current_database()) AS connections_active, (SELECT count(*) FROM pg_stat_activity WHERE datname = current_database() AND state = 'active') AS sessions_running, d.numbackends AS num_backends, d.xact_commit, d.xact_rollback, CASE WHEN (d.xact_commit + d.xact_rollback) > 0 THEN round((d.xact_commit::numeric * 100) / (d.xact_commit + d.xact_rollback), 4) ELSE NULL END AS commit_percent, d.deadlocks, d.conflicts, d.temp_files, d.temp_bytes, CASE WHEN (d.blks_hit + d.blks_read) > 0 THEN round((d.blks_hit::numeric * 100) / (d.blks_hit + d.blks_read), 4) ELSE NULL END AS cache_hit_percent FROM pg_stat_database d WHERE d.datname = current_database()",
        )[0] ?? [];
    }

    #[\Override]
    public function sessions(): array
    {
        return $this->query(
            "SELECT pid, usename AS user_name, application_name, client_addr::text AS client_address, state, wait_event_type, wait_event, backend_start, xact_start, query_start, state_change, CASE WHEN query_start IS NULL THEN NULL ELSE EXTRACT(EPOCH FROM (clock_timestamp() - query_start))::bigint END AS query_seconds, query FROM pg_stat_activity WHERE datname = current_database() AND pid <> pg_backend_pid() ORDER BY query_start NULLS LAST",
        );
    }

    #[\Override]
    public function longRunningQueries(int $seconds): array
    {
        return $this->query(
            "SELECT pid, usename AS user_name, application_name, client_addr::text AS client_address, state, wait_event_type, wait_event, EXTRACT(EPOCH FROM (clock_timestamp() - query_start))::bigint AS query_seconds, query_start, query FROM pg_stat_activity WHERE datname = current_database() AND pid <> pg_backend_pid() AND state <> 'idle' AND query_start IS NOT NULL AND clock_timestamp() - query_start >= make_interval(secs => ?) ORDER BY query_start",
            [max(1, $seconds)],
        );
    }

    #[\Override]
    public function locks(): array
    {
        return $this->query(
            'SELECT a.pid, a.usename AS user_name, a.state, a.wait_event_type, a.wait_event, pg_blocking_pids(a.pid) AS blocked_by, a.query AS blocked_query FROM pg_stat_activity a WHERE a.datname = current_database() AND cardinality(pg_blocking_pids(a.pid)) > 0 ORDER BY a.query_start',
        );
    }

    #[\Override]
    public function tableMetrics(): array
    {
        return $this->query(
            'SELECT schemaname AS schema_name, relname AS table_name, n_live_tup AS estimated_rows, n_dead_tup AS dead_rows, seq_scan, seq_tup_read, idx_scan, idx_tup_fetch, n_tup_ins, n_tup_upd, n_tup_del, n_tup_hot_upd, pg_relation_size(relid) AS table_bytes, pg_total_relation_size(relid) AS total_bytes FROM pg_stat_user_tables ORDER BY pg_total_relation_size(relid) DESC, relname',
        );
    }

    #[\Override]
    public function indexMetrics(): array
    {
        return $this->query(
            'SELECT schemaname AS schema_name, relname AS table_name, indexrelname AS index_name, idx_scan, idx_tup_read, idx_tup_fetch, pg_relation_size(indexrelid) AS index_bytes FROM pg_stat_user_indexes ORDER BY idx_scan DESC, pg_relation_size(indexrelid) DESC',
        );
    }

    #[\Override]
    public function replication(): array
    {
        return $this->query(
            'SELECT pid, usename AS user_name, application_name, client_addr::text AS client_address, state, sync_state, sent_lsn::text, write_lsn::text, flush_lsn::text, replay_lsn::text, write_lag, flush_lag, replay_lag FROM pg_stat_replication ORDER BY application_name, client_addr',
        );
    }

    #[\Override]
    public function maintenance(): array
    {
        return $this->query(
            'SELECT schemaname AS schema_name, relname AS table_name, n_live_tup AS live_rows, n_dead_tup AS dead_rows, last_vacuum, last_autovacuum, last_analyze, last_autoanalyze, vacuum_count, autovacuum_count, analyze_count, autoanalyze_count FROM pg_stat_user_tables ORDER BY n_dead_tup DESC, relname',
        );
    }
}
