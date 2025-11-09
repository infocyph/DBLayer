<?php
declare(strict_types=1);
namespace Infocyph\DBLayer\Grammar;
abstract class Grammar {
    protected string $tablePrefix = '';
    abstract public function compileSelect(array $query): string;
    abstract public function compileInsert(array $query, array $values): string;
    abstract public function compileUpdate(array $query, array $values): string;
    abstract public function compileDelete(array $query): string;
    abstract protected function wrapIdentifier(string $value): string;
    
    public function wrap(string $value): string {
        if (strpos($value, '.') !== false) {
            return implode('.', array_map([$this, 'wrapIdentifier'], explode('.', $value)));
        }
        return $this->wrapIdentifier($value);
    }
    
    protected function compileColumns(array $columns): string {
        if (empty($columns)) {
            return '*';
        }
        return implode(', ', array_map([$this, 'wrap'], $columns));
    }
    
    protected function compileFrom(string $table): string {
        return 'FROM ' . $this->wrap($table);
    }
    
    protected function compileWheres(array $wheres): string {
        if (empty($wheres)) {
            return '';
        }
        $sql = [];
        foreach ($wheres as $where) {
            $sql[] = $where['boolean'] . ' ' . $this->wrap($where['column']) . ' ' . $where['operator'] . ' ?';
        }
        return 'WHERE ' . preg_replace('/and |or /i', '', implode(' ', $sql), 1);
    }
    
    protected function compileOrders(array $orders): string {
        if (empty($orders)) {
            return '';
        }
        $sql = [];
        foreach ($orders as $order) {
            $sql[] = $this->wrap($order['column']) . ' ' . strtoupper($order['direction']);
        }
        return 'ORDER BY ' . implode(', ', $sql);
    }
    
    protected function compileLimit(?int $limit): string {
        return $limit !== null ? "LIMIT {$limit}" : '';
    }
    
    protected function compileOffset(?int $offset): string {
        return $offset !== null ? "OFFSET {$offset}" : '';
    }
}
