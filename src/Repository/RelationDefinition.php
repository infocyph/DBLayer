<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Repository;

use Infocyph\DBLayer\Query\QueryBuilder;
use InvalidArgumentException;

final readonly class RelationDefinition
{
    public const string BELONGS_TO = 'belongs_to';
    public const string BELONGS_TO_MANY = 'belongs_to_many';
    public const string HAS_MANY = 'has_many';
    public const string HAS_ONE = 'has_one';
    public const string HAS_ONE_THROUGH = 'has_one_through';
    public const string MORPH_MANY = 'morph_many';
    public const string MORPH_ONE = 'morph_one';
    public const string MORPH_TO = 'morph_to';
    public const string MORPH_TO_MANY = 'morph_to_many';

    /**
     * @param class-string<TableRepository>|null $related
     * @param list<string> $columns
     * @param null|callable(QueryBuilder):void $scope
     * @param list<string> $pivotColumns
     * @param array<string,class-string<TableRepository>> $morphMap
     * @param class-string<TableRepository>|null $through
     * @param null|callable(QueryBuilder):void $oneOfManyScope
     * @param array<string,'max'|'min'> $oneOfManyOrders
     */
    public function __construct(
        public string $type,
        public ?string $related,
        public string $parentKey,
        public string $relatedKey,
        public array $columns = ['*'],
        public ?string $pivotTable = null,
        public ?string $pivotParentKey = null,
        public ?string $pivotRelatedKey = null,
        public mixed $scope = null,
        public array $pivotColumns = [],
        public string $pivotAccessor = 'pivot',
        public ?string $morphTypeColumn = null,
        public ?string $morphIdColumn = null,
        public ?string $morphAlias = null,
        public array $morphMap = [],
        public ?string $through = null,
        public ?string $throughParentKey = null,
        public ?string $throughKey = null,
        public ?string $oneOfManyColumn = null,
        public ?string $oneOfManyAggregate = null,
        public mixed $oneOfManyScope = null,
        public array $oneOfManyOrders = [],
    ) {
        foreach ($oneOfManyOrders as $column => $aggregate) {
            if (trim($column) === '') {
                throw new InvalidArgumentException('Advanced one-of-many orders require non-empty columns mapped to max or min.');
            }
        }
    }

    public function asPivot(string $accessor): self
    {
        $accessor = trim($accessor);
        if ($accessor === '') {
            throw new InvalidArgumentException('Pivot accessor must not be empty.');
        }

        return $this->copy(pivotAccessor: $accessor);
    }

    /** @param callable(QueryBuilder):void $scope */
    public function constrain(callable $scope): self
    {
        return $this->copy(scope: $scope);
    }

    /**
     * @param string|array<string,'max'|'min'>|null $column
     * @param string|callable(QueryBuilder):void $aggregate
     * @param null|callable(QueryBuilder):void $scope
     */
    public function ofMany(
        string|array|null $column = null,
        string|callable $aggregate = 'max',
        ?callable $scope = null,
    ): self {
        $type = $this->toOneType();

        return is_array($column)
            ? $this->advancedOfMany($type, $column, $aggregate, $scope)
            : $this->scalarOfMany($type, $column, $aggregate, $scope);
    }

    public function latestOfMany(?string $column = null): self
    {
        return $this->ofMany($column, 'max');
    }

    public function oldestOfMany(?string $column = null): self
    {
        return $this->ofMany($column, 'min');
    }

    public function one(): self
    {
        return $this->copy(type: $this->toOneType());
    }

    /** @param list<string> $columns */
    public function select(array $columns): self
    {
        return $this->copy(columns: $columns);
    }

    /** @param string|list<string> ...$columns */
    public function withPivot(string|array ...$columns): self
    {
        if ($this->pivotTable === null) {
            throw new InvalidArgumentException('Pivot columns require a many-to-many relation.');
        }

        $resolved = [];
        foreach ($columns as $columnGroup) {
            foreach (is_array($columnGroup) ? $columnGroup : [$columnGroup] as $column) {
                $column = trim($column);
                if ($column === '') {
                    throw new InvalidArgumentException('Pivot column names must not be empty.');
                }
                $resolved[$column] = true;
            }
        }

        return $this->copy(pivotColumns: array_keys($resolved));
    }

    /**
     * @param array<string,'max'|'min'> $criteria
     * @param string|callable(QueryBuilder):void $aggregate
     * @param null|callable(QueryBuilder):void $scope
     */
    private function advancedOfMany(string $type, array $criteria, string|callable $aggregate, ?callable $scope): self
    {
        if ($criteria === []) {
            throw new InvalidArgumentException('Advanced one-of-many criteria must not be empty.');
        }

        if (!is_string($aggregate)) {
            $scope = $aggregate;
        } elseif (strtolower(trim($aggregate)) !== 'max') {
            throw new InvalidArgumentException('Advanced one-of-many uses the second argument as an optional scope.');
        }

        $orders = $this->normalizeOrders($criteria);
        $firstColumn = array_key_first($orders);

        return $this->withOneOfMany($type, $firstColumn, $orders[$firstColumn], $scope, $orders);
    }

    /**
     * @param null|callable(QueryBuilder):void $scope
     */
    private function scalarOfMany(string $type, ?string $column, string|callable $aggregate, ?callable $scope): self
    {
        if (!is_string($aggregate)) {
            throw new InvalidArgumentException('Scalar one-of-many definitions require max or min as the aggregate.');
        }

        $aggregate = strtolower(trim($aggregate));
        if (!in_array($aggregate, ['max', 'min'], true)) {
            throw new InvalidArgumentException('One-of-many aggregate must be max or min.');
        }

        if ($column !== null) {
            $column = trim($column);
            if ($column === '') {
                throw new InvalidArgumentException('One-of-many column must not be empty.');
            }
        }

        return $this->withOneOfMany(
            $type,
            $column,
            $aggregate,
            $scope,
            $column === null ? [] : [$column => $aggregate],
        );
    }

    /**
     * @param array<string,'max'|'min'> $criteria
     * @return non-empty-array<non-empty-string,'max'|'min'>
     */
    private function normalizeOrders(array $criteria): array
    {
        $orders = [];
        foreach ($criteria as $column => $aggregate) {
            $column = trim($column);
            if ($column === '') {
                throw new InvalidArgumentException('Advanced one-of-many criteria require non-empty columns mapped to max or min.');
            }
            $orders[$column] = $aggregate;
        }

        return $orders;
    }

    /**
     * @param list<string>|null $columns
     * @param null|callable(QueryBuilder):void $scope
     * @param list<string>|null $pivotColumns
     */
    private function copy(
        ?string $type = null,
        ?array $columns = null,
        mixed $scope = null,
        ?array $pivotColumns = null,
        ?string $pivotAccessor = null,
    ): self {
        return new self(
            $type ?? $this->type,
            $this->related,
            $this->parentKey,
            $this->relatedKey,
            $columns ?? $this->columns,
            $this->pivotTable,
            $this->pivotParentKey,
            $this->pivotRelatedKey,
            $scope ?? $this->scope,
            $pivotColumns ?? $this->pivotColumns,
            $pivotAccessor ?? $this->pivotAccessor,
            $this->morphTypeColumn,
            $this->morphIdColumn,
            $this->morphAlias,
            $this->morphMap,
            $this->through,
            $this->throughParentKey,
            $this->throughKey,
            $this->oneOfManyColumn,
            $this->oneOfManyAggregate,
            $this->oneOfManyScope,
            $this->oneOfManyOrders,
        );
    }

    /**
     * @param null|callable(QueryBuilder):void $scope
     * @param array<string,'max'|'min'> $orders
     */
    private function withOneOfMany(
        string $type,
        ?string $column,
        string $aggregate,
        ?callable $scope,
        array $orders,
    ): self {
        return new self(
            $type,
            $this->related,
            $this->parentKey,
            $this->relatedKey,
            $this->columns,
            $this->pivotTable,
            $this->pivotParentKey,
            $this->pivotRelatedKey,
            $this->scope,
            $this->pivotColumns,
            $this->pivotAccessor,
            $this->morphTypeColumn,
            $this->morphIdColumn,
            $this->morphAlias,
            $this->morphMap,
            $this->through,
            $this->throughParentKey,
            $this->throughKey,
            $column,
            $aggregate,
            $scope,
            $orders,
        );
    }

    private function toOneType(): string
    {
        return match ($this->type) {
            self::HAS_MANY => self::HAS_ONE,
            self::MORPH_MANY => self::MORPH_ONE,
            self::HAS_ONE, self::MORPH_ONE => $this->type,
            default => throw new InvalidArgumentException(sprintf(
                'Relation type [%s] cannot be converted to a one-of-many relation.',
                $this->type,
            )),
        };
    }
}
