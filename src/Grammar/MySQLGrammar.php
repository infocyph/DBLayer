<?php
declare(strict_types=1);
namespace Infocyph\DBLayer\Grammar;
class MySQLGrammar extends Grammar {
    public function compileSelect(array $query): string {
        $sql = ['SELECT'];
        $sql[] = $this->compileColumns($query['columns'] ?? ['*']);
        $sql[] = $this->compileFrom($query['from']);
        if (!empty($query['wheres'])) {
            $sql[] = $this->compileWheres($query['wheres']);
        }
        if (!empty($query['orders'])) {
            $sql[] = $this->compileOrders($query['orders']);
        }
        if (isset($query['limit'])) {
            $sql[] = $this->compileLimit($query['limit']);
        }
        if (isset($query['offset'])) {
            $sql[] = $this->compileOffset($query['offset']);
        }
        return implode(' ', array_filter($sql));
    }
    
    public function compileInsert(array $query, array $values): string {
        $table = $this->wrap($query['from']);
        $columns = implode(', ', array_map([$this, 'wrap'], array_keys($values)));
        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        return "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
    }
    
    public function compileUpdate(array $query, array $values): string {
        $table = $this->wrap($query['from']);
        $columns = [];
        foreach (array_keys($values) as $key) {
            $columns[] = $this->wrap($key) . ' = ?';
        }
        $sql = "UPDATE {$table} SET " . implode(', ', $columns);
        if (!empty($query['wheres'])) {
            $sql .= ' ' . $this->compileWheres($query['wheres']);
        }
        return $sql;
    }
    
    public function compileDelete(array $query): string {
        $sql = "DELETE FROM " . $this->wrap($query['from']);
        if (!empty($query['wheres'])) {
            $sql .= ' ' . $this->compileWheres($query['wheres']);
        }
        return $sql;
    }
    
    protected function wrapIdentifier(string $value): string {
        if ($value === '*') {
            return $value;
        }
        return '`' . str_replace('`', '``', $value) . '`';
    }
}
