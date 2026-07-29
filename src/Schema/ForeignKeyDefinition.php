<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Schema;

use Infocyph\DBLayer\Exceptions\SchemaException;

final class ForeignKeyDefinition
{
    public ?string $name = null;

    public ?string $onDelete = null;

    public ?string $onUpdate = null;

    /** @param non-empty-list<string> $columns */
    public function __construct(
        public readonly array $columns,
        public string $referencedTable = '',
        /** @var non-empty-list<string> */
        public array $referencedColumns = ['id'],
    ) {}

    public function named(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function on(string $table): self
    {
        $this->referencedTable = $table;

        return $this;
    }

    public function onDelete(string $action): self
    {
        $this->onDelete = strtoupper(trim($action));

        return $this;
    }

    public function onUpdate(string $action): self
    {
        $this->onUpdate = strtoupper(trim($action));

        return $this;
    }

    /** @param string|list<string> $columns */
    public function references(string|array $columns): self
    {
        if ($columns === []) {
            throw SchemaException::invalid('Foreign key referenced columns must not be empty.');
        }

        $this->referencedColumns = is_array($columns) ? $columns : [$columns];

        return $this;
    }
}
