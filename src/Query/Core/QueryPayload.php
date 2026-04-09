<?php

// src/Query/Core/QueryPayload.php

declare(strict_types=1);

namespace Infocyph\DBLayer\Query\Core;

use Infocyph\DBLayer\Query\Expression;

/**
 * Immutable query payload (AST-ish).
 *
 * Everything the driver/compiler needs to turn a structured query into SQL.
 */
final readonly class QueryPayload
{
    /**
     * @param list<string|Expression>                 $columns
     * @param list<array<string,mixed>>              $wheres
     * @param list<array<string,mixed>|object>       $joins   JoinClause-like arrays or objects
     * @param list<string>                           $groups
     * @param list<array<string,mixed>>              $havings
     * @param list<array{column:string,direction:string}> $orders
     * @param list<array{query:QueryPayload,all:bool}> $unions
     * @param array{function:string,column:string}|null $aggregate
     * @param list<mixed>                             $bindings
     */
    public function __construct(
        public QueryType $type,
        public ?string $table,
        public array $columns,
        public array $wheres,
        public array $joins,
        public array $groups,
        public array $havings,
        public array $orders,
        public ?int $limit,
        public ?int $offset,
        public array $unions,
        public ?string $lock,
        public ?array $aggregate,
        public array $bindings,
    ) {}

    /**
     * Lightweight clone with overridden pieces.
     *
     * @param array{
     *   type?:QueryType,
     *   table?:?string,
     *   columns?:array,
     *   wheres?:array,
     *   joins?:array,
     *   groups?:array,
     *   havings?:array,
     *   orders?:array,
     *   limit?:?int,
     *   offset?:?int,
     *   unions?:array,
     *   lock?:?string,
     *   aggregate?:?array,
     *   bindings?:array
     * } $overrides
     */
    public function with(array $overrides): self
    {
        return new self(
            $overrides['type']      ?? $this->type,
            $overrides['table']     ?? $this->table,
            $overrides['columns']   ?? $this->columns,
            $overrides['wheres']    ?? $this->wheres,
            $overrides['joins']     ?? $this->joins,
            $overrides['groups']    ?? $this->groups,
            $overrides['havings']   ?? $this->havings,
            $overrides['orders']    ?? $this->orders,
            $overrides['limit']     ?? $this->limit,
            $overrides['offset']    ?? $this->offset,
            $overrides['unions']    ?? $this->unions,
            $overrides['lock']      ?? $this->lock,
            $overrides['aggregate'] ?? $this->aggregate,
            $overrides['bindings']  ?? $this->bindings,
        );
    }
}
