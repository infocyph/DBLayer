<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Monitoring\Drivers;

final class SQLiteMonitor extends AbstractDatabaseMonitor
{
    #[\Override]
    public function status(): array
    {
        $pageCount = $this->intValue($this->scalar('PRAGMA page_count'));
        $pageSize = $this->intValue($this->scalar('PRAGMA page_size'));
        $freePages = $this->intValue($this->scalar('PRAGMA freelist_count'));

        return [
            'server_version' => $this->serverVersion(),
            'database_bytes' => $pageCount * $pageSize,
            'page_count' => $pageCount,
            'page_size' => $pageSize,
            'free_pages' => $freePages,
            'journal_mode' => $this->scalar('PRAGMA journal_mode'),
            'foreign_keys' => $this->intValue($this->scalar('PRAGMA foreign_keys')) === 1,
        ];
    }

    #[\Override]
    public function sessions(): array
    {
        return [];
    }

    #[\Override]
    public function longRunningQueries(int $seconds): array
    {
        unset($seconds);

        return [];
    }

    #[\Override]
    public function locks(): array
    {
        return [];
    }

    #[\Override]
    public function tableMetrics(): array
    {
        return $this->query(
            "SELECT name AS table_name, sql FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name",
        );
    }

    #[\Override]
    public function indexMetrics(): array
    {
        return $this->query(
            "SELECT name AS index_name, tbl_name AS table_name, sql FROM sqlite_master WHERE type = 'index' AND name NOT LIKE 'sqlite_autoindex_%' ORDER BY tbl_name, name",
        );
    }

    #[\Override]
    public function replication(): array
    {
        return [];
    }

    #[\Override]
    public function maintenance(): array
    {
        $pageCount = $this->intValue($this->scalar('PRAGMA page_count'));
        $pageSize = $this->intValue($this->scalar('PRAGMA page_size'));
        $freePages = $this->intValue($this->scalar('PRAGMA freelist_count'));

        return [[
            'page_count' => $pageCount,
            'page_size' => $pageSize,
            'free_pages' => $freePages,
            'reclaimable_bytes' => $freePages * $pageSize,
            'reclaimable_percent' => $pageCount > 0 ? round(($freePages / $pageCount) * 100, 4) : 0.0,
        ]];
    }
}
