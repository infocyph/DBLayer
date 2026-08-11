<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Query\Concerns;

use Infocyph\DBLayer\Exceptions\QueryException;
use Infocyph\DBLayer\Query\Core\QueryPayload;
use Infocyph\DBLayer\Query\Core\WindowExpression;
use Infocyph\DBLayer\Query\Expression;

trait QueryBuilderCompilation
{
    /**
     * Alias the current FROM source after identifier validation.
     */
    public function as(string $alias): self
    {
        if ($this->from === null && $this->fromSubquery === null) {
            throw QueryException::invalidParameter('alias', 'A FROM source must be selected before assigning an alias.');
        }

        $this->validateColumnIdentifier($alias, false);
        $this->fromAlias = $alias;

        return $this;
    }

    /**
     * Add a structured window-function expression to the SELECT list.
     *
     * @param list<string> $partitionBy
     * @param list<string> $orderBy
     */
    public function selectWindow(
        string|Expression $functionExpression,
        string $alias,
        array $partitionBy = [],
        array $orderBy = [],
    ): self {
        $alias = trim($alias);
        $this->validateColumnIdentifier($alias, false);

        if ($functionExpression instanceof Expression) {
            $functionSql = trim($functionExpression->getValue());
            $this->validateRawFragment($functionSql);
        } else {
            $functionSql = strtolower(trim($functionExpression));
            if (!in_array($functionSql, ['row_number()', 'rank()', 'dense_rank()', 'percent_rank()', 'cume_dist()'], true)) {
                throw QueryException::invalidParameter(
                    'functionExpression',
                    'Use a supported parameterless window function or an explicit Expression.',
                );
            }
        }

        foreach ($partitionBy as $column) {
            $this->validateColumnIdentifier($column, false);
        }

        $structuredOrders = [];
        foreach ($orderBy as $order) {
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_.]*)(?:\s+(asc|desc))?$/iD', trim($order), $matches) !== 1) {
                throw QueryException::invalidParameter('orderBy', 'Window ordering requires an identifier and optional ASC/DESC.');
            }
            $this->validateColumnIdentifier($matches[1], false);
            $structuredOrders[] = [
                'column' => $matches[1],
                'direction' => strtolower($matches[2] ?? 'asc'),
            ];
        }

        if ($this->columns === ['*']) {
            $this->columns = [];
        }
        $this->type = 'select';
        $this->columns[] = new WindowExpression(
            $functionExpression instanceof Expression ? $functionExpression : $functionSql,
            $alias,
            $partitionBy,
            $structuredOrders,
        );

        return $this;
    }

    /**
     * Convert the complete builder state into the compiler payload.
     */
    public function toPayload(): QueryPayload
    {
        $wherePayloads = [];
        foreach ($this->wheres as $where) {
            if (($where['query'] ?? null) instanceof self) {
                $where['query'] = $where['query']->toPayload();
            }
            $wherePayloads[] = $where;
        }

        $joinPayloads = [];
        foreach ($this->joins as $join) {
            if (is_array($join) && ($join['query'] ?? null) instanceof self) {
                $join['query'] = $join['query']->toPayload();
            }
            $joinPayloads[] = $join;
        }

        $ctePayloads = [];
        foreach ($this->ctes as $cte) {
            $query = $cte['query'];
            $ctePayloads[] = [
                'name' => $cte['name'],
                'query' => $query instanceof self ? $query->toPayload() : $query,
                'recursive' => $cte['recursive'],
            ];
        }

        $unionPayloads = [];
        foreach ($this->unions as $union) {
            $unionPayloads[] = [
                'query' => $union['query']->toPayload(),
                'all' => $union['all'],
            ];
        }

        return new QueryPayload(
            type: $this->mapTypeToEnum($this->type),
            table: $this->from,
            columns: $this->columns,
            wheres: $wherePayloads,
            joins: $joinPayloads,
            groups: $this->groups,
            havings: $this->havings,
            orders: $this->orders,
            limit: $this->limit,
            offset: $this->offset,
            unions: $unionPayloads,
            lock: $this->lock,
            aggregate: $this->aggregate,
            bindings: $this->getBindings(),
            containsRawFragments: $this->containsRawFragments,
            distinct: $this->distinct,
            ctes: $ctePayloads,
            tableAlias: $this->fromAlias,
            sourceQuery: $this->fromSubquery?->toPayload(),
        );
    }
}
