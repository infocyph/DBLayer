<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Query;

use Infocyph\DBLayer\Exceptions\QueryException;
use Infocyph\DBLayer\Exceptions\SecurityException;
use Infocyph\DBLayer\Security\Security;

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
     * Allowed comparison operators for join clauses.
     *
     * @var list<string>
     */
    private const array ALLOWED_OPERATORS = [
        '=',
        '!=',
        '<>',
        '<',
        '>',
        '<=',
        '>=',
        '<=>',
        'like',
        'not like',
        'ilike',
        'not ilike',
        'regexp',
        'not regexp',
        'rlike',
        'not rlike',
        '~',
        '~*',
        '!~',
        '!~*',
        'similar to',
        'not similar to',
        'is',
        'is not',
    ];

    /**
     * Optional structured table alias.
     */
    private readonly ?string $alias;

    /**
     * The table being joined.
     */
    private readonly string $table;

    /**
     * The normalized join type.
     */
    private readonly string $type;

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
     * Create a new join clause instance.
     */
    public function __construct(string $table, string $type = 'inner', ?string $alias = null)
    {
        $this->validateTableIdentifier($table);
        if ($alias !== null) {
            $this->validateColumnIdentifier($alias);
        }
        $type = strtolower(trim($type));

        if (!in_array($type, ['inner', 'left', 'right', 'cross'], true)) {
            throw QueryException::invalidParameter('type', 'Unsupported JOIN type.');
        }

        $this->table = $table;
        $this->type = $type;
        $this->alias = $alias;
    }

    /**
     * Get the structured table alias.
     */
    public function getAlias(): ?string
    {
        return $this->alias;
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
        $boolean = $this->normalizeBoolean($boolean);
        $this->validateColumnIdentifier($first);
        $this->validateColumnIdentifier($second);
        $operator = $this->assertValidOperator($operator);

        $this->conditions[] = [
            'type' => 'basic',
            'first' => $first,
            'operator' => $operator,
            'second' => $second,
            'boolean' => $boolean,
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
        $boolean = $this->normalizeBoolean($boolean);
        $this->validateColumnIdentifier($column);
        $operator = $this->assertValidOperator($operator);

        $this->conditions[] = [
            'type' => 'where',
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
            'boolean' => $boolean,
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
        $boolean = $this->normalizeBoolean($boolean);
        $this->validateColumnIdentifier($column);

        $this->conditions[] = [
            'type' => 'whereIn',
            'column' => $column,
            'values' => $values,
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
        $boolean = $this->normalizeBoolean($boolean);
        $this->validateColumnIdentifier($column);

        $this->conditions[] = [
            'type' => 'whereNotNull',
            'column' => $column,
            'boolean' => $boolean,
        ];

        return $this;
    }

    /**
     * Add a WHERE NULL clause to the join.
     */
    public function whereNull(string $column, string $boolean = 'and'): self
    {
        $boolean = $this->normalizeBoolean($boolean);
        $this->validateColumnIdentifier($column);

        $this->conditions[] = [
            'type' => 'whereNull',
            'column' => $column,
            'boolean' => $boolean,
        ];

        return $this;
    }

    /**
     * Validate and normalize a comparison operator.
     */
    private function assertValidOperator(string $operator): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($operator));
        $normalized = strtolower($normalized ?? $operator);

        if (!\in_array($normalized, self::ALLOWED_OPERATORS, true)) {
            throw QueryException::invalidOperator($operator);
        }

        return $normalized;
    }

    private function normalizeBoolean(string $boolean): string
    {
        $boolean = strtolower(trim($boolean));

        if (!in_array($boolean, ['and', 'or'], true)) {
            throw QueryException::invalidParameter('boolean', 'Boolean combinator must be AND or OR.');
        }

        return $boolean;
    }

    /**
     * Validate join column identifier.
     */
    private function validateColumnIdentifier(string $column): void
    {
        try {
            Security::validateColumnName($column);
        } catch (SecurityException $e) {
            throw QueryException::invalidParameter('column', $e->getMessage());
        }
    }

    /**
     * Validate join table identifier.
     */
    private function validateTableIdentifier(string $table): void
    {
        try {
            Security::validateTableName($table);
        } catch (SecurityException $e) {
            throw QueryException::invalidParameter('table', $e->getMessage());
        }
    }
}
