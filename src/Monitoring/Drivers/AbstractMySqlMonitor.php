<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Monitoring\Drivers;

/** @internal */
abstract class AbstractMySqlMonitor extends AbstractDatabaseMonitor
{
    #[\Override]
    public function status(): array
    {
        $statusRows = $this->query(
            "SHOW GLOBAL STATUS WHERE Variable_name IN ('Uptime','Connections','Threads_connected','Threads_running','Queries','Slow_queries','Innodb_buffer_pool_reads','Innodb_buffer_pool_read_requests','Innodb_row_lock_current_waits')",
        );
        $variableRows = $this->query(
            "SHOW GLOBAL VARIABLES WHERE Variable_name IN ('max_connections','read_only')",
        );
        $values = $this->variables([...$statusRows, ...$variableRows]);
        $bufferRequests = $this->intValue($values['Innodb_buffer_pool_read_requests'] ?? 0);
        $bufferReads = $this->intValue($values['Innodb_buffer_pool_reads'] ?? 0);

        return [
            'server_version' => $this->serverVersion(),
            'uptime_seconds' => $this->intValue($values['Uptime'] ?? 0),
            'connections_total' => $this->intValue($values['Connections'] ?? 0),
            'connections_active' => $this->intValue($values['Threads_connected'] ?? 0),
            'threads_running' => $this->intValue($values['Threads_running'] ?? 0),
            'max_connections' => $this->intValue($values['max_connections'] ?? 0),
            'queries_total' => $this->intValue($values['Queries'] ?? 0),
            'slow_queries' => $this->intValue($values['Slow_queries'] ?? 0),
            'lock_waits' => $this->intValue($values['Innodb_row_lock_current_waits'] ?? 0),
            'read_only' => ($values['read_only'] ?? 'OFF') === 'ON',
            'buffer_pool_hit_percent' => $bufferRequests > 0
                ? round((1 - ($bufferReads / $bufferRequests)) * 100, 4)
                : null,
        ];
    }

    #[\Override]
    public function sessions(): array
    {
        return $this->query(
            'SELECT ID AS id, USER AS user_name, HOST AS host, DB AS database_name, COMMAND AS command, TIME AS seconds, STATE AS state, INFO AS query FROM information_schema.PROCESSLIST WHERE ID <> CONNECTION_ID() ORDER BY TIME DESC',
        );
    }

    #[\Override]
    public function longRunningQueries(int $seconds): array
    {
        return $this->query(
            "SELECT ID AS id, USER AS user_name, HOST AS host, DB AS database_name, COMMAND AS command, TIME AS seconds, STATE AS state, INFO AS query FROM information_schema.PROCESSLIST WHERE ID <> CONNECTION_ID() AND COMMAND <> 'Sleep' AND TIME >= ? ORDER BY TIME DESC",
            [max(1, $seconds)],
        );
    }

    #[\Override]
    public function tableMetrics(): array
    {
        return $this->query(
            "SELECT TABLE_SCHEMA AS schema_name, TABLE_NAME AS table_name, ENGINE AS engine, TABLE_ROWS AS estimated_rows, DATA_LENGTH AS data_bytes, INDEX_LENGTH AS index_bytes, DATA_FREE AS free_bytes, AUTO_INCREMENT AS auto_increment FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC, TABLE_NAME",
        );
    }

    #[\Override]
    public function indexMetrics(): array
    {
        return $this->query(
            'SELECT OBJECT_SCHEMA AS schema_name, OBJECT_NAME AS table_name, INDEX_NAME AS index_name, COUNT_READ AS reads, COUNT_WRITE AS writes, COUNT_FETCH AS fetches, COUNT_INSERT AS inserts, COUNT_UPDATE AS updates, COUNT_DELETE AS deletes FROM performance_schema.table_io_waits_summary_by_index_usage WHERE OBJECT_SCHEMA = DATABASE() AND INDEX_NAME IS NOT NULL ORDER BY COUNT_READ DESC, OBJECT_NAME, INDEX_NAME',
        );
    }

    #[\Override]
    public function maintenance(): array
    {
        return $this->query(
            "SELECT TABLE_SCHEMA AS schema_name, TABLE_NAME AS table_name, ENGINE AS engine, TABLE_ROWS AS estimated_rows, DATA_LENGTH AS data_bytes, INDEX_LENGTH AS index_bytes, DATA_FREE AS reclaimable_bytes, CASE WHEN DATA_LENGTH > 0 THEN ROUND((DATA_FREE / DATA_LENGTH) * 100, 2) ELSE 0 END AS reclaimable_percent FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND DATA_FREE > 0 ORDER BY DATA_FREE DESC LIMIT 50",
        );
    }

    /** @param list<array<string,mixed>> $rows @return array<string,mixed> */
    private function variables(array $rows): array
    {
        $values = [];
        foreach ($rows as $row) {
            $name = $row['Variable_name'] ?? null;
            if (is_string($name) && $name !== '') {
                $values[$name] = $row['Value'] ?? null;
            }
        }

        return $values;
    }
}
