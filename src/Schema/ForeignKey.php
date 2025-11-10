<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Schema;

/**
 * Foreign Key Definition
 * 
 * Represents a foreign key constraint with all its properties.
 * Provides a fluent interface for configuring foreign key relationships.
 * 
 * @package DBLayer\Schema
 * @author Hasan
 */
class ForeignKey
{
    /**
     * The blueprint instance
     */
    protected Blueprint $blueprint;

    /**
     * The local columns
     */
    protected array $columns;

    /**
     * The foreign key name
     */
    protected string $name;

    /**
     * The referenced table
     */
    protected ?string $on = null;

    /**
     * The referenced columns
     */
    protected array $references = [];

    /**
     * The ON DELETE action
     */
    protected ?string $onDelete = null;

    /**
     * The ON UPDATE action
     */
    protected ?string $onUpdate = null;

    /**
     * Create a new foreign key instance
     */
    public function __construct(Blueprint $blueprint, array $columns, string $name)
    {
        $this->blueprint = $blueprint;
        $this->columns = $columns;
        $this->name = $name;
    }

    /**
     * Specify the referenced table
     */
    public function on(string $table): static
    {
        $this->on = $table;

        return $this;
    }

    /**
     * Specify the referenced column(s)
     */
    public function references(string|array $columns): static
    {
        $this->references = is_array($columns) ? $columns : [$columns];

        return $this;
    }

    /**
     * Specify the ON DELETE action
     */
    public function onDelete(string $action): static
    {
        $this->onDelete = $action;

        return $this;
    }

    /**
     * Set the foreign key to cascade on delete
     */
    public function cascadeOnDelete(): static
    {
        return $this->onDelete('cascade');
    }

    /**
     * Set the foreign key to restrict on delete
     */
    public function restrictOnDelete(): static
    {
        return $this->onDelete('restrict');
    }

    /**
     * Set the foreign key to set null on delete
     */
    public function nullOnDelete(): static
    {
        return $this->onDelete('set null');
    }

    /**
     * Set the foreign key to no action on delete
     */
    public function noActionOnDelete(): static
    {
        return $this->onDelete('no action');
    }

    /**
     * Specify the ON UPDATE action
     */
    public function onUpdate(string $action): static
    {
        $this->onUpdate = $action;

        return $this;
    }

    /**
     * Set the foreign key to cascade on update
     */
    public function cascadeOnUpdate(): static
    {
        return $this->onUpdate('cascade');
    }

    /**
     * Set the foreign key to restrict on update
     */
    public function restrictOnUpdate(): static
    {
        return $this->onUpdate('restrict');
    }

    /**
     * Set the foreign key to set null on update
     */
    public function nullOnUpdate(): static
    {
        return $this->onUpdate('set null');
    }

    /**
     * Set the foreign key to no action on update
     */
    public function noActionOnUpdate(): static
    {
        return $this->onUpdate('no action');
    }

    /**
     * Finalize the foreign key definition and add it to the blueprint
     */
    public function __destruct()
    {
        if ($this->on && !empty($this->references)) {
            $this->blueprint->addCommand('foreign', [
                'name' => $this->name,
                'columns' => $this->columns,
                'on' => $this->on,
                'references' => implode(',', $this->references),
                'onDelete' => $this->onDelete,
                'onUpdate' => $this->onUpdate,
            ]);
        }
    }

    /**
     * Get the foreign key name
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get the local columns
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /**
     * Get the referenced table
     */
    public function getOn(): ?string
    {
        return $this->on;
    }

    /**
     * Get the referenced columns
     */
    public function getReferences(): array
    {
        return $this->references;
    }

    /**
     * Get the ON DELETE action
     */
    public function getOnDelete(): ?string
    {
        return $this->onDelete;
    }

    /**
     * Get the ON UPDATE action
     */
    public function getOnUpdate(): ?string
    {
        return $this->onUpdate;
    }

    /**
     * Convert the foreign key to an array
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'columns' => $this->columns,
            'on' => $this->on,
            'references' => $this->references,
            'onDelete' => $this->onDelete,
            'onUpdate' => $this->onUpdate,
        ];
    }
}
