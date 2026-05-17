<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Grammar\Concerns;

use Infocyph\DBLayer\Query\Expression;
use Infocyph\DBLayer\Query\JoinClause;
use Infocyph\DBLayer\Query\QueryBuilder;
use InvalidArgumentException;

trait GrammarComponentNormalization
{
    private function compileColumnsComponent(QueryBuilder $query, mixed $value): string
    {
        $columns = $this->normalizeColumns($value);

        return $columns !== [] ? $this->compileColumns($query, $columns) : '';
    }

    private function compileComponent(QueryBuilder $query, string $component, mixed $value): string
    {
        return match ($component) {
            'aggregate' => is_array($value) ? $this->compileAggregate($query) : '',
            'columns' => $this->compileColumnsComponent($query, $value),
            'from' => is_string($value) && $value !== '' ? $this->compileFrom($query, $value) : '',
            'joins' => $this->compileJoinsComponent($query, $value),
            'wheres' => $this->compileWheresComponent($query, $value),
            'groups' => $this->compileGroupsComponent($query, $value),
            'havings' => $this->compileHavingsComponent($query, $value),
            'orders' => $this->compileOrdersComponent($query, $value),
            'limit' => is_int($value) ? $this->compileLimit($query, $value) : '',
            'offset' => is_int($value) ? $this->compileOffset($query, $value) : '',
            'lock' => is_string($value) && $value !== '' ? $this->compileLock($query, $value) : '',
            default => '',
        };
    }

    private function compileGroupsComponent(QueryBuilder $query, mixed $value): string
    {
        $groups = $this->normalizeColumns($value);

        return $groups !== [] ? $this->compileGroups($query, $groups) : '';
    }

    private function compileHavingsComponent(QueryBuilder $query, mixed $value): string
    {
        $havings = $this->normalizeHavings($value);

        return $havings !== [] ? $this->compileHavings($query, $havings) : '';
    }

    private function compileJoinsComponent(QueryBuilder $query, mixed $value): string
    {
        $joins = $this->normalizeJoins($value);

        return $joins !== [] ? $this->compileJoins($query, $joins) : '';
    }

    private function compileOrdersComponent(QueryBuilder $query, mixed $value): string
    {
        $orders = $this->normalizeOrders($value);

        return $orders !== [] ? $this->compileOrders($query, $orders) : '';
    }

    private function compileWheresComponent(QueryBuilder $query, mixed $value): string
    {
        $wheres = $this->normalizeWheres($value);

        return $wheres !== [] ? $this->compileWheres($query, $wheres) : '';
    }

    /**
     * @template T of array<string,mixed>
     * @param callable(array<string,mixed>,string):T $mapper
     * @return list<T>
     */
    private function normalizeColumnRows(mixed $rows, callable $mapper): array
    {
        if (!is_array($rows)) {
            return [];
        }

        $normalized = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $normalizedRow = $this->normalizeStringKeyArray($row);
            $column = $this->stringValue($normalizedRow['column'] ?? '');
            if ($column === '') {
                continue;
            }

            $normalized[] = $mapper($normalizedRow, $column);
        }

