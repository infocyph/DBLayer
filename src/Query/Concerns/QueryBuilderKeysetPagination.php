<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Query\Concerns;

use Generator;
use Infocyph\DBLayer\Exceptions\QueryException;
use Infocyph\DBLayer\Pagination\CursorCodec;
use Infocyph\DBLayer\Pagination\CursorPaginator;
use Infocyph\DBLayer\Query\QueryBuilder;
use Infocyph\DBLayer\Support\SqlFingerprint;

/**
 * Internal mechanics for stable keyset iteration and cursor pagination.
 */
trait QueryBuilderKeysetPagination
{
    /**
     * Paginate with an opaque, query-bound keyset cursor.
     *
     * Existing ORDER BY clauses are retained. The unique column is appended as
     * the final tie-breaker when necessary. Every ordered value must be selected,
     * scalar, and non-null.
     *
     * @param int $perPage Items per page
     * @param string|null $cursor Opaque cursor returned by this paginator
     * @param string $uniqueColumn Unique final tie-breaker (default: "id")
     * @param string|null $direction Tie-breaker direction, or inherited/default ASC
     *
     * @throws QueryException
     */
    public function cursorPaginate(
        int $perPage = 15,
        ?string $cursor = null,
        string $uniqueColumn = 'id',
        ?string $direction = null,
    ): CursorPaginator {
        if ($perPage <= 0) {
            throw QueryException::invalidLimit($perPage);
        }

        $orders = $this->resolveCursorOrders($uniqueColumn, $direction);
        $base = $this->cursorBaseQuery($orders);
        $fingerprint = $this->cursorFingerprint($base);
        $decoded = $cursor === null
            ? ['direction' => 'next', 'values' => []]
            : CursorCodec::decode($cursor, $orders, $fingerprint, $this->cursorSigningKey());

        $query = $base->cloneBuilder();
        $travelDirection = $decoded['direction'];
        if ($decoded['values'] !== []) {
            $this->applyCursorSeek($query, $orders, $decoded['values'], $travelDirection);
        }

        $query->orders = $travelDirection === 'previous'
            ? $this->reverseCursorOrders($orders)
            : $orders;
        $query->limit = $perPage + 1;

        $results = $query->get();
        [$items, $hasMore] = $this->resolvePaginatedItems($results, $perPage);
        if ($travelDirection === 'previous') {
            $items = \array_reverse($items);
        }

        $hasNext = $items !== [] && ($travelDirection === 'previous' || $hasMore);
        $hasPrevious = $items !== [] && ($travelDirection === 'next' ? $cursor !== null : $hasMore);
        $nextCursor = $hasNext
            ? $this->encodeCursorForRow($items[\count($items) - 1], $orders, 'next', $fingerprint)
            : null;
        $previousCursor = $hasPrevious
            ? $this->encodeCursorForRow($items[0], $orders, 'previous', $fingerprint)
            : null;

        return new CursorPaginator(
            $items,
            $perPage,
            $cursor,
            $nextCursor,
            $hasNext,
            $previousCursor,
            $hasPrevious,
        );
    }

    /**
     * @param list<array{column:string,direction:string}> $orders
     * @param list<bool|float|int|string> $values
     */
    private function applyCursorSeek(
        QueryBuilder $query,
        array $orders,
        array $values,
        string $travelDirection,
    ): void {
        $query->where(
            function (QueryBuilder $outer) use ($orders, $values, $travelDirection): void {
                foreach ($orders as $index => $order) {
                    $outer->orWhere(
                        function (QueryBuilder $branch) use (
                            $orders,
                            $values,
                            $travelDirection,
                            $index,
                            $order,
                        ): void {
                            for ($prefix = 0; $prefix < $index; $prefix++) {
                                $column = $this->requireNonEmptyString(
                                    $orders[$prefix]['column'],
                                    'cursorOrder',
                                );
                                $branch->where($column, '=', $values[$prefix]);
                            }

                            $column = $this->requireNonEmptyString($order['column'], 'cursorOrder');
                            $branch->where(
                                $column,
                                $this->cursorComparisonOperator($order['direction'], $travelDirection),
                                $values[$index],
                            );
                        },
                    );
                }
            },
        );
    }

    /** @param list<array{column:string,direction:string}> $orders */
    private function assertCursorResultMappings(array $orders): void
    {
        foreach ($orders as $order) {
            $this->cursorResultKey($order['column']);
        }
    }

