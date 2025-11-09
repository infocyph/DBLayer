<?php
declare(strict_types=1);
namespace Infocyph\DBLayer;
use Infocyph\DBLayer\Grammar\Grammar;
use Infocyph\DBLayer\Grammar\MySQLGrammar;
use Infocyph\DBLayer\Grammar\PostgreSQLGrammar;
use Infocyph\DBLayer\Grammar\SQLiteGrammar;
use Closure;

class QueryBuilder {
    private Connection $connection;
    private Executor $executor;
    private Grammar $grammar;
    private ?string $from = null;
    private array $columns = ['*'];
    private array $wheres = [];
    private array $orders = [];
    private ?int $limit = null;
    private ?int $offset = null;
    private array $bindings = [];
    
    public function __construct(Connection $connection) {
        $this->connection = $connection;
        $this->executor = new Executor($connection);
        $this->grammar = $this->createGrammar($connection->getDriver());
    }
    
    private function createGrammar(string $driver): Grammar {
        return match($driver) {
            'mysql' => new MySQLGrammar(),
            'pgsql' => new PostgreSQLGrammar(),
            'sqlite' => new SQLiteGrammar(),
            default => throw new InvalidArgumentException("Unsupported driver: {$driver}")
        };
    }
    
    public function table(string $table): self {
        $this->from = Security::sanitizeTableName($table);
        return $this;
    }
    
    public function from(string $table): self {
        return $this->table($table);
    }
    
    public function select(string|array ...$columns): self {
        $this->columns = [];
        foreach ($columns as $column) {
            if (is_array($column)) {
                $this->columns = array_merge($this->columns, $column);
            } else {
                $this->columns[] = $column;
            }
        }
        return $this;
    }
    
