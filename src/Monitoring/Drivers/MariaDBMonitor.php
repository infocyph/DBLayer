<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Monitoring\Drivers;

final class MariaDBMonitor extends AbstractMySqlMonitor
{
    #[\Override]
    public function locks(): array
    {
        return $this->query(
            'SELECT w.requesting_trx_id AS waiting_transaction_id, r.trx_mysql_thread_id AS waiting_thread_id, r.trx_started AS waiting_started, r.trx_query AS waiting_query, w.blocking_trx_id AS blocking_transaction_id, b.trx_mysql_thread_id AS blocking_thread_id, b.trx_started AS blocking_started, b.trx_query AS blocking_query FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_TRX r ON r.trx_id = w.requesting_trx_id JOIN information_schema.INNODB_TRX b ON b.trx_id = w.blocking_trx_id ORDER BY r.trx_started',
        );
    }

    #[\Override]
    public function replication(): array
    {
        return $this->query('SHOW SLAVE STATUS');
    }
}