    /**
     * @param list<array<string,mixed>> $results
     */
    private function chunkCursorValue(array $results, string $column, mixed $previous): mixed
    {
        $lastRow = $results[\count($results) - 1];
        $key = $this->cursorResultKey($column);

        if (!\array_key_exists($key, $lastRow) || $lastRow[$key] === null) {
            throw QueryException::invalidParameter(
                'column',
                "Keyset column [{$column}] must be selected and non-null.",
            );
        }

        $value = $lastRow[$key];
        if ($value === $previous) {
            throw QueryException::invalidParameter(
                'column',
                "Keyset column [{$column}] did not advance.",
            );
        }

        return $value;
    }

    /**
     * @param list<array{column:string,direction:string}> $orders
     */
    private function cursorBaseQuery(array $orders): QueryBuilder
    {
        if ($this->aggregate !== null || $this->groups !== [] || $this->havings !== [] || $this->unions !== []) {
            throw QueryException::invalidParameter(
                'cursor',
                'Cursor pagination does not support aggregate, grouped, HAVING, or UNION queries.',
            );
        }

        $query = $this->cloneBuilder();
        $query->withoutCache();
        $query->limit = null;
        $query->offset = null;
        $query->orders = $orders;

        return $query;
    }

    private function cursorBindingFingerprint(mixed $binding): string
    {
        return match (true) {
            $binding === null => 'n',
            \is_bool($binding) => 'b:' . (int) $binding,
            \is_int($binding) => 'i:' . $binding,
            \is_float($binding) => 'f:' . \sprintf('%.17g', $binding),
            \is_string($binding) => 's:' . \strlen($binding) . ':' . $binding,
            default => throw QueryException::invalidParameter(
                'cursor',
                'Cursor pagination requires scalar or null query bindings.',
            ),
        };
    }

    private function cursorComparisonOperator(string $orderDirection, string $travelDirection): string
    {
        $ascending = $orderDirection === 'asc';

        if ($travelDirection === 'previous') {
            $ascending = !$ascending;
        }

        return $ascending ? '>' : '<';
    }

    private function cursorFingerprint(QueryBuilder $query): string
    {
        $parts = [SqlFingerprint::normalize($query->toSelectSql())];

        foreach ($query->getBindings() as $binding) {
            $parts[] = $this->cursorBindingFingerprint($binding);
        }

        return \substr(\hash('sha256', \implode("\0", $parts)), 0, 32);
    }

    private function cursorResultKey(string $column): string
    {
        $selected = [];
        $outputCounts = [];

        foreach ($this->columns as $selectedColumn) {
            if (!is_string($selectedColumn) || $selectedColumn === '*') {
                continue;
            }

            [$source, $alias] = $this->cursorSelectedColumn($selectedColumn);
            $segments = explode('.', $source);
            $output = $alias ?? $segments[count($segments) - 1];
            $selected[$source] = $output;
            $selected[$output] ??= $output;
            $outputCounts[$output] = ($outputCounts[$output] ?? 0) + 1;
        }

        if (isset($selected[$column])) {
            $output = $selected[$column];
            if (($outputCounts[$output] ?? 0) > 1) {
                throw QueryException::invalidParameter(
                    'cursor',
                    "Ordered column [{$column}] has an ambiguous result key; select it with addSelectAs().",
                );
            }

            return $output;
        }

        $segments = \explode('.', $column);
        $output = $segments[\count($segments) - 1];

        if (count($segments) > 1 && $this->joins !== []) {
            throw QueryException::invalidParameter(
                'cursor',
                "Qualified ordered column [{$column}] must be selected with addSelectAs() in a joined query.",
            );
        }

        return $output;
    }

    /**
     * @return array{0:string,1:?string}
     */
    private function cursorSelectedColumn(string $column): array
    {
        if (preg_match('/^([^\s]+)\s+(?:as\s+)?([A-Za-z_][A-Za-z0-9_]*)$/iD', trim($column), $matches) === 1) {
            return [$matches[1], $matches[2]];
        }

        return [trim($column), null];
    }

