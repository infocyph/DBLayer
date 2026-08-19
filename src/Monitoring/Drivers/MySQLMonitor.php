<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Monitoring\Drivers;

final class MySQLMonitor extends AbstractMySqlMonitor
{
    #[\Override]
    public function locks(): array
    {
        return $this->query(
            'SELECT rw.REQUESTING_ENGINE_TRANSACTION_ID AS waiting_transaction_id, rw.BLOCKING_ENGINE_TRANSACTION_ID AS blocking_transaction_id, rl.OBJECT_SCHEMA AS schema_name, rl.OBJECT_NAME AS table_name, rl.INDEX_NAME AS index_name, rl.LOCK_TYPE AS waiting_lock_type, rl.LOCK_MODE AS waiting_lock_mode, bl.LOCK_TYPE AS blocking_lock_type, bl.LOCK_MODE AS blocking_lock_mode FROM performance_schema.data_lock_waits rw JOIN performance_schema.data_locks rl ON rl.ENGINE_LOCK_ID = rw.REQUESTING_ENGINE_LOCK_ID JOIN performance_schema.data_locks bl ON bl.ENGINE_LOCK_ID = rw.BLOCKING_ENGINE_LOCK_ID ORDER BY rl.OBJECT_SCHEMA, rl.OBJECT_NAME',
        );
    }

    #[\Override]
    public function longRunningQueries(int $seconds): array
    {
        return $this->query(
            "SELECT ID AS id, USER AS user_name, HOST AS host, DB AS database_name, COMMAND AS command, TIME AS seconds, STATE AS state, INFO AS query FROM performance_schema.processlist WHERE ID <> CONNECTION_ID() AND COMMAND <> 'Sleep' AND TIME >= ? ORDER BY TIME DESC",
            [max(1, $seconds)],
        );
    }

    #[\Override]
    public function replication(): array
    {
        return $this->query('SHOW REPLICA STATUS');
    }

    #[\Override]
    public function sessions(): array
    {
        return $this->query(
            'SELECT ID AS id, USER AS user_name, HOST AS host, DB AS database_name, COMMAND AS command, TIME AS seconds, STATE AS state, INFO AS query FROM performance_schema.processlist WHERE ID <> CONNECTION_ID() ORDER BY TIME DESC',
        );
    }
}
