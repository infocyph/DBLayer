<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Driver;

use Infocyph\DBLayer\Driver\Concerns\CompilesJoins;
use Infocyph\DBLayer\Driver\Concerns\CompilesWhereClauses;
use Infocyph\DBLayer\Driver\Contracts\QueryCompilerInterface;
use Infocyph\DBLayer\Driver\Support\MutationCompiler;
use Infocyph\DBLayer\Driver\Support\TablePrefixMapper;
use Infocyph\DBLayer\Query\Core\CompiledQuery;
use Infocyph\DBLayer\Query\Core\QueryPayload;
use Infocyph\DBLayer\Query\Core\QueryType;
use Infocyph\DBLayer\Query\Core\SqlOrigin;
use Infocyph\DBLayer\Query\Core\WindowExpression;
use Infocyph\DBLayer\Query\Expression;
use LogicException;

/**
 * Generic AST-based SQL compiler.
 *
 * Supports SELECT and core write operations:
 * - INSERT
 * - UPDATE
 * - DELETE
 * - TRUNCATE
 * Engine-specific subclasses handle identifier quoting.
 */
abstract class AbstractSqlCompiler implements QueryCompilerInterface
{
    use CompilesJoins;
    use CompilesWhereClauses;

    /** @var array<string,true> */
    private array $logicalTables = [];

    private string $tablePrefix = '';

    /** @var array<string,true> */
    private array $virtualTables = [];

    /**
     * Quote an identifier for the current dialect.
     *
     * Implemented by concrete compilers (e.g. MySQL/PostgreSQL/SQLite).
     */
    abstract protected function wrapIdentifier(string $identifier): string;

    #[\Override]
    public function compile(QueryPayload $payload): CompiledQuery
    {
        if ($this->tablePrefix === '') {
            return $this->compilePayload($payload);
        }

        $this->logicalTables = TablePrefixMapper::logicalTables($payload, $this->tablePrefix);
        $this->virtualTables = [];
        foreach ($payload->ctes as $cte) {
            $this->virtualTables[$cte['name']] = true;
            unset($this->logicalTables[$cte['name']]);
        }

        try {
            return $this->compilePayload($payload);
        } finally {
            $this->logicalTables = [];
            $this->virtualTables = [];
        }
    }

    #[\Override]
    public function setTablePrefix(string $prefix): void
    {
        $this->tablePrefix = $prefix;
    }

    protected function compileDelete(QueryPayload $payload): string
    {
        $sql = 'DELETE FROM ' . $this->wrapTableIdentifier($this->requireTable($payload));
        $where = $this->compileWheres($payload);

        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }

