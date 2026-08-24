<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Repository\Concerns;

use Infocyph\DBLayer\Query\QueryBuilder;
use Infocyph\DBLayer\Repository\RepositoryRelationAggregator;
use InvalidArgumentException;

trait RepositoryQueryAggregates
{
    /** @param null|callable(QueryBuilder):void $constraint */
    public function withAggregate(
        string $relation,
        string $column,
        string $function,
        ?string $alias = null,
        ?callable $constraint = null,
    ): self {
        $function = strtolower(trim($function));
        if (!in_array($function, ['avg', 'count', 'max', 'min', 'sum'], true)) {
            throw new InvalidArgumentException(sprintf('Unsupported relation aggregate [%s].', $function));
        }

        $this->registerAggregate(
            $relation,
            $function,
            $function === 'count' ? '*' : $column,
            $alias,
            $constraint,
            false,
        );

        return $this;
    }

    /** @param null|callable(QueryBuilder):void $constraint */
    public function withAvg(string $relation, string $column, ?string $alias = null, ?callable $constraint = null): self
    {
        return $this->withAggregate($relation, $column, 'avg', $alias, $constraint);
    }

    /** @param string|array<int|string,string|callable(QueryBuilder):void> ...$relations */
    public function withCount(string|array ...$relations): self
    {
        $this->registerAggregateList('count', '*', false, $relations);

        return $this;
    }

    /** @param string|array<int|string,string|callable(QueryBuilder):void> ...$relations */
    public function withExists(string|array ...$relations): self
    {
        $this->registerAggregateList('count', '*', true, $relations);

        return $this;
    }

    /** @param null|callable(QueryBuilder):void $constraint */
    public function withMax(string $relation, string $column, ?string $alias = null, ?callable $constraint = null): self
    {
        return $this->withAggregate($relation, $column, 'max', $alias, $constraint);
    }

    /** @param null|callable(QueryBuilder):void $constraint */
    public function withMin(string $relation, string $column, ?string $alias = null, ?callable $constraint = null): self
    {
        return $this->withAggregate($relation, $column, 'min', $alias, $constraint);
    }

    /** @param null|callable(QueryBuilder):void $constraint */
    public function withSum(string $relation, string $column, ?string $alias = null, ?callable $constraint = null): self
    {
        return $this->withAggregate($relation, $column, 'sum', $alias, $constraint);
    }

    private function aggregateAlias(string $relation, string $function, string $column, bool $exists): string
    {
        if ($exists) {
            return $relation . '_exists';
        }
        if ($function === 'count') {
            return $relation . '_count';
        }

        $column = preg_replace('/[^A-Za-z0-9_]+/', '_', $column) ?? $column;

        return sprintf('%s_%s_%s', $relation, $function, trim($column, '_'));
    }

    private function aggregateProjectionValue(bool $exists, string $function, mixed $value): mixed
    {
        if ($exists) {
            return $this->numericInt($value) > 0;
        }

        return $function === 'count' ? $this->numericInt($value) : $value;
    }

    private function assertAggregateAlias(string $alias): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $alias) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid relation projection alias [%s].', $alias));
        }
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function loadAggregates(array $rows): array
    {
        if ($rows === [] || $this->requestedAggregates === []) {
            return $rows;
        }

        $aggregator = new RepositoryRelationAggregator($this->connection);

        foreach ($this->requestedAggregates as $alias => $request) {
            $definition = $this->directRelationDefinition($request['relation']);
            $values = $aggregator->aggregate(
                $rows,
                $definition,
                $request['function'],
                $request['column'],
                $request['constraint'],
            );

            foreach ($rows as &$row) {
                $value = $values[$aggregator->parentIdentity($row, $definition)] ?? null;
                $row[$alias] = $this->aggregateProjectionValue($request['exists'], $request['function'], $value);
            }
            unset($row);
        }

        return $rows;
    }

    private function numericInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /** @return array{0:string,1:?string} */
    private function parseAggregateAlias(string $expression): array
    {
        $parts = preg_split('/\s+as\s+/i', trim($expression), 2);
        $relation = trim((string) ($parts[0] ?? ''));
        $alias = isset($parts[1]) ? trim((string) $parts[1]) : null;

        if ($relation === '' || $alias === '') {
            throw new InvalidArgumentException('Relation name and optional alias must be non-empty.');
        }

        return [$relation, $alias];
    }

    /** @param null|callable(QueryBuilder):void $constraint */
    private function registerAggregate(
        string $expression,
        string $function,
        string $column,
        ?string $alias,
        ?callable $constraint,
        bool $exists,
    ): void {
        [$relation, $inlineAlias] = $this->parseAggregateAlias($expression);
        $this->directRelationDefinition($relation);
        $alias ??= $inlineAlias ?? $this->aggregateAlias($relation, $function, $column, $exists);
        $this->assertAggregateAlias($alias);

        $this->requestedAggregates[$alias] = [
            'relation' => $relation,
            'function' => $function,
            'column' => $column,
            'constraint' => $constraint,
            'exists' => $exists,
        ];
    }

    /**
     * @param array<int|string,string|array<int|string,string|callable(QueryBuilder):void>> $relations
     */
    private function registerAggregateList(
        string $function,
        string $column,
        bool $exists,
        array $relations,
    ): void {
        foreach ($relations as $relation) {
            if (is_string($relation)) {
                $this->registerAggregate($relation, $function, $column, null, null, $exists);

                continue;
            }

            foreach ($relation as $expression => $constraint) {
                if (is_int($expression)) {
                    if (!is_string($constraint)) {
                        throw new InvalidArgumentException('Numeric relation aggregate entries must contain relation names.');
                    }
                    $this->registerAggregate($constraint, $function, $column, null, null, $exists);

                    continue;
                }

                if (!is_callable($constraint)) {
                    throw new InvalidArgumentException(sprintf(
                        'Relation aggregate constraint for [%s] must be callable.',
                        $expression,
                    ));
                }
                $this->registerAggregate($expression, $function, $column, null, $constraint, $exists);
            }
        }
    }
}
