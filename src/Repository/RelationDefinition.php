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
    ) {}

    /**
     * Return a copy with an alternate pivot projection key.
     */
    public function asPivot(string $accessor): self
    {
        $accessor = trim($accessor);
        if ($accessor === '') {
            throw new InvalidArgumentException('Pivot accessor must not be empty.');
        }

        return $this->copy(pivotAccessor: $accessor);
    }

    /**
     * Return a copy with constrained related-row selection.
     *
     * @param callable(QueryBuilder):void $scope
     */
    public function constrain(callable $scope): self
    {
        return $this->copy(scope: $scope);
    }

    /**
     * Return a copy with an explicit related-column projection.
     *
     * @param list<string> $columns
     */
    public function select(array $columns): self
    {
        return $this->copy(columns: $columns);
    }

    /**
     * Project selected pivot attributes onto many-to-many relation rows.
     *
     * @param string|list<string> ...$columns
     */
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
     * @param list<string>|null $columns
     * @param null|callable(QueryBuilder):void $scope
     * @param list<string>|null $pivotColumns
     */
    private function copy(
        ?array $columns = null,
        mixed $scope = null,
        ?array $pivotColumns = null,
        ?string $pivotAccessor = null,
    ): self {
        return new self(
            $this->type,
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
        );
    }
}
