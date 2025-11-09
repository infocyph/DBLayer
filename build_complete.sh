#!/bin/bash

echo "Building complete DBLayer project (without ORM)..."
echo ""

# Create Grammar files
mkdir -p src/Grammar

echo "Creating Grammar/Grammar.php..."
cat > src/Grammar/Grammar.php << 'EOFPHP'
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
EOFPHP

echo "Creating Grammar/MySQLGrammar.php..."
cat > src/Grammar/MySQLGrammar.php << 'EOFPHP'
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
EOFPHP

echo "Creating Grammar/PostgreSQLGrammar.php..."
cat > src/Grammar/PostgreSQLGrammar.php << 'EOFPHP'
<?php
declare(strict_types=1);
namespace Infocyph\DBLayer\Grammar;
class PostgreSQLGrammar extends Grammar {
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
        return '"' . str_replace('"', '""', $value) . '"';
    }
}
EOFPHP

echo "Creating Grammar/SQLiteGrammar.php..."
cat > src/Grammar/SQLiteGrammar.php << 'EOFPHP'
<?php
declare(strict_types=1);
namespace Infocyph\DBLayer\Grammar;
class SQLiteGrammar extends Grammar {
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
        return '"' . str_replace('"', '""', $value) . '"';
    }
}
EOFPHP

echo ""
echo "✓ Grammar files created successfully!"
echo ""
echo "Grammar layer complete:"
echo "  - Grammar/Grammar.php"
echo "  - Grammar/MySQLGrammar.php"
echo "  - Grammar/PostgreSQLGrammar.php"
echo "  - Grammar/SQLiteGrammar.php"
