<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Driver\Concerns;

use Infocyph\DBLayer\Query\Core\QueryPayload;
use LogicException;

trait CompilesWhereClauses
{
    /** @param array<string,mixed> $where */
    protected function compileWhereBasic(array $where): string
    {
        $column = $this->arrayString($where, 'column');
        if ($column === '') {
            return '';
        }

        return sprintf(
            '%s %s ?',
            $this->wrapColumnIdentifier($column),
            $this->arrayString($where, 'operator', '='),
        );
    }

    /** @param array<string,mixed> $where */
    protected function compileWhereBetween(array $where): string
    {
        $column = $this->arrayString($where, 'column');
        $values = $where['values'] ?? null;
        if ($column === '' || !is_array($values) || count($values) < 2) {
            return '';
        }

        return sprintf(
            '%s %sBETWEEN ? AND ?',
            $this->wrapColumnIdentifier($column),
            ($where['not'] ?? false) === true ? 'NOT ' : '',
        );
    }

    /** @param array<string,mixed> $where */
    protected function compileWhereIn(array $where): string
    {
        $column = $this->arrayString($where, 'column');
        $values = $where['values'] ?? null;
        if ($column === '' || !is_array($values)) {
            return '';
        }

        $not = ($where['not'] ?? false) === true;
        if ($values === []) {
            return $not ? '1 = 1' : '0 = 1';
        }

        return sprintf(
            '%s %sIN (%s)',
            $this->wrapColumnIdentifier($column),
            $not ? 'NOT ' : '',
            implode(', ', array_fill(0, count($values), '?')),
        );
    }

    /** @param array<string,mixed> $where */
    protected function compileWhereNull(array $where): string
    {
        $column = $this->arrayString($where, 'column');
        if ($column === '') {
            return '';
        }

        return sprintf(
            '%s IS %sNULL',
            $this->wrapColumnIdentifier($column),
            ($where['not'] ?? false) === true ? 'NOT ' : '',
        );
    }

    /** @param array<string,mixed> $where */
    protected function compileWhereRaw(array $where): string
    {
        return $this->arrayString($where, 'sql');
    }

    protected function compileWheres(QueryPayload $payload): string
    {
        $segments = [];

        foreach ($payload->wheres as $where) {
            $type = $this->arrayString($where, 'type', 'basic');
            $segment = $this->compileWhereComponent($type, $where);
            if ($segment === '') {
                throw new LogicException("WHERE component [{$type}] compiled to an empty expression.");
            }

            $boolean = strtolower($this->arrayString($where, 'boolean', 'and')) === 'or' ? 'OR ' : 'AND ';
            $segments[] = $segments === [] ? $segment : $boolean . $segment;
        }

        return implode(' ', $segments);
    }

    /** @param array<string,mixed> $where */
    private function compileWhereComponent(string $type, array $where): string
    {
        return match ($type) {
            'basic' => $this->compileWhereBasic($where),
            'in' => $this->compileWhereIn($where),
            'between' => $this->compileWhereBetween($where),
            'null' => $this->compileWhereNull($where),
            'raw' => $this->compileWhereRaw($where),
            'exists' => $this->compileWhereExists($where),
            'nested' => $this->compileWhereNested($where),
            default => throw new LogicException("Unsupported WHERE type: {$type}"),
        };
    }

    /** @param array<string,mixed> $where */
    private function compileWhereExists(array $where): string
    {
        $query = $where['query'] ?? null;
        if (!$query instanceof QueryPayload) {
            throw new LogicException('EXISTS requires a structured child query.');
        }

        return (($where['not'] ?? false) === true ? 'NOT ' : '')
            . 'EXISTS (' . $this->compileChildPayload($query) . ')';
    }

    /** @param array<string,mixed> $where */
    private function compileWhereNested(array $where): string
    {
        $query = $where['query'] ?? null;
        if (!$query instanceof QueryPayload) {
            throw new LogicException('Nested WHERE requires a structured child query.');
        }

        return '(' . $this->compileWheres($query) . ')';
    }
}
