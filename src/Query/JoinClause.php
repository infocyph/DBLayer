<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Query;

/**
 * JOIN Clause Builder
 *
 * Builds complex JOIN clauses with multiple conditions:
 * - Multiple ON conditions
 * - OR conditions
 * - WHERE / WHERE IN / NULL checks inside JOIN
 */
final class JoinClause
{
    /**
     * Bound values for where/whereIn conditions.
     *
     * @var list<mixed>
     */
    private array $bindings = [];

    /**
     * The join conditions.
     *
     * @var list<array<string,mixed>>
     */
    private array $conditions = [];

    /**
     * The table being joined.
     */
    private string $table;

    /**
     * The type of join.
     */
    private string $type;

    /**
     * Create a new join clause instance.
     */
    public function __construct(string $table, string $type = 'inner')
    {
        $this->table = $table;
        $this->type  = $type;
    }

    /**
     * Get bindings for this join.
     *
     * @return list<mixed>
     */
    public function getBindings(): array
    {
        return $this->bindings;
    }

    /**
     * Get all join conditions.
     *
     * @return list<array<string,mixed>>
     */
    public function getConditions(): array
    {
        return $this->conditions;
    }

    /**
     * Get the table being joined.
     */
    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * Get the type of join.
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Check if join has conditions.
     */
    public function hasConditions(): bool
    {
        return $this->conditions !== [];
    }

    /**
     * Add an ON clause.
     */
    public function on(string $first, string $operator, string $second, string $boolean = 'and'): self
    {
        $this->conditions[] = [
          'type'     => 'basic',
          'first'    => $first,
          'operator' => $operator,
          'second'   => $second,
          'boolean'  => $boolean,
        ];

        return $this;
    }

    /**
     * Add an OR ON clause.
     */
    public function orOn(string $first, string $operator, string $second): self
    {
        return $this->on($first, $operator, $second, 'or');
    }

    /**
     * Add an OR WHERE clause to the join.
     */
    public function orWhere(string $column, string $operator, mixed $value): self
    {
        return $this->where($column, $operator, $value, 'or');
    }

    /**
     * Add a WHERE clause to the join.
     */
    public function where(string $column, string $operator, mixed $value, string $boolean = 'and'): self
    {
        $this->conditions[] = [
          'type'     => 'where',
          'column'   => $column,
          'operator' => $operator,
          'value'    => $value,
          'boolean'  => $boolean,
        ];

        $this->bindings[] = $value;

        return $this;
    }

    /**
     * Add a WHERE IN clause to the join.
     *
     * @param list<mixed> $values
     */
    public function whereIn(string $column, array $values, string $boolean = 'and'): self
    {
        $this->conditions[] = [
          'type'    => 'whereIn',
          'column'  => $column,
          'values'  => $values,
          'boolean' => $boolean,
        ];

        foreach ($values as $value) {
            $this->bindings[] = $value;
        }

        return $this;
    }

    /**
     * Add a WHERE NOT NULL clause to the join.
     */
    public function whereNotNull(string $column, string $boolean = 'and'): self
    {
        $this->conditions[] = [
          'type'    => 'whereNotNull',
          'column'  => $column,
          'boolean' => $boolean,
        ];

        return $this;
    }

    /**
     * Add a WHERE NULL clause to the join.
     */
    public function whereNull(string $column, string $boolean = 'and'): self
    {
        $this->conditions[] = [
          'type'    => 'whereNull',
          'column'  => $column,
          'boolean' => $boolean,
        ];

        return $this;
    }
}