        return $sql;
    }

    protected function compileFrom(QueryPayload $payload): string
    {
        if ($payload->sourceQuery !== null) {
            if ($payload->tableAlias === null) {
                throw new LogicException('A derived FROM source requires an alias.');
            }

            return 'FROM (' . $this->compileChildPayload($payload->sourceQuery) . ') AS '
                . $this->wrapIdentifier($payload->tableAlias);
        }

        if ($payload->table === null || $payload->table === '') {
            return '';
        }

        $sql = 'FROM ' . $this->wrapTableIdentifier($payload->table);
        if ($payload->tableAlias !== null) {
            $sql .= ' AS ' . $this->wrapIdentifier($payload->tableAlias);
        }

        return $sql;
    }

    protected function compileGroupBy(QueryPayload $payload): string
    {
        if ($payload->groups === []) {
            return '';
        }

        $columns = [];

        foreach ($payload->groups as $column) {
            $columns[] = $this->wrapColumnIdentifier($column);
        }

        return 'GROUP BY ' . implode(', ', $columns);
    }

    protected function compileHavings(QueryPayload $payload): string
    {
        if ($payload->havings === []) {
            return '';
        }

        $segments = [];
        $first = true;

        foreach ($payload->havings as $having) {
            $column = $this->arrayString($having, 'column');
            $operator = $this->arrayString($having, 'operator', '=');
            $boolean = strtolower($this->arrayString($having, 'boolean', 'and'));
            $boolean = $boolean === 'or' ? 'OR' : 'AND';

            if ($column === '') {
                throw new LogicException('HAVING clauses require a non-empty column.');
            }

            $segment = sprintf(
                '%s %s ?',
                $this->wrapColumnIdentifier($column),
                $operator,
            );

            if (!$first) {
                $segments[] = $boolean . ' ' . $segment;
            } else {
                $segments[] = $segment;
                $first = false;
            }
        }

        return implode(' ', $segments);
    }

    /** @return array{0:string,1:list<mixed>} */
    protected function compileInsert(QueryPayload $payload): array
    {
        [$sql, $bindings] = MutationCompiler::compileInsert(
            TablePrefixMapper::physicalTable($this->requireTable($payload), $this->tablePrefix),
            $payload->insertRows,
            fn(string $identifier): string => $this->wrapIdentifier($identifier),
        );

        $sql = match ($payload->insertMode) {
            'insert' => $sql,
            'ignore' => $this->compileInsertIgnore($sql),
            'upsert' => $this->compileUpsert($sql, $payload->uniqueBy, $payload->upsertUpdate),
            default => throw new LogicException("Unsupported INSERT mode [{$payload->insertMode}]."),
        };

        if ($payload->returning !== []) {
            $sql = $this->compileReturning($sql, $payload->returning);
        }

        return [$sql, $bindings];
    }

    protected function compileInsertIgnore(string $insertSql): string
    {
        throw new LogicException('INSERT IGNORE is not supported by this SQL compiler.');
    }

    protected function compileLimitOffset(QueryPayload $payload): string
    {
        $limit = $payload->limit;
        $offset = $payload->offset;

        if ($limit === null && $offset === null) {
            return '';
        }

        $sql = '';

        if ($limit !== null) {
            $sql .= 'LIMIT ' . $limit;
        }

        if ($offset !== null) {
            if ($sql !== '') {
                $sql .= ' ';
            }

            $sql .= 'OFFSET ' . $offset;
        }

        return $sql;
    }

    protected function compileLock(string $lock): string
    {
        return $lock === 'update' ? 'FOR UPDATE' : 'LOCK IN SHARE MODE';
    }

    protected function compileOrderBy(QueryPayload $payload): string
    {
        if ($payload->orders === []) {
            return '';
        }

        $segments = [];

        foreach ($payload->orders as $order) {
            $column = $order['column'];
            $direction = strtoupper($order['direction']);

            if ($direction !== 'ASC' && $direction !== 'DESC') {
                $direction = 'ASC';
            }

            $segments[] = sprintf(
                '%s %s',
                $this->wrapColumnIdentifier($column),
                $direction,
            );
        }

        return 'ORDER BY ' . implode(', ', $segments);
    }

    /** @param list<string> $returning */
    protected function compileReturning(string $sql, array $returning): string
    {
        throw new LogicException('RETURNING is not supported by this SQL compiler.');
    }

    protected function compileSelect(QueryPayload $payload): string
    {
        $sql = implode(' ', array_filter([
            $this->compileSelectList($payload),
            $this->compileFrom($payload),
            $this->compileJoins($payload),
            $this->prefixClause('WHERE', $this->compileWheres($payload)),
            $this->compileGroupBy($payload),
            $this->prefixClause('HAVING', $this->compileHavings($payload)),
            $this->compileOrderBy($payload),
            $this->compileLimitOffset($payload),
        ]));

        foreach ($payload->unions as $union) {
            $sql .= $union['all'] ? ' UNION ALL ' : ' UNION ';
            $sql .= $this->compileChildPayload($union['query']);
        }

        if ($payload->lock !== null) {
            $lock = $this->compileLock($payload->lock);
            if ($lock !== '') {
                $sql .= ' ' . $lock;
            }
        }

        if ($payload->ctes !== []) {
            $sql = $this->compileCtes($payload) . ' ' . $sql;
        }

        return $sql;
    }

    protected function compileSelectList(QueryPayload $payload): string
    {
        $aggregate = $payload->aggregate;

        if ($aggregate !== null) {
            $function = strtoupper($aggregate['function']);
            $column = $aggregate['column'];
            $columnSql = $column === '*' ? '*' : $this->wrapColumnIdentifier($column);

            return sprintf('SELECT %s(%s) AS aggregate', $function, $columnSql);
        }

        $columns = $payload->columns;

        if ($columns === []) {
            return 'SELECT *';
        }

        $parts = [];

        foreach ($columns as $column) {
            $parts[] = $this->compileSelectColumn($column);
        }

        return ($payload->distinct ? 'SELECT DISTINCT ' : 'SELECT ') . implode(', ', $parts);
    }

    protected function compileTruncate(QueryPayload $payload): string
    {
        return $this->truncateStatementForTable($this->wrapTableIdentifier($this->requireTable($payload)));
    }

    /** @return array{0:string,1:list<mixed>} */
    protected function compileUpdate(QueryPayload $payload): array
    {
        [$sql, $bindings] = MutationCompiler::compileUpdate(
            TablePrefixMapper::physicalTable($this->requireTable($payload), $this->tablePrefix),
            $payload->updateValues,
            $payload->bindings,
            fn(string $identifier): string => $this->wrapIdentifier($identifier),
        );

        $where = $this->compileWheres($payload);

        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }

        return [$sql, $bindings];
    }

    /**
     * @param list<string> $uniqueBy
     * @param list<string> $update
     */
    protected function compileUpsert(string $insertSql, array $uniqueBy, array $update): string
    {
        throw new LogicException('UPSERT is not supported by this SQL compiler.');
    }

    protected function expressionToSql(Expression $expression): string
    {
        return $expression->getValue();
    }

    protected function requireTable(QueryPayload $payload): string
    {
        $table = trim((string) $payload->table);

        if ($table === '') {
            throw new LogicException(
                sprintf('Payload for %s requires a non-empty table name.', $payload->type->value),
            );
        }

        return $table;
    }

    protected function truncateStatementForTable(string $wrappedTable): string
    {
        return 'TRUNCATE TABLE ' . $wrappedTable;
    }

    protected function wrapDelimitedIdentifier(string $identifier, string $quote): string
    {
        $identifier = trim($identifier);

        if ($identifier === '' || $identifier === '*') {
            return $identifier;
        }

        if (str_contains($identifier, '(') || str_contains($identifier, ' ')) {
            return $identifier;
        }

        $parts = explode('.', $identifier);
        $wrapped = array_map(
            static fn(string $part): string => $part === '*' ? '*' : $quote . $part . $quote,
            $parts,
        );

        return implode('.', $wrapped);
    }

    /** @param array<string,mixed> $data */
    private function arrayString(array $data, string $key, string $default = ''): string
    {
        if (!array_key_exists($key, $data)) {
            return $default;
        }

        return $this->stringValue($data[$key], $default);
    }

    private function compileChildPayload(QueryPayload $payload): string
    {
        $logical = $this->logicalTables;
        $virtual = $this->virtualTables;

        try {
            return $this->compile($payload)->sql;
        } finally {
            $this->logicalTables = $logical;
            $this->virtualTables = $virtual;
        }
    }

    protected function compileCtes(QueryPayload $payload): string
    {
        $recursive = false;
        $parts = [];

        foreach ($payload->ctes as $cte) {
            $recursive = $recursive || $cte['recursive'];
            $query = $cte['query'];
            $sql = $query instanceof QueryPayload
                ? $this->compileChildPayload($query)
                : $query;
            $parts[] = $this->wrapIdentifier($cte['name']) . ' AS (' . $sql . ')';
        }

        return $this->compileCtePrefix($recursive) . ' ' . implode(', ', $parts);
    }

    protected function compileCtePrefix(bool $recursive): string
    {
        return $recursive ? 'WITH RECURSIVE' : 'WITH';
    }

    private function compilePayload(QueryPayload $payload): CompiledQuery
    {
        $type = $payload->type;
        [$sql, $bindings] = match ($type) {
            QueryType::SELECT => [$this->compileSelect($payload), $payload->bindings],
            QueryType::INSERT => $this->compileInsert($payload),
            QueryType::UPDATE => $this->compileUpdate($payload),
            QueryType::DELETE => [$this->compileDelete($payload), $payload->bindings],
            QueryType::TRUNCATE => [$this->compileTruncate($payload), []],
        };

        return new CompiledQuery(
            $sql,
            $bindings,
            $payload->type,
            SqlOrigin::BUILDER,
            $payload->containsRawFragments,
        );
    }

    private function compileSelectColumn(string|Expression|WindowExpression $column): string
    {
        if ($column instanceof WindowExpression) {
            return $this->compileWindowExpression($column);
        }
        if ($column instanceof Expression) {
            return $this->expressionToSql($column);
        }

        return $column === '*' || str_contains($column, '(')
            ? $column
            : $this->wrapColumnIdentifier($column);
    }

    private function compileWindowExpression(WindowExpression $window): string
    {
        $function = $window->function instanceof Expression
            ? $this->expressionToSql($window->function)
            : $window->function;
        $clauses = [];

        if ($window->partitionBy !== []) {
            $partition = [];
            foreach ($window->partitionBy as $column) {
                $partition[] = $this->wrapColumnIdentifier($column);
            }
            $clauses[] = 'PARTITION BY ' . implode(', ', $partition);
        }

        if ($window->orderBy !== []) {
            $orders = [];
            foreach ($window->orderBy as $order) {
                $orders[] = sprintf(
                    '%s %s',
                    $this->wrapColumnIdentifier($order['column']),
                    strtoupper($order['direction']),
                );
            }
            $clauses[] = 'ORDER BY ' . implode(', ', $orders);
        }

        return sprintf(
            '%s OVER (%s) AS %s',
            $function,
            implode(' ', $clauses),
            $this->wrapIdentifier($window->alias),
        );
    }

    private function prefixClause(string $keyword, string $clause): string
    {
        return $clause === '' ? '' : $keyword . ' ' . $clause;
    }

    private function stringValue(mixed $value, string $default = ''): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        return $default;
    }

    private function wrapColumnIdentifier(string $identifier): string
    {
        $identifier = trim($identifier);
        if ($identifier === '' || $identifier === '*' || str_contains($identifier, '(')) {
            return $identifier;
        }

        if (preg_match('/^([^\s]+)\s+(?:as\s+)?([A-Za-z_][A-Za-z0-9_]*)$/iD', $identifier, $matches) === 1) {
            return sprintf(
                '%s AS %s',
                $this->wrapColumnIdentifier($matches[1]),
                $this->wrapIdentifier($matches[2]),
            );
        }

        if ($this->tablePrefix === '') {
            return $this->wrapIdentifier($identifier);
        }

        $parts = explode('.', $identifier);
        if (count($parts) > 1 && isset($this->logicalTables[$parts[0]])) {
            $parts[0] = TablePrefixMapper::physicalTable($parts[0], $this->tablePrefix);
        }

        return $this->wrapIdentifier(implode('.', $parts));
    }

    private function wrapTableIdentifier(string $table): string
    {
        if (isset($this->virtualTables[$table])) {
            return $this->wrapIdentifier($table);
        }

        if ($this->tablePrefix === '') {
            return $this->wrapIdentifier($table);
        }

        $table = trim($table);
        if ($table === '' || str_contains($table, '(')) {
            return $table;
        }

        if (preg_match('/^([^\s]+)\s+(?:as\s+)?([A-Za-z_][A-Za-z0-9_]*)$/iD', $table, $matches) === 1) {
            return sprintf(
                '%s AS %s',
                $this->wrapIdentifier(TablePrefixMapper::physicalTable($matches[1], $this->tablePrefix)),
                $this->wrapIdentifier($matches[2]),
            );
        }

        return $this->wrapIdentifier(TablePrefixMapper::physicalTable($table, $this->tablePrefix));
    }
}
