<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Schema;

/**
 * Table Blueprint
 *
 * Defines table structure with fluent column and index methods:
 * - Column definitions (string, integer, text, etc.)
 * - Indexes (primary, unique, index, foreign)
 * - Table operations (create, drop, rename)
 * - Modifiers (nullable, default, unsigned, etc.)
 *
 * @package Infocyph\DBLayer\Schema
 * @author Hasan
 */
class Blueprint
{
    /**
     * The table charset
     */
    private ?string $charset = null;

    /**
     * The table collation
     */
    private ?string $collation = null;

    /**
     * The columns to add
     */
    private array $columns = [];

    /**
     * The commands to execute
     */
    private array $commands = [];

    /**
     * The table engine
     */
    private ?string $engine = null;

    /**
     * Whether to add soft deletes
     */
    private bool $softDeletes = false;
    /**
     * The table name
     */
    private string $table;

    /**
     * Whether to add timestamps
     */
    private bool $timestamps = false;

    /**
     * Create a new blueprint instance
     */
    public function __construct(string $table)
    {
        $this->table = $table;
    }

    /**
     * Add an auto-incrementing big integer column
     */
    public function bigIncrements(string $column): Column
    {
        $col = $this->addColumn('bigIncrements', $column);
        $col->autoIncrement()->unsigned();
        $this->primary($column);
        return $col;
    }

    /**
     * Add a big integer column
     */
    public function bigInteger(string $column): Column
    {
        return $this->addColumn('bigInteger', $column);
    }

    /**
     * Add a binary column
     */
    public function binary(string $column): Column
    {
        return $this->addColumn('binary', $column);
    }

    /**
     * Add a boolean column
     */
    public function boolean(string $column): Column
    {
        return $this->addColumn('boolean', $column);
    }

    /**
     * Add a char column
     */
    public function char(string $column, int $length = 255): Column
    {
        return $this->addColumn('char', $column, ['length' => $length]);
    }

    /**
     * Set table charset
     */
    public function charset(string $charset): void
    {
        $this->charset = $charset;
    }

    /**
     * Set table collation
     */
    public function collation(string $collation): void
    {
        $this->collation = $collation;
    }

    /**
     * Create the table
     */
    public function create(): void
    {
        $this->addCommand('create');
    }

    /**
     * Add a date column
     */
    public function date(string $column): Column
    {
        return $this->addColumn('date', $column);
    }

    /**
     * Add a datetime column
     */
    public function dateTime(string $column, int $precision = 0): Column
    {
        return $this->addColumn('dateTime', $column, ['precision' => $precision]);
    }

    /**
     * Add a decimal column
     */
    public function decimal(string $column, int $precision = 10, int $scale = 2): Column
    {
        return $this->addColumn('decimal', $column, ['precision' => $precision, 'scale' => $scale]);
    }

    /**
     * Add a double column
     */
    public function double(string $column, int $precision = 15, int $scale = 8): Column
    {
        return $this->addColumn('double', $column, ['precision' => $precision, 'scale' => $scale]);
    }

    /**
     * Drop the table
     */
    public function drop(): void
    {
        $this->addCommand('drop');
    }

    /**
     * Drop a column
     */
    public function dropColumn(string|array $columns): void
    {
        $this->addCommand('dropColumn', [
            'columns' => (array) $columns,
        ]);
    }

    /**
     * Drop a foreign key
     */
    public function dropForeign(string|array $columns): void
    {
        $this->addCommand('dropForeign', [
            'columns' => (array) $columns,
        ]);
    }

    /**
     * Drop the table if it exists
     */
    public function dropIfExists(): void
    {
        $this->addCommand('dropIfExists');
    }

    /**
     * Drop an index
     */
    public function dropIndex(string|array $columns): void
    {
        $this->addCommand('dropIndex', [
            'columns' => (array) $columns,
        ]);
    }

    /**
     * Drop a primary key
     */
    public function dropPrimary(?string $name = null): void
    {
        $this->addCommand('dropPrimary', ['name' => $name]);
    }

