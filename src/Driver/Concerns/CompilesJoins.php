<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Driver\Concerns;

use Infocyph\DBLayer\Query\Core\QueryPayload;
use Infocyph\DBLayer\Query\JoinClause;
use LogicException;
use Stringable;

trait CompilesJoins
{
    protected function compileJoins(QueryPayload $payload): string
    {
        $joins = [];
        foreach ($payload->joins as $join) {
            $joins[] = $this->compileJoinRepresentation($join);
        }

        return implode(' ', $joins);
    }

    private function compileJoinClause(JoinClause $join): string
    {
        $sql = strtoupper($join->getType()) . ' JOIN ' . $this->wrapTableIdentifier($join->getTable());
        if ($join->getAlias() !== null) {
            $sql .= ' AS ' . $this->wrapIdentifier($join->getAlias());
        }
        $conditions = $join->getConditions();
        if ($conditions === []) {
            return $sql;
        }

        $segments = [];
        foreach ($conditions as $index => $condition) {
            $boolean = $index === 0 ? '' : strtoupper($this->arrayString($condition, 'boolean', 'and')) . ' ';
            $segments[] = $boolean . $this->compileJoinCondition($condition);
        }

        return $sql . ' ON ' . implode(' ', $segments);
    }

    /** @param array<string,mixed> $condition */
    private function compileJoinCondition(array $condition): string
    {
        $type = $this->arrayString($condition, 'type');

        return match ($type) {
            'basic' => sprintf(
                '%s %s %s',
                $this->wrapColumnIdentifier($this->arrayString($condition, 'first')),
                $this->arrayString($condition, 'operator', '='),
                $this->wrapColumnIdentifier($this->arrayString($condition, 'second')),
            ),
            'where' => sprintf(
                '%s %s ?',
                $this->wrapColumnIdentifier($this->arrayString($condition, 'column')),
                $this->arrayString($condition, 'operator', '='),
            ),
            'whereIn' => ($condition['values'] ?? []) === []
                ? '0 = 1'
                : sprintf(
                    '%s IN (%s)',
                    $this->wrapColumnIdentifier($this->arrayString($condition, 'column')),
                    implode(', ', array_fill(0, count((array) $condition['values']), '?')),
                ),
            'whereNull' => $this->wrapColumnIdentifier($this->arrayString($condition, 'column')) . ' IS NULL',
            'whereNotNull' => $this->wrapColumnIdentifier($this->arrayString($condition, 'column')) . ' IS NOT NULL',
            default => throw new LogicException("Unsupported JOIN condition type: {$type}"),
        };
    }

    /** @param array<string,mixed>|object $join */
    private function compileJoinRepresentation(array|object $join): string
    {
        return match (true) {
            $join instanceof JoinClause => $this->compileJoinClause($join),
            is_array($join) => $this->compileStructuredJoin($join),
            $join instanceof Stringable => $join->__toString(),
            default => throw new LogicException('Unsupported JOIN representation in payload.'),
        };
    }

    /** @param array<string,mixed> $join */
    private function compileStructuredJoin(array $join): string
    {
        $table = $this->arrayString($join, 'table');
        $query = $join['query'] ?? null;
        if ($table === '' && !$query instanceof QueryPayload) {
            throw new LogicException('JOIN payload requires a table or structured child query.');
        }

        $type = match (strtolower($this->arrayString($join, 'type', 'inner'))) {
            'left' => 'LEFT',
            'right' => 'RIGHT',
            'cross' => 'CROSS',
            default => 'INNER',
        };
        $tableSql = $query instanceof QueryPayload
            ? '(' . $this->compileChildPayload($query) . ')'
            : (($join['subquery'] ?? false) === true ? $table : $this->wrapTableIdentifier($table));
        $alias = $this->arrayString($join, 'alias');
        $tableSql .= $alias === '' ? '' : ' AS ' . $this->wrapIdentifier($alias);

        return sprintf('%s JOIN %s%s', $type, $tableSql, $this->compileStructuredJoinCondition($join));
    }

    /** @param array<string,mixed> $join */
    private function compileStructuredJoinCondition(array $join): string
    {
        if (!isset($join['first'], $join['operator'], $join['second'])) {
            return '';
        }

        return sprintf(
            ' ON %s %s %s',
            $this->wrapColumnIdentifier($this->stringValue($join['first'])),
            $this->stringValue($join['operator']),
            $this->wrapColumnIdentifier($this->stringValue($join['second'])),
        );
    }
}