    private function cursorSigningKey(): ?string
    {
        $key = $this->connection->getConfig()->securityConfig()['cursor_signing_key'] ?? null;

        return \is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * @param array<string,mixed> $row
     * @param list<array{column:string,direction:string}> $orders
     */
    private function encodeCursorForRow(
        array $row,
        array $orders,
        string $travelDirection,
        string $fingerprint,
    ): string {
        $values = [];

        foreach ($orders as $order) {
            $key = $this->cursorResultKey($order['column']);

            if (!\array_key_exists($key, $row) || $row[$key] === null || !\is_scalar($row[$key])) {
                throw QueryException::invalidParameter(
                    'cursor',
                    "Ordered column [{$order['column']}] must be selected as a non-null scalar.",
                );
            }

            $values[] = $row[$key];
        }

        /** @var list<bool|float|int|string> $values */
        return CursorCodec::encode(
            $orders,
            $values,
            $travelDirection,
            $fingerprint,
            $this->cursorSigningKey(),
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fetchChunkById(
        int $chunkSize,
        string $column,
        mixed $lastId,
        string $direction,
    ): array {
        $clone = $this->cloneBuilder();
        $clone->withoutCache();
        $this->resetCursorWindow($clone);
        $column = $this->requireNonEmptyString($column, 'column');

        if ($lastId !== null) {
            $clone->where($column, $direction === 'asc' ? '>' : '<', $lastId);
        }

        $clone->orderBy($column, $direction);
        $clone->limit = $chunkSize;

        return $clone->get();
    }

    /**
     * @return Generator<array{0:list<array<string,mixed>>,1:int}>
     */
    private function keysetChunks(
        int $chunkSize,
        string $column,
        mixed $fromId,
        string $direction,
    ): Generator {
        if ($chunkSize <= 0) {
            throw QueryException::invalidLimit($chunkSize);
        }

        $lastId = $fromId;
        $column = $this->requireNonEmptyString($column, 'column');
        $direction = $this->normalizeKeysetDirection($direction);

        for ($page = 1; ; $page++) {
            $rows = $this->fetchChunkById($chunkSize, $column, $lastId, $direction);

            if ($rows === []) {
                return;
            }

            yield [$rows, $page];
            $lastId = $this->chunkCursorValue($rows, $column, $lastId);

            if (\count($rows) < $chunkSize) {
                return;
            }
        }
    }

    private function normalizeKeysetDirection(string $direction): string
    {
        $direction = \strtolower($direction);

        if (!\in_array($direction, ['asc', 'desc'], true)) {
            throw QueryException::invalidOrderDirection($direction);
        }

        return $direction;
    }

    /**
     * @return list<array{column:string,direction:string}>
     */
    private function resolveCursorOrders(string $uniqueColumn, ?string $direction): array
    {
        $uniqueColumn = $this->requireNonEmptyString($uniqueColumn, 'uniqueColumn');
        $this->validateColumnIdentifier($uniqueColumn, false);
        $direction = $direction === null ? null : $this->normalizeKeysetDirection($direction);
        $orders = $this->orders;

        if ($orders === []) {
            $orders = [[
                'column' => $uniqueColumn,
                'direction' => $direction ?? 'asc',
            ]];

            $this->assertCursorResultMappings($orders);

            return $orders;
        }

        $columns = \array_column($orders, 'column');
        if (\count($columns) !== \count(\array_unique($columns))) {
            throw QueryException::invalidParameter(
                'cursor',
                'Cursor pagination does not allow duplicate order columns.',
            );
        }

        $position = \array_search($uniqueColumn, $columns, true);
        if ($position === false) {
            $orders[] = [
                'column' => $uniqueColumn,
                'direction' => $direction ?? $orders[\count($orders) - 1]['direction'],
            ];

            $this->assertCursorResultMappings($orders);

            return $orders;
        }

        if ($position !== \count($orders) - 1) {
            throw QueryException::invalidParameter(
                'uniqueColumn',
                'The unique tie-breaker must be the final order column.',
            );
        }

        if ($direction !== null && $orders[$position]['direction'] !== $direction) {
            throw QueryException::invalidParameter(
                'direction',
                'The direction conflicts with the existing unique tie-breaker order.',
            );
        }

        $this->assertCursorResultMappings($orders);

        return $orders;
    }

    /**
     * @param list<array{column:string,direction:string}> $orders
     * @return list<array{column:string,direction:string}>
     */
    private function reverseCursorOrders(array $orders): array
    {
        return \array_map(
            static fn(array $order): array => [
                'column' => $order['column'],
                'direction' => $order['direction'] === 'asc' ? 'desc' : 'asc',
            ],
            $orders,
        );
    }
}
