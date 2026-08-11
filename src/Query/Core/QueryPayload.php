<?php

// src/Query/Core/QueryPayload.php

declare(strict_types=1);

namespace Infocyph\DBLayer\Query\Core;

use Infocyph\DBLayer\Query\Expression;
use Infocyph\DBLayer\Query\JoinClause;
use Infocyph\DBLayer\Support\ArrayNormalizer;
use LogicException;
use Stringable;

/**
 * Immutable query payload (AST-ish).
 *
 * Everything the driver/compiler needs to turn a structured query into SQL.
 */
final readonly class QueryPayload
{
    /** @var array{function:string,column:string}|null */
    public ?array $aggregate;

    /** @var list<mixed> */
    public array $bindings;

    /** @var list<string|Expression|WindowExpression> */
    public array $columns;

    public bool $containsRawFragments;

    /** @var list<array{name:string,query:string|QueryPayload,recursive:bool}> */
    public array $ctes;

    /** @var list<string> */
    public array $groups;

    /** @var list<array<string,mixed>> */
    public array $havings;

    /** @var list<array<string,mixed>> */
    public array $insertRows;

    /** @var list<array<string,mixed>|object> */
    public array $joins;

    /** @var list<array{column:string,direction:string}> */
    public array $orders;

    /** @var list<string> */
    public array $returning;

    /** @var list<array{query:QueryPayload,all:bool}> */
    public array $unions;

    /** @var list<string> */
    public array $uniqueBy;

    /** @var array<string,mixed> */
    public array $updateValues;

    /** @var list<string> */
    public array $upsertUpdate;

    /** @var list<array<string,mixed>> */
    public array $wheres;

    /**
     * @param list<string|Expression|WindowExpression> $columns
     * @param list<array<string,mixed>> $wheres
     * @param list<array<string,mixed>|object> $joins JoinClause-like arrays or objects
     * @param list<string> $groups
     * @param list<array<string,mixed>> $havings
     * @param list<array{column:string,direction:string}> $orders
     * @param list<array{query:QueryPayload,all:bool}> $unions
     * @param array{function:string,column:string}|null $aggregate
     * @param list<mixed> $bindings
     * @param list<array<string,mixed>> $insertRows
     * @param array<string,mixed> $updateValues
     * @param bool $containsRawFragments Whether validated developer-owned SQL fragments are present
     * @param list<array{name:string,query:string|QueryPayload,recursive:bool}> $ctes
     * @param list<string> $uniqueBy
     * @param list<string> $upsertUpdate
     * @param list<string> $returning
     */
    public function __construct(
        public QueryType $type,
        public ?string $table,
        array $columns,
        array $wheres,
        array $joins,
        array $groups,
        array $havings,
        array $orders,
        public ?int $limit,
        public ?int $offset,
        array $unions,
        public ?string $lock,
        ?array $aggregate,
        array $bindings,
        array $insertRows = [],
        array $updateValues = [],
        bool $containsRawFragments = false,
        public bool $distinct = false,
        array $ctes = [],
        public ?string $tableAlias = null,
        public ?self $sourceQuery = null,
        public string $insertMode = 'insert',
        array $uniqueBy = [],
        array $upsertUpdate = [],
        array $returning = [],
    ) {
        $this->columns = self::normalizeColumns($columns);
        $this->wheres = self::normalizeAssocRows($wheres);
        $this->joins = self::normalizeJoins($joins);
        $this->groups = self::normalizeStringList($groups);
        $this->havings = self::normalizeAssocRows($havings);
        $this->orders = self::normalizeOrders($orders);
        $this->unions = self::normalizeUnions($unions);
        $this->aggregate = self::normalizeAggregate($aggregate);
        $this->bindings = self::normalizeMixedList($bindings);
        $this->insertRows = self::normalizeInsertRows($insertRows);
        $this->updateValues = self::normalizeStringKeyMap($updateValues);
        $this->ctes = self::normalizeCtes($ctes);
        $this->uniqueBy = self::normalizeStringList($uniqueBy);
        $this->upsertUpdate = self::normalizeStringList($upsertUpdate);
        $this->returning = self::normalizeStringList($returning);

        if ($tableAlias !== null && trim($tableAlias) === '') {
            throw new LogicException('QueryPayload tableAlias must be null or non-empty.');
        }

        if ($sourceQuery !== null && $tableAlias === null) {
            throw new LogicException('QueryPayload structured source requires tableAlias.');
        }

        if (!in_array($insertMode, ['insert', 'ignore', 'upsert'], true)) {
            throw new LogicException("Unsupported QueryPayload insert mode [{$insertMode}].");
        }

        $this->containsRawFragments = $containsRawFragments || QueryRawProvenance::contains(
            $this->columns,
            $this->wheres,
            $this->joins,
            $this->havings,
            $this->unions,
            $this->ctes,
            $sourceQuery,
        );
    }

    /**
     * Lightweight clone with overridden pieces.
     *
     * @param array{
     *   type?:QueryType,
     *   table?:?string,
     *   columns?:list<string|Expression|WindowExpression>,
     *   wheres?:list<array<string,mixed>>,
     *   joins?:list<array<string,mixed>|object>,
     *   groups?:list<string>,
     *   havings?:list<array<string,mixed>>,
     *   orders?:list<array{column:string,direction:string}>,
     *   limit?:?int,
     *   offset?:?int,
     *   unions?:list<array{query:QueryPayload,all:bool}>,
     *   lock?:?string,
     *   aggregate?:array{function:string,column:string}|null,
     *   bindings?:list<mixed>,
     *   insertRows?:list<array<string,mixed>>,
     *   updateValues?:array<string,mixed>,
     *   containsRawFragments?:bool,
     *   distinct?:bool,
     *   ctes?:list<array{name:string,query:string|QueryPayload,recursive:bool}>,
     *   tableAlias?:?string,
     *   sourceQuery?:?QueryPayload,
     *   insertMode?:mixed,
     *   uniqueBy?:list<string>,
     *   upsertUpdate?:list<string>,
     *   returning?:list<string>
     * } $overrides
     */
    public function with(array $overrides): self
    {
        return new self(
            $overrides['type'] ?? $this->type,
            array_key_exists('table', $overrides) ? $overrides['table'] : $this->table,
            self::normalizeColumns($overrides['columns'] ?? $this->columns),
            self::normalizeAssocRows($overrides['wheres'] ?? $this->wheres),
            self::normalizeJoins($overrides['joins'] ?? $this->joins),
            self::normalizeStringList($overrides['groups'] ?? $this->groups),
            self::normalizeAssocRows($overrides['havings'] ?? $this->havings),
            self::normalizeOrders($overrides['orders'] ?? $this->orders),
            array_key_exists('limit', $overrides) ? $overrides['limit'] : $this->limit,
            array_key_exists('offset', $overrides) ? $overrides['offset'] : $this->offset,
            self::normalizeUnions($overrides['unions'] ?? $this->unions),
            array_key_exists('lock', $overrides) ? $overrides['lock'] : $this->lock,
            self::normalizeAggregate(array_key_exists('aggregate', $overrides) ? $overrides['aggregate'] : $this->aggregate),
            self::normalizeMixedList($overrides['bindings'] ?? $this->bindings),
            self::normalizeInsertRows($overrides['insertRows'] ?? $this->insertRows),
            self::normalizeStringKeyMap($overrides['updateValues'] ?? $this->updateValues),
            (bool) ($overrides['containsRawFragments'] ?? $this->containsRawFragments),
            (bool) ($overrides['distinct'] ?? $this->distinct),
            self::normalizeCtes($overrides['ctes'] ?? $this->ctes),
            array_key_exists('tableAlias', $overrides) ? $overrides['tableAlias'] : $this->tableAlias,
            array_key_exists('sourceQuery', $overrides) ? $overrides['sourceQuery'] : $this->sourceQuery,
            self::normalizeInsertMode($overrides['insertMode'] ?? $this->insertMode),
            self::normalizeStringList($overrides['uniqueBy'] ?? $this->uniqueBy),
            self::normalizeStringList($overrides['upsertUpdate'] ?? $this->upsertUpdate),
            self::normalizeStringList($overrides['returning'] ?? $this->returning),
        );
    }

    /**
     * @param array<int|string,mixed> $values
     */
    private static function assertList(array $values, string $component): void
    {
        if (!array_is_list($values)) {
            throw new LogicException("QueryPayload {$component} must be a list.");
        }
    }

    /**
     * @return array{function:string,column:string}|null
     */
    private static function normalizeAggregate(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (!is_array($value)) {
            throw new LogicException('QueryPayload aggregate must be an array or null.');
        }

        $function = $value['function'] ?? null;
        $column = $value['column'] ?? null;

        if (!is_string($function) || !is_string($column)) {
            throw new LogicException('QueryPayload aggregate must contain string function and column values.');
        }

        return [
            'function' => $function,
            'column' => $column,
        ];
    }

    /**
     * @param array<int|string,mixed> $values
     * @return list<array<string,mixed>>
     */
    private static function normalizeAssocRows(array $values): array
    {
        self::assertList($values, 'row components');
        $normalized = [];

        foreach ($values as $value) {
            if (!is_array($value)) {
                throw new LogicException('QueryPayload row components must be associative arrays.');
            }

            $normalized[] = self::normalizeStringKeyMap($value);
        }

        return $normalized;
    }

    /**
     * @param array<int|string,mixed> $values
     * @return list<string|Expression|WindowExpression>
     */
    private static function normalizeColumns(array $values): array
    {
        self::assertList($values, 'columns');
        $normalized = [];

        foreach ($values as $value) {
            if (!is_string($value) && !$value instanceof Expression && !$value instanceof WindowExpression) {
                throw new LogicException('QueryPayload columns must be strings, Expression, or WindowExpression objects.');
            }
            if (is_string($value) && trim($value) === '') {
                throw new LogicException('QueryPayload columns must not be empty.');
            }

            $normalized[] = $value;
        }

        return $normalized;
    }

    /**
     * @param array<int|string,mixed> $values
     * @return list<array{name:string,query:string|QueryPayload,recursive:bool}>
     */
    private static function normalizeCtes(array $values): array
    {
        self::assertList($values, 'CTEs');
        $normalized = [];

        foreach ($values as $value) {
            if (!is_array($value)) {
                throw new LogicException('QueryPayload CTEs must be arrays.');
            }

            $name = $value['name'] ?? null;
            $query = $value['query'] ?? null;
            if (!is_string($name) || (!is_string($query) && !$query instanceof self)) {
                throw new LogicException('QueryPayload CTEs require a name and structured or raw query.');
            }
            if (trim($name) === '' || (is_string($query) && trim($query) === '')) {
                throw new LogicException('QueryPayload CTE names and raw queries must not be empty.');
            }
            $recursive = $value['recursive'] ?? false;
            if (!is_bool($recursive)) {
                throw new LogicException('QueryPayload CTE recursive flag must be boolean.');
            }

            $normalized[] = [
                'name' => $name,
                'query' => $query,
                'recursive' => $recursive,
            ];
        }

        return $normalized;
    }

    private static function normalizeInsertMode(mixed $value): string
    {
        if (!is_string($value)) {
            throw new LogicException('QueryPayload insertMode must be a string.');
        }

        return $value;
    }

    /**
     * @param array<int|string,mixed> $values
     * @return list<array<string,mixed>>
     */
    private static function normalizeInsertRows(array $values): array
    {
        self::assertList($values, 'insert rows');
        $normalized = [];

        foreach ($values as $value) {
            if (!is_array($value)) {
                throw new LogicException('QueryPayload insert rows must be associative arrays.');
            }

            $normalized[] = self::normalizeStringKeyMap($value);
        }

        return $normalized;
    }

    /**
     * @param array<int|string,mixed> $values
     * @return list<array<string,mixed>|object>
     */
    private static function normalizeJoins(array $values): array
    {
        self::assertList($values, 'joins');
        $normalized = [];

        foreach ($values as $value) {
            if ($value instanceof JoinClause || $value instanceof Stringable) {
                $normalized[] = $value;

                continue;
            }

            if (!is_array($value)) {
                throw new LogicException('QueryPayload joins must be arrays, JoinClause, or Stringable objects.');
            }

            $normalized[] = self::normalizeStringKeyMap($value);
        }

        return $normalized;
    }

    /**
     * @param array<int|string,mixed> $values
     * @return list<mixed>
     */
    private static function normalizeMixedList(array $values): array
    {
        self::assertList($values, 'bindings');
        $normalized = [];

        foreach ($values as $value) {
            $normalized[] = $value;
        }

        return $normalized;
    }

    /**
     * @param array<int|string,mixed> $values
     * @return list<array{column:string,direction:string}>
     */
    private static function normalizeOrders(array $values): array
    {
        self::assertList($values, 'orders');
        $normalized = [];

        foreach ($values as $value) {
            if (!is_array($value)) {
                throw new LogicException('QueryPayload orders must be arrays.');
            }

            $column = $value['column'] ?? null;
            $direction = $value['direction'] ?? null;

            if (!is_string($column) || !is_string($direction)) {
                throw new LogicException('QueryPayload orders require string column and direction values.');
            }
            if (trim($column) === '' || !in_array(strtolower($direction), ['asc', 'desc'], true)) {
                throw new LogicException('QueryPayload orders require a non-empty column and ASC/DESC direction.');
            }

            $normalized[] = [
                'column' => $column,
                'direction' => $direction,
            ];
        }

        return $normalized;
    }

    /**
     * @param array<int|string,mixed> $values
     * @return array<string,mixed>
     */
    private static function normalizeStringKeyMap(array $values): array
    {
        $normalized = ArrayNormalizer::stringKeyArray($values);
        if ($normalized !== $values) {
            throw new LogicException('QueryPayload associative components must use string keys.');
        }

        return $normalized;
    }

    /**
     * @param array<int|string,mixed> $values
     * @return list<string>
     */
    private static function normalizeStringList(array $values): array
    {
        self::assertList($values, 'string-list components');
        $normalized = [];

        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new LogicException('QueryPayload string-list components must contain only strings.');
            }

            if (trim($value) === '') {
                throw new LogicException('QueryPayload string-list components must not be empty.');
            }

            $normalized[] = $value;
        }

        return $normalized;
    }

    /**
     * @param array<int|string,mixed> $values
     * @return list<array{query:QueryPayload,all:bool}>
     */
    private static function normalizeUnions(array $values): array
    {
        self::assertList($values, 'unions');
        $normalized = [];

        foreach ($values as $value) {
            if (!is_array($value)) {
                throw new LogicException('QueryPayload unions must be arrays.');
            }

            $query = $value['query'] ?? null;
            $all = $value['all'] ?? false;

            if (!$query instanceof self || !is_bool($all)) {
                throw new LogicException('QueryPayload unions require a QueryPayload and boolean all flag.');
            }

            $normalized[] = [
                'query' => $query,
                'all' => $all,
            ];
        }

        return $normalized;
    }
}
