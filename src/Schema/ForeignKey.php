<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Schema;

/**
 * Foreign Key Definition
 *
 * Defines foreign key constraints with:
 * - Reference table and columns
 * - ON DELETE and ON UPDATE actions
 * - Constraint naming
 *
 * @package Infocyph\DBLayer\Schema
 * @author Hasan
 */
class ForeignKey
{
    /**
     * The local columns
     */
    private array $columns;

    /**
     * The constraint name
     */
    private ?string $name;

    /**
     * The ON DELETE action
     */
    private ?string $onDelete = null;

    /**
     * The ON UPDATE action
     */
    private ?string $onUpdate = null;

    /**
     * The reference columns
     */
    private array $referenceColumns = ['id'];

    /**
     * The reference table
     */
    private ?string $referenceTable = null;

    /**
     * Create a new foreign key instance
     */
    public function __construct(array $columns, ?string $name = null)
    {
        $this->columns = $columns;
        $this->name = $name;
    }

    /**
     * Set ON DELETE CASCADE
     */
    public function cascadeOnDelete(): self
    {
        return $this->onDelete('CASCADE');
    }

    /**
     * Set ON UPDATE CASCADE
     */
    public function cascadeOnUpdate(): self
    {
        return $this->onUpdate('CASCADE');
    }

    /**
     * Constrained - shorthand for common pattern
     */
    public function constrained(?string $table = null, string $column = 'id'): self
    {
        if ($table === null) {
            // Infer table name from column name
            $table = str_replace('_id', '', $this->columns[0]) . 's';
        }

        return $this->references($column)->on($table);
    }

    /**
     * Get the local columns
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /**
     * Get the constraint name
     */
    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * Get ON DELETE action
     */
    public function getOnDelete(): ?string
    {
        return $this->onDelete;
    }

    /**
     * Get ON UPDATE action
     */
    public function getOnUpdate(): ?string
    {
        return $this->onUpdate;
    }

    /**
     * Get the reference columns
     */
    public function getReferenceColumns(): array
    {
        return $this->referenceColumns;
    }

    /**
     * Get the reference table
     */
    public function getReferenceTable(): ?string
    {
        return $this->referenceTable;
    }

    /**
     * Check if foreign key is valid
     */
    public function isValid(): bool
    {
        return !empty($this->columns) &&
               !empty($this->referenceTable) &&
               !empty($this->referenceColumns);
    }

    /**
     * Set constraint name
     */
    public function name(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Set ON DELETE NO ACTION
     */
    public function noActionOnDelete(): self
    {
        return $this->onDelete('NO ACTION');
    }

    /**
     * Set ON UPDATE NO ACTION
     */
    public function noActionOnUpdate(): self
    {
        return $this->onUpdate('NO ACTION');
    }

    /**
     * Set ON DELETE SET NULL
     */
    public function nullOnDelete(): self
    {
        return $this->onDelete('SET NULL');
    }

    /**
     * Set ON UPDATE SET NULL
     */
    public function nullOnUpdate(): self
    {
        return $this->onUpdate('SET NULL');
    }

    /**
     * Set the reference table
     */
    public function on(string $table): self
    {
        $this->referenceTable = $table;
        return $this;
    }

    /**
     * Set ON DELETE action
     */
    public function onDelete(string $action): self
    {
        $this->onDelete = strtoupper($action);
        return $this;
    }

    /**
     * Set ON UPDATE action
     */
    public function onUpdate(string $action): self
    {
        $this->onUpdate = strtoupper($action);
        return $this;
    }

    /**
     * Set the reference table
     */
    public function references(string|array $columns): self
    {
        $this->referenceColumns = (array) $columns;
        return $this;
    }

    /**
     * Set ON DELETE RESTRICT
     */
    public function restrictOnDelete(): self
    {
        return $this->onDelete('RESTRICT');
    }

    /**
     * Set ON UPDATE RESTRICT
     */
    public function restrictOnUpdate(): self
    {
        return $this->onUpdate('RESTRICT');
    }
}