        return $normalized;
    }

    /**
     * @return array<int,string|Expression>
     */
    private function normalizeColumns(mixed $columns): array
    {
        if (!is_array($columns)) {
            return [];
        }

        $normalized = [];

        foreach ($columns as $column) {
            if (is_string($column) || $column instanceof Expression) {
                $normalized[] = $column;
            }
        }

        return $normalized;
    }

    /**
     * @return list<array{name:string,query:string|QueryBuilder,recursive:bool}>
     */
    private function normalizeCtes(mixed $ctes): array
    {
        if (!is_array($ctes)) {
            return [];
        }

        $normalized = [];

        foreach ($ctes as $cte) {
            if (!is_array($cte)) {
                continue;
            }

            $name = $this->stringValue($cte['name'] ?? '');
            $query = $cte['query'] ?? null;
            $recursive = (bool) ($cte['recursive'] ?? false);

            if ($name === '' || (!is_string($query) && !$query instanceof QueryBuilder)) {
                continue;
            }

            $normalized[] = [
                'name' => $name,
                'query' => $query,
                'recursive' => $recursive,
            ];
        }

        return $normalized;
    }

    /**
     * @return array<int,array{column:string,operator:string,value:mixed,boolean:string}>
     */
    private function normalizeHavings(mixed $havings): array
    {
        return $this->normalizeColumnRows(
            $havings,
            fn(array $having, string $column): array => [
                'column' => $column,
                'operator' => $this->stringValue($having['operator'] ?? '=', '='),
                'value' => $having['value'] ?? null,
                'boolean' => $this->stringValue($having['boolean'] ?? 'and', 'and'),
            ],
        );
    }

    /**
     * @return array<int,JoinClause|array<string,mixed>>
     */
    private function normalizeJoins(mixed $joins): array
    {
        if (!is_array($joins)) {
            return [];
        }

        $normalized = [];

        foreach ($joins as $join) {
            if ($join instanceof JoinClause) {
                $normalized[] = $join;

                continue;
            }

            if (is_array($join)) {
                $normalizedJoin = $this->normalizeStringKeyArray($join);
                if ($normalizedJoin !== []) {
                    $normalized[] = $normalizedJoin;
                }
            }
        }

        return $normalized;
    }

    /**
     * @return array<int,array{column:string,direction:string}>
     */
    private function normalizeOrders(mixed $orders): array
    {
        return $this->normalizeColumnRows(
            $orders,
            fn(array $order, string $column): array => [
                'column' => $column,
                'direction' => $this->stringValue($order['direction'] ?? 'asc', 'asc'),
            ],
        );
    }

    /**
     * @param array<mixed> $items
     * @return array<string,mixed>
     */
    private function normalizeStringKeyArray(array $items): array
    {
        $normalized = [];

        foreach ($items as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /**
     * @return list<array{query:QueryBuilder,all:bool}>
     */
    private function normalizeUnions(mixed $unions): array
    {
        if (!is_array($unions)) {
            return [];
        }

        $normalized = [];

        foreach ($unions as $union) {
            if (!is_array($union)) {
                continue;
            }

            $query = $union['query'] ?? null;
            if (!$query instanceof QueryBuilder) {
                continue;
            }

            $normalized[] = [
                'query' => $query,
                'all' => (bool) ($union['all'] ?? false),
            ];
        }

        return $normalized;
    }

    /**
     * @return array<int|string,mixed>
     */
    private function normalizeValues(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }

        return $values;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function normalizeWheres(mixed $wheres): array
    {
        if (!is_array($wheres)) {
            return [];
        }

        $normalized = [];

        foreach ($wheres as $where) {
            if (is_array($where)) {
                $normalizedWhere = [];

                foreach ($where as $key => $value) {
                    if (is_string($key)) {
                        $normalizedWhere[$key] = $value;
                    }
                }

                if ($normalizedWhere !== []) {
                    $normalized[] = $normalizedWhere;
                }
            }
        }

        return $normalized;
    }

    /**
     * @param array{
     *   from:?string,
     *   type:?string,
     *   ctes:list<array{name:string,query:string|QueryBuilder,recursive:bool}>,
     *   columns:list<string|Expression>,
     *   distinct:bool,
     *   joins:list<array<string,mixed>|JoinClause>,
     *   wheres:list<array<string,mixed>>,
     *   groups:list<string>,
     *   havings:list<array<string,mixed>>,
     *   orders:list<array{column:string,direction:string}>,
     *   limit:?int,
     *   offset:?int,
     *   unions:list<array{query:QueryBuilder,all:bool}>,
     *   lock:?string,
     *   aggregate:array{function:string,column:string}|null
     * } $components
     */
    private function requireFromTable(array $components): string
    {
        $from = $components['from'];

        if ($from === null || $from === '') {
            throw new InvalidArgumentException('Cannot compile query without a source table.');
        }

        return $from;
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
}
