<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Repository;

use Infocyph\DBLayer\Query\QueryBuilder;

final readonly class RelationDefinition
{
    public const string BELONGS_TO = 'belongs_to';
    public const string BELONGS_TO_MANY = 'belongs_to_many';
    public const string HAS_MANY = 'has_many';
    public const string HAS_ONE = 'has_one';

    /**
     * @param class-string<TableRepository> $related
     * @param list<string> $columns
     * @param null|callable(QueryBuilder):void $scope
     */
    public function __construct(
        public string $type,
        public string $related,
        public string $parentKey,
        public string $relatedKey,
        public array $columns = ['*'],
        public ?string $pivotTable = null,
        public ?string $pivotParentKey = null,
        public ?string $pivotRelatedKey = null,
        public mixed $scope = null,
    ) {}

    /**
     * Return a copy with constrained related-row selection.
     *
     * @param callable(QueryBuilder):void $scope
     */
    public function constrain(callable $scope): self
    {
        return new self(
            $this->type,
            $this->related,
            $this->parentKey,
            $this->relatedKey,
            $this->columns,
            $this->pivotTable,
            $this->pivotParentKey,
            $this->pivotRelatedKey,
            $scope,
        );
    }

    /**
     * Return a copy with an explicit related-column projection.
     *
     * @param list<string> $columns
     */
    public function select(array $columns): self
    {
        return new self(
            $this->type,
            $this->related,
            $this->parentKey,
            $this->relatedKey,
            $columns,
            $this->pivotTable,
            $this->pivotParentKey,
            $this->pivotRelatedKey,
            $this->scope,
        );
    }
}