    public function where(string|Closure $column, mixed $operator = null, mixed $value = null): self {
        if ($column instanceof Closure) {
            return $this->whereNested($column);
        }
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }
        Security::validateOperator($operator);
        Security::sanitizeColumnName($column);
        $this->wheres[] = [
            'type' => 'basic',
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
            'boolean' => empty($this->wheres) ? 'and' : 'and'
        ];
        $this->bindings[] = $value;
        return $this;
    }
    
    public function orWhere(string|Closure $column, mixed $operator = null, mixed $value = null): self {
        if ($column instanceof Closure) {
            return $this->whereNested($column, 'or');
        }
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }
        Security::validateOperator($operator);
        Security::sanitizeColumnName($column);
        $this->wheres[] = [
            'type' => 'basic',
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
            'boolean' => 'or'
        ];
        $this->bindings[] = $value;
        return $this;
    }
    
    public function whereIn(string $column, array $values): self {
        Security::sanitizeColumnName($column);
        $this->wheres[] = [
            'type' => 'in',
            'column' => $column,
            'values' => $values,
            'boolean' => empty($this->wheres) ? 'and' : 'and'
        ];
        $this->bindings = array_merge($this->bindings, $values);
        return $this;
    }
    
    public function whereNotIn(string $column, array $values): self {
        Security::sanitizeColumnName($column);
        $this->wheres[] = [
            'type' => 'not_in',
            'column' => $column,
            'values' => $values,
            'boolean' => empty($this->wheres) ? 'and' : 'and'
        ];
        $this->bindings = array_merge($this->bindings, $values);
        return $this;
    }
    
    public function whereNull(string $column): self {
        Security::sanitizeColumnName($column);
        $this->wheres[] = [
            'type' => 'null',
            'column' => $column,
            'boolean' => empty($this->wheres) ? 'and' : 'and'
        ];
        return $this;
    }
    
    public function whereNotNull(string $column): self {
        Security::sanitizeColumnName($column);
        $this->wheres[] = [
            'type' => 'not_null',
            'column' => $column,
            'boolean' => empty($this->wheres) ? 'and' : 'and'
        ];
        return $this;
    }
    
    public function whereBetween(string $column, array $values): self {
        if (count($values) !== 2) {
            throw new InvalidArgumentException("whereBetween requires exactly 2 values");
        }
        Security::sanitizeColumnName($column);
        $this->wheres[] = [
            'type' => 'between',
            'column' => $column,
            'values' => $values,
            'boolean' => empty($this->wheres) ? 'and' : 'and'
        ];
        $this->bindings = array_merge($this->bindings, $values);
        return $this;
    }
    
    private function whereNested(Closure $callback, string $boolean = 'and'): self {
        $query = new self($this->connection);
        $callback($query);
        if (!empty($query->wheres)) {
            $this->wheres[] = [
                'type' => 'nested',
                'query' => $query,
                'boolean' => $boolean
            ];
            $this->bindings = array_merge($this->bindings, $query->bindings);
        }
        return $this;
    }
    
    public function orderBy(string $column, string $direction = 'asc'): self {
        $direction = strtolower($direction);
        if (!in_array($direction, ['asc', 'desc'])) {
            throw new InvalidArgumentException("Order direction must be 'asc' or 'desc'");
        }
        Security::sanitizeColumnName($column);
        $this->orders[] = [
            'column' => $column,
            'direction' => $direction
        ];
        return $this;
    }
    
    public function orderByDesc(string $column): self {
        return $this->orderBy($column, 'desc');
    }
    
    public function latest(string $column = 'created_at'): self {
        return $this->orderBy($column, 'desc');
    }
    
    public function oldest(string $column = 'created_at'): self {
        return $this->orderBy($column, 'asc');
    }
    
    public function limit(int $value): self {
        if ($value < 0) {
            throw new InvalidArgumentException("Limit must be non-negative");
        }
        $this->limit = $value;
        return $this;
    }
    
    public function take(int $value): self {
        return $this->limit($value);
    }
    
    public function offset(int $value): self {
        if ($value < 0) {
            throw new InvalidArgumentException("Offset must be non-negative");
        }
        $this->offset = $value;
        return $this;
    }
    
    public function skip(int $value): self {
        return $this->offset($value);
    }
    
    public function forPage(int $page, int $perPage = 15): self {
        return $this->offset(($page - 1) * $perPage)->limit($perPage);
    }
    
    public function get(array $columns = ['*']): Collection {
        if ($columns !== ['*']) {
            $this->select(...$columns);
        }
        $sql = $this->grammar->compileSelect($this->toArray());
        $results = $this->executor->select($sql, $this->bindings);
        return new Collection($results);
    }
    
    public function first(array $columns = ['*']): ?array {
        $results = $this->limit(1)->get($columns);
        return $results->first();
    }
    
    public function find(mixed $id, array $columns = ['*']): ?array {
        return $this->where('id', '=', $id)->first($columns);
    }
    
    public function value(string $column): mixed {
        $result = $this->first([$column]);
        return $result[$column] ?? null;
    }
    
    public function pluck(string $column): array {
        return $this->get([$column])->pluck($column)->all();
    }
    
    public function cursor(): \Generator {
        $sql = $this->grammar->compileSelect($this->toArray());
        return $this->executor->cursor($sql, $this->bindings);
    }
    
    public function chunk(int $count, callable $callback): bool {
        $page = 1;
        do {
            $results = $this->forPage($page, $count)->get();
            if ($results->isEmpty()) {
                break;
            }
            if ($callback($results, $page) === false) {
                return false;
            }
            $page++;
        } while ($results->count() === $count);
        return true;
    }
    
    public function count(string $columns = '*'): int {
        $result = $this->executor->selectOne(
            "SELECT COUNT({$columns}) as aggregate FROM " . Security::escapeIdentifier($this->from, $this->connection->getDriver()) . $this->compileWheres(),
            $this->bindings
        );
        return (int) ($result['aggregate'] ?? 0);
    }
    
    public function sum(string $column): float {
        Security::sanitizeColumnName($column);
        $col = Security::escapeIdentifier($column, $this->connection->getDriver());
        $result = $this->executor->selectOne(
            "SELECT SUM({$col}) as aggregate FROM " . Security::escapeIdentifier($this->from, $this->connection->getDriver()) . $this->compileWheres(),
            $this->bindings
        );
        return (float) ($result['aggregate'] ?? 0);
    }
    
    public function avg(string $column): float {
        Security::sanitizeColumnName($column);
        $col = Security::escapeIdentifier($column, $this->connection->getDriver());
        $result = $this->executor->selectOne(
            "SELECT AVG({$col}) as aggregate FROM " . Security::escapeIdentifier($this->from, $this->connection->getDriver()) . $this->compileWheres(),
            $this->bindings
        );
        return (float) ($result['aggregate'] ?? 0);
    }
    
    public function min(string $column): mixed {
        Security::sanitizeColumnName($column);
        $col = Security::escapeIdentifier($column, $this->connection->getDriver());
        $result = $this->executor->selectOne(
            "SELECT MIN({$col}) as aggregate FROM " . Security::escapeIdentifier($this->from, $this->connection->getDriver()) . $this->compileWheres(),
            $this->bindings
        );
        return $result['aggregate'] ?? null;
    }
    
    public function max(string $column): mixed {
        Security::sanitizeColumnName($column);
        $col = Security::escapeIdentifier($column, $this->connection->getDriver());
        $result = $this->executor->selectOne(
            "SELECT MAX({$col}) as aggregate FROM " . Security::escapeIdentifier($this->from, $this->connection->getDriver()) . $this->compileWheres(),
            $this->bindings
        );
        return $result['aggregate'] ?? null;
    }
    
    public function exists(): bool {
        return $this->count() > 0;
    }
    
    public function doesntExist(): bool {
        return !$this->exists();
    }
    
    public function insert(array $values): bool {
        if (empty($values)) {
            return false;
        }
        $isList = array_keys($values) !== range(0, count($values) - 1);
        if (!$isList) {
            foreach ($values as $row) {
                $this->insert($row);
            }
            return true;
        }
        $sql = $this->grammar->compileInsert($this->toArray(), $values);
        return $this->executor->insert($sql, array_values($values));
    }
    
    public function insertGetId(array $values, ?string $sequence = null): int {
        $sql = $this->grammar->compileInsert($this->toArray(), $values);
        return $this->executor->insertGetId($sql, array_values($values), $sequence);
    }
    
    public function update(array $values): int {
        if (empty($values)) {
            return 0;
        }
        $sql = $this->grammar->compileUpdate($this->toArray(), $values);
        $bindings = array_merge(array_values($values), $this->bindings);
        return $this->executor->update($sql, $bindings);
    }
    
    public function increment(string $column, int $amount = 1): int {
        Security::sanitizeColumnName($column);
        $col = Security::escapeIdentifier($column, $this->connection->getDriver());
        $table = Security::escapeIdentifier($this->from, $this->connection->getDriver());
        $sql = "UPDATE {$table} SET {$col} = {$col} + ?" . $this->compileWheres();
        $bindings = array_merge([$amount], $this->bindings);
        return $this->executor->update($sql, $bindings);
    }
    
    public function decrement(string $column, int $amount = 1): int {
        return $this->increment($column, -$amount);
    }
    
    public function delete(?int $id = null): int {
        if ($id !== null) {
            $this->where('id', '=', $id);
        }
        $sql = $this->grammar->compileDelete($this->toArray());
        return $this->executor->delete($sql, $this->bindings);
    }
    
    public function truncate(): void {
        $table = Security::escapeIdentifier($this->from, $this->connection->getDriver());
        $this->executor->unprepared("TRUNCATE TABLE {$table}");
    }
    
    public function toSql(): string {
        return $this->grammar->compileSelect($this->toArray());
    }
    
    public function getBindings(): array {
        return $this->bindings;
    }
    
    public function dd(): never {
        dump([
            'sql' => $this->toSql(),
            'bindings' => $this->getBindings()
        ]);
        exit(1);
    }
    
    public function dump(): self {
        dump([
            'sql' => $this->toSql(),
            'bindings' => $this->getBindings()
        ]);
        return $this;
    }
    
    public function when(mixed $value, callable $callback, ?callable $default = null): self {
        if ($value) {
            return $callback($this, $value) ?? $this;
        } elseif ($default) {
            return $default($this, $value) ?? $this;
        }
        return $this;
    }
    
    public function unless(mixed $value, callable $callback, ?callable $default = null): self {
        return $this->when(!$value, $callback, $default);
    }
    
    public function tap(callable $callback): self {
        $callback($this);
        return $this;
    }
    
    private function compileWheres(): string {
        if (empty($this->wheres)) {
            return '';
        }
        $sql = [];
        foreach ($this->wheres as $i => $where) {
            $boolean = $i === 0 ? '' : strtoupper($where['boolean']) . ' ';
            switch ($where['type']) {
                case 'basic':
                    $col = Security::escapeIdentifier($where['column'], $this->connection->getDriver());
                    $sql[] = $boolean . $col . ' ' . $where['operator'] . ' ?';
                    break;
                case 'in':
                    $col = Security::escapeIdentifier($where['column'], $this->connection->getDriver());
                    $placeholders = implode(',', array_fill(0, count($where['values']), '?'));
                    $sql[] = $boolean . $col . ' IN (' . $placeholders . ')';
                    break;
                case 'not_in':
                    $col = Security::escapeIdentifier($where['column'], $this->connection->getDriver());
                    $placeholders = implode(',', array_fill(0, count($where['values']), '?'));
                    $sql[] = $boolean . $col . ' NOT IN (' . $placeholders . ')';
                    break;
                case 'null':
                    $col = Security::escapeIdentifier($where['column'], $this->connection->getDriver());
                    $sql[] = $boolean . $col . ' IS NULL';
                    break;
                case 'not_null':
                    $col = Security::escapeIdentifier($where['column'], $this->connection->getDriver());
                    $sql[] = $boolean . $col . ' IS NOT NULL';
                    break;
                case 'between':
                    $col = Security::escapeIdentifier($where['column'], $this->connection->getDriver());
                    $sql[] = $boolean . $col . ' BETWEEN ? AND ?';
                    break;
                case 'nested':
                    $nested = $where['query']->compileWheres();
                    $sql[] = $boolean . '(' . ltrim($nested, 'WHERE ') . ')';
                    break;
            }
        }
        return ' WHERE ' . implode(' ', $sql);
    }
    
    private function toArray(): array {
        return [
            'from' => $this->from,
            'columns' => $this->columns,
            'wheres' => $this->wheres,
            'orders' => $this->orders,
            'limit' => $this->limit,
            'offset' => $this->offset
        ];
    }
}