    /**
     * Drop a unique index
     */
    public function dropUnique(string|array $columns): void
    {
        $this->addCommand('dropUnique', [
            'columns' => (array) $columns,
        ]);
    }

    /**
     * Set table engine
     */
    public function engine(string $engine): void
    {
        $this->engine = $engine;
    }

    /**
     * Add an enum column
     */
    public function enum(string $column, array $allowed): Column
    {
        return $this->addColumn('enum', $column, ['allowed' => $allowed]);
    }

    /**
     * Add a float column
     */
    public function float(string $column, int $precision = 8, int $scale = 2): Column
    {
        return $this->addColumn('float', $column, ['precision' => $precision, 'scale' => $scale]);
    }

    /**
     * Add a foreign key
     */
    public function foreign(string|array $columns, ?string $name = null): ForeignKey
    {
        $foreignKey = new ForeignKey((array) $columns, $name);

        $this->addCommand('foreign', [
            'foreignKey' => $foreignKey,
        ]);

        return $foreignKey;
    }

    /**
     * Add a foreign key column
     */
    public function foreignId(string $column): Column
    {
        return $this->unsignedBigInteger($column);
    }

    /**
     * Add a foreign key with constrained relationship
     */
    public function foreignIdFor(string $model, ?string $column = null): ForeignKey
    {
        $column = $column ?? strtolower(class_basename($model)) . '_id';
        $this->unsignedBigInteger($column);

        return $this->foreign($column);
    }

    /**
     * Add a fulltext index
     */
    public function fulltext(string|array $columns, ?string $name = null): void
    {
        $this->addCommand('fulltext', [
            'columns' => (array) $columns,
            'name' => $name,
        ]);
    }

    /**
     * Get table charset
     */
    public function getCharset(): ?string
    {
        return $this->charset;
    }

    /**
     * Get table collation
     */
    public function getCollation(): ?string
    {
        return $this->collation;
    }

    /**
     * Get all columns
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /**
     * Get all commands
     */
    public function getCommands(): array
    {
        return $this->commands;
    }

    /**
     * Get table engine
     */
    public function getEngine(): ?string
    {
        return $this->engine;
    }

    /**
     * Get table name
     */
    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * Check if using soft deletes
     */
    public function hasSoftDeletes(): bool
    {
        return $this->softDeletes;
    }

    /**
     * Check if using timestamps
     */
    public function hasTimestamps(): bool
    {
        return $this->timestamps;
    }

    /**
     * Add an incrementing ID column
     */
    public function id(string $column = 'id'): Column
    {
        return $this->bigIncrements($column);
    }

    /**
     * Add an auto-incrementing integer column
     */
    public function increments(string $column): Column
    {
        $col = $this->addColumn('increments', $column);
        $col->autoIncrement()->unsigned();
        $this->primary($column);
        return $col;
    }

    /**
     * Add an index
     */
    public function index(string|array $columns, ?string $name = null): void
    {
        $this->addCommand('index', [
            'columns' => (array) $columns,
            'name' => $name,
        ]);
    }

    /**
     * Add an integer column
     */
    public function integer(string $column): Column
    {
        return $this->addColumn('integer', $column);
    }

    /**
     * Add an IP address column
     */
    public function ipAddress(string $column): Column
    {
        return $this->string($column, 45);
    }

    /**
     * Add a JSON column
     */
    public function json(string $column): Column
    {
        return $this->addColumn('json', $column);
    }

    /**
     * Add a JSONB column (PostgreSQL)
     */
    public function jsonb(string $column): Column
    {
        return $this->addColumn('jsonb', $column);
    }

    /**
     * Add a long text column
     */
    public function longText(string $column): Column
    {
        return $this->addColumn('longText', $column);
    }

    /**
     * Add a MAC address column
     */
    public function macAddress(string $column): Column
    {
        return $this->string($column, 17);
    }

    /**
     * Add a medium integer column
     */
    public function mediumInteger(string $column): Column
    {
        return $this->addColumn('mediumInteger', $column);
    }

