<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Query\Core;

use Infocyph\DBLayer\Query\Expression;
use LogicException;

/**
 * Structured SELECT-list window expression compiled by the active driver.
 */
final readonly class WindowExpression
{
    /** @var list<array{column:string,direction:string}> */
    public array $orderBy;

    /** @var list<string> */
    public array $partitionBy;

    /**
     * @param array<int,mixed> $partitionBy
     * @param array<int,mixed> $orderBy
     */
    public function __construct(
        public string|Expression $function,
        public string $alias,
        array $partitionBy,
        array $orderBy,
    ) {
        $functionSql = $function instanceof Expression ? $function->getValue() : $function;
        if (trim($functionSql) === '') {
            throw new LogicException('Window function must not be empty.');
        }
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $alias) !== 1) {
            throw new LogicException('Window alias must be a valid identifier.');
        }

        $normalizedPartition = [];
        foreach ($partitionBy as $column) {
            $normalizedPartition[] = self::normalizeIdentifier($column, 'partition column');
        }

        $normalizedOrders = [];
        foreach ($orderBy as $order) {
            if (!is_array($order)) {
                throw new LogicException('Window orders must be structured arrays.');
            }
            $column = self::normalizeIdentifier($order['column'] ?? null, 'order column');
            $direction = $order['direction'] ?? null;
            if (!is_string($direction) || !in_array(strtolower($direction), ['asc', 'desc'], true)) {
                throw new LogicException('Window order direction must be ASC or DESC.');
            }
            $normalizedOrders[] = [
                'column' => $column,
                'direction' => strtolower($direction),
            ];
        }

        $this->partitionBy = $normalizedPartition;
        $this->orderBy = $normalizedOrders;
    }

    private static function normalizeIdentifier(mixed $identifier, string $context): string
    {
        if (!is_string($identifier) || preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/D', $identifier) !== 1) {
            throw new LogicException("Window {$context} must be a valid identifier.");
        }

        return $identifier;
    }
}