    /**
     * Add a medium text column
     */
    public function mediumText(string $column): Column
    {
        return $this->addColumn('mediumText', $column);
    }

    /**
     * Add a primary key
     */
    public function primary(string|array $columns, ?string $name = null): void
    {
        $this->addCommand('primary', [
            'columns' => (array) $columns,
            'name' => $name,
        ]);
    }

    /**
     * Add remember_token column
     */
    public function rememberToken(): Column
    {
        return $this->string('remember_token', 100)->nullable();
    }

    /**
     * Rename the table
     */
    public function rename(string $to): void
    {
        $this->addCommand('rename', ['to' => $to]);
    }

    /**
     * Rename a column
     */
    public function renameColumn(string $from, string $to): void
    {
        $this->addCommand('renameColumn', [
            'from' => $from,
            'to' => $to,
        ]);
    }

    /**
     * Add a small auto-incrementing integer column
     */
    public function smallIncrements(string $column): Column
    {
        $col = $this->addColumn('smallIncrements', $column);
        $col->autoIncrement()->unsigned();
        $this->primary($column);
        return $col;
    }

    /**
     * Add a small integer column
     */
    public function smallInteger(string $column): Column
    {
        return $this->addColumn('smallInteger', $column);
    }

    /**
     * Add a deleted_at column for soft deletes
     */
    public function softDeletes(string $column = 'deleted_at', int $precision = 0): Column
    {
        $this->softDeletes = true;
        return $this->timestamp($column, $precision)->nullable();
    }

    /**
     * Add a string column
     */
    public function string(string $column, int $length = 255): Column
    {
        return $this->addColumn('string', $column, ['length' => $length]);
    }

    /**
     * Add a text column
     */
    public function text(string $column): Column
    {
        return $this->addColumn('text', $column);
    }

    /**
     * Add a time column
     */
    public function time(string $column, int $precision = 0): Column
    {
        return $this->addColumn('time', $column, ['precision' => $precision]);
    }

    /**
     * Add a timestamp column
     */
    public function timestamp(string $column, int $precision = 0): Column
    {
        return $this->addColumn('timestamp', $column, ['precision' => $precision]);
    }

    /**
     * Add created_at and updated_at columns
     */
    public function timestamps(int $precision = 0): void
    {
        $this->timestamp('created_at', $precision)->nullable();
        $this->timestamp('updated_at', $precision)->nullable();
        $this->timestamps = true;
    }

    /**
     * Add a tiny integer column
     */
    public function tinyInteger(string $column): Column
    {
        return $this->addColumn('tinyInteger', $column);
    }

    /**
     * Add a unique index
     */
    public function unique(string|array $columns, ?string $name = null): void
    {
        $this->addCommand('unique', [
            'columns' => (array) $columns,
            'name' => $name,
        ]);
    }

    /**
     * Add an unsigned big integer column
     */
    public function unsignedBigInteger(string $column): Column
    {
        return $this->bigInteger($column)->unsigned();
    }

    /**
     * Add an unsigned integer column
     */
    public function unsignedInteger(string $column): Column
    {
        return $this->integer($column)->unsigned();
    }

    /**
     * Add a UUID column
     */
    public function uuid(string $column = 'id'): Column
    {
        $col = $this->addColumn('uuid', $column);
        $col->primary();
        return $col;
    }

    /**
     * Add a UUID column (binary or string based on driver)
     */
    public function uuidColumn(string $column): Column
    {
        return $this->addColumn('uuid', $column);
    }

    /**
     * Add a year column
     */
    public function year(string $column): Column
    {
        return $this->addColumn('year', $column);
    }

    /**
     * Add a new column
     */
    private function addColumn(string $type, string $name, array $parameters = []): Column
    {
        $column = new Column($type, $name, $parameters);
        $this->columns[] = $column;

        return $column;
    }

    /**
     * Add a command
     */
    private function addCommand(string $name, array $parameters = []): void
    {
        $this->commands[] = array_merge(['name' => $name], $parameters);
    }
}
