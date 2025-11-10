<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Schema;

use Infocyph\DBLayer\Connection;
use Infocyph\DBLayer\Grammar\Grammar;

/**
 * Schema Blueprint
 * 
 * Provides a fluent interface for defining table structures.
 * Tracks all columns, indexes, and foreign keys to be added to a table.
 * 
 * @package DBLayer\Schema
 * @author Hasan
 */
class Blueprint
{
    /**
     * The table the blueprint describes
     */
    protected string $table;

    /**
     * The columns that should be added
     */
    protected array $columns = [];

    /**
     * The commands that should be run
     */
    protected array $commands = [];

    /**
     * Whether to make the table temporary
     */
    public bool $temporary = false;

    /**
     * The storage engine that should be used
     */
    public ?string $engine = null;

    /**
     * The default character set
     */
    public ?string $charset = null;

    /**
     * The collation that should be used
     */
    public ?string $collation = null;

    /**
     * Create a new schema blueprint
     */
    public function __construct(string $table, ?\Closure $callback = null)
    {
        $this->table = $table;

        if (!is_null($callback)) {
            $callback($this);
        }
    }

    /**
     * Execute the blueprint against the database
     */
    public function build(Connection $connection, Grammar $grammar): void
    {
        foreach ($this->toSql($connection, $grammar) as $statement) {
            $connection->statement($statement);
        }
    }

    /**
     * Get the raw SQL statements for the blueprint
     */
    public function toSql(Connection $connection, Grammar $grammar): array
    {
        $statements = [];

        foreach ($this->commands as $command) {
            $method = 'compile' . ucfirst($command['name']);

            if (method_exists($grammar, $method)) {
                if (!is_null($sql = $grammar->$method($this, $command))) {
                    $statements = array_merge($statements, (array) $sql);
                }
            }
        }

        return $statements;
    }

    /**
     * Indicate that the table needs to be created
     */
    public function create(): void
    {
        $this->addCommand('create');
    }

    /**
     * Indicate that the table needs to be temporary
     */
    public function temporary(): void
    {
        $this->temporary = true;
    }

    /**
     * Indicate that the table should be dropped
     */
    public function drop(): void
    {
        $this->addCommand('drop');
    }

    /**
     * Indicate that the table should be dropped if it exists
     */
    public function dropIfExists(): void
    {
        $this->addCommand('dropIfExists');
    }

    /**
     * Indicate that the given columns should be dropped
     */
    public function dropColumn(string|array $columns): void
    {
        $columns = is_array($columns) ? $columns : func_get_args();

        foreach ($columns as $column) {
            $this->addCommand('dropColumn', ['column' => $column]);
        }
    }

    /**
     * Indicate that the given columns should be renamed
     */
    public function renameColumn(string $from, string $to): void
    {
        $this->addCommand('renameColumn', compact('from', 'to'));
    }

    /**
     * Indicate that the given primary key should be dropped
     */
    public function dropPrimary(string|array $index = null): void
    {
        $this->addCommand('dropPrimary', ['index' => $index]);
    }

    /**
     * Indicate that the given unique key should be dropped
     */
    public function dropUnique(string|array $index): void
    {
        $this->addCommand('dropUnique', ['index' => $this->createIndexName('unique', (array) $index)]);
    }

    /**
     * Indicate that the given index should be dropped
     */
    public function dropIndex(string|array $index): void
    {
        $this->addCommand('dropIndex', ['index' => $this->createIndexName('index', (array) $index)]);
    }

    /**
     * Indicate that the given foreign key should be dropped
     */
    public function dropForeign(string|array $index): void
    {
        $this->addCommand('dropForeign', ['index' => $this->createIndexName('foreign', (array) $index)]);
    }

    /**
     * Indicate that the timestamp columns should be dropped
     */
    public function dropTimestamps(): void
    {
        $this->dropColumn('created_at', 'updated_at');
    }

    /**
     * Indicate that the soft delete column should be dropped
     */
    public function dropSoftDeletes(string $column = 'deleted_at'): void
    {
        $this->dropColumn($column);
    }

    /**
     * Rename the table to a given name
     */
    public function rename(string $to): void
    {
        $this->addCommand('rename', compact('to'));
    }

    /**
     * Specify the primary key(s) for the table
     */
    public function primary(string|array $columns, ?string $name = null): void
    {
        $this->addCommand('primary', [
            'columns' => (array) $columns,
            'index' => $name,
        ]);
    }

    /**
     * Specify a unique index for the table
     */
    public function unique(string|array $columns, ?string $name = null): void
    {
        $this->addCommand('unique', [
            'columns' => (array) $columns,
            'index' => $name ?? $this->createIndexName('unique', (array) $columns),
        ]);
    }

    /**
     * Specify an index for the table
     */
    public function index(string|array $columns, ?string $name = null, ?string $type = null): void
    {
        $this->addCommand('index', [
            'columns' => (array) $columns,
            'index' => $name ?? $this->createIndexName('index', (array) $columns),
            'type' => $type,
        ]);
    }

    /**
     * Specify a foreign key for the table
     */
    public function foreign(string|array $columns, ?string $name = null): ForeignKey
    {
        $name = $name ?? $this->createIndexName('foreign', (array) $columns);

        return new ForeignKey($this, (array) $columns, $name);
    }

    /**
     * Create a new auto-incrementing big integer column
     */
    public function id(string $column = 'id'): Column
    {
        return $this->bigInteger($column)->autoIncrement()->primary();
    }

    /**
     * Create a new char column
     */
    public function char(string $column, ?int $length = null): Column
    {
        $length = $length ?: Schema::$defaultStringLength;

        return $this->addColumn('char', $column, compact('length'));
    }

    /**
     * Create a new string column
     */
    public function string(string $column, ?int $length = null): Column
    {
        $length = $length ?: Schema::$defaultStringLength;

        return $this->addColumn('string', $column, compact('length'));
    }

    /**
     * Create a new text column
     */
    public function text(string $column): Column
    {
        return $this->addColumn('text', $column);
    }

    /**
     * Create a new medium text column
     */
    public function mediumText(string $column): Column
    {
        return $this->addColumn('mediumText', $column);
    }

    /**
     * Create a new long text column
     */
    public function longText(string $column): Column
    {
        return $this->addColumn('longText', $column);
    }

    /**
     * Create a new integer column
     */
    public function integer(string $column, bool $autoIncrement = false, bool $unsigned = false): Column
    {
        return $this->addColumn('integer', $column, compact('autoIncrement', 'unsigned'));
    }

    /**
     * Create a new tiny integer column
     */
    public function tinyInteger(string $column, bool $autoIncrement = false, bool $unsigned = false): Column
    {
        return $this->addColumn('tinyInteger', $column, compact('autoIncrement', 'unsigned'));
    }

    /**
     * Create a new small integer column
     */
    public function smallInteger(string $column, bool $autoIncrement = false, bool $unsigned = false): Column
    {
        return $this->addColumn('smallInteger', $column, compact('autoIncrement', 'unsigned'));
    }

    /**
     * Create a new medium integer column
     */
    public function mediumInteger(string $column, bool $autoIncrement = false, bool $unsigned = false): Column
    {
        return $this->addColumn('mediumInteger', $column, compact('autoIncrement', 'unsigned'));
    }

    /**
     * Create a new big integer column
     */
    public function bigInteger(string $column, bool $autoIncrement = false, bool $unsigned = false): Column
    {
        return $this->addColumn('bigInteger', $column, compact('autoIncrement', 'unsigned'));
    }

    /**
     * Create a new unsigned integer column
     */
    public function unsignedInteger(string $column, bool $autoIncrement = false): Column
    {
        return $this->integer($column, $autoIncrement, true);
    }

    /**
     * Create a new unsigned tiny integer column
     */
    public function unsignedTinyInteger(string $column, bool $autoIncrement = false): Column
    {
        return $this->tinyInteger($column, $autoIncrement, true);
    }

    /**
     * Create a new unsigned small integer column
     */
    public function unsignedSmallInteger(string $column, bool $autoIncrement = false): Column
    {
        return $this->smallInteger($column, $autoIncrement, true);
    }

    /**
     * Create a new unsigned medium integer column
     */
    public function unsignedMediumInteger(string $column, bool $autoIncrement = false): Column
    {
        return $this->mediumInteger($column, $autoIncrement, true);
    }

    /**
     * Create a new unsigned big integer column
     */
    public function unsignedBigInteger(string $column, bool $autoIncrement = false): Column
    {
        return $this->bigInteger($column, $autoIncrement, true);
    }

    /**
     * Create a new float column
     */
    public function float(string $column, int $precision = 53): Column
    {
        return $this->addColumn('float', $column, compact('precision'));
    }

    /**
     * Create a new double column
     */
    public function double(string $column): Column
    {
        return $this->addColumn('double', $column);
    }

    /**
     * Create a new decimal column
     */
    public function decimal(string $column, int $precision = 8, int $scale = 2): Column
    {
        return $this->addColumn('decimal', $column, compact('precision', 'scale'));
    }

    /**
     * Create a new boolean column
     */
    public function boolean(string $column): Column
    {
        return $this->addColumn('boolean', $column);
    }

    /**
     * Create a new enum column
     */
    public function enum(string $column, array $allowed): Column
    {
        return $this->addColumn('enum', $column, compact('allowed'));
    }

    /**
     * Create a new json column
     */
    public function json(string $column): Column
    {
        return $this->addColumn('json', $column);
    }

    /**
     * Create a new jsonb column
     */
    public function jsonb(string $column): Column
    {
        return $this->addColumn('jsonb', $column);
    }

    /**
     * Create a new date column
     */
    public function date(string $column): Column
    {
        return $this->addColumn('date', $column);
    }

    /**
     * Create a new date-time column
     */
    public function dateTime(string $column, int $precision = 0): Column
    {
        return $this->addColumn('datetime', $column, compact('precision'));
    }

    /**
     * Create a new date-time column with timezone
     */
    public function dateTimeTz(string $column, int $precision = 0): Column
    {
        return $this->addColumn('datetimeTz', $column, compact('precision'));
    }

    /**
     * Create a new time column
     */
    public function time(string $column, int $precision = 0): Column
    {
        return $this->addColumn('time', $column, compact('precision'));
    }

    /**
     * Create a new time column with timezone
     */
    public function timeTz(string $column, int $precision = 0): Column
    {
        return $this->addColumn('timeTz', $column, compact('precision'));
    }

    /**
     * Create a new timestamp column
     */
    public function timestamp(string $column, int $precision = 0): Column
    {
        return $this->addColumn('timestamp', $column, compact('precision'));
    }

    /**
     * Create a new timestamp column with timezone
     */
    public function timestampTz(string $column, int $precision = 0): Column
    {
        return $this->addColumn('timestampTz', $column, compact('precision'));
    }

    /**
     * Add nullable creation and update timestamps
     */
    public function timestamps(int $precision = 0): void
    {
        $this->timestamp('created_at', $precision)->nullable();
        $this->timestamp('updated_at', $precision)->nullable();
    }

    /**
     * Add nullable creation and update timestamps with timezone
     */
    public function timestampsTz(int $precision = 0): void
    {
        $this->timestampTz('created_at', $precision)->nullable();
        $this->timestampTz('updated_at', $precision)->nullable();
    }

    /**
     * Add a "deleted at" timestamp for soft deletes
     */
    public function softDeletes(string $column = 'deleted_at', int $precision = 0): Column
    {
        return $this->timestamp($column, $precision)->nullable();
    }

    /**
     * Add a "deleted at" timestamp with timezone for soft deletes
     */
    public function softDeletesTz(string $column = 'deleted_at', int $precision = 0): Column
    {
        return $this->timestampTz($column, $precision)->nullable();
    }

    /**
     * Create a new year column
     */
    public function year(string $column): Column
    {
        return $this->addColumn('year', $column);
    }

    /**
     * Create a new binary column
     */
    public function binary(string $column): Column
    {
        return $this->addColumn('binary', $column);
    }

    /**
     * Create a new uuid column
     */
    public function uuid(string $column): Column
    {
        return $this->addColumn('uuid', $column);
    }

    /**
     * Create a new IP address column
     */
    public function ipAddress(string $column): Column
    {
        return $this->addColumn('ipAddress', $column);
    }

    /**
     * Create a new MAC address column
     */
    public function macAddress(string $column): Column
    {
        return $this->addColumn('macAddress', $column);
    }

    /**
     * Create a new geometry column
     */
    public function geometry(string $column): Column
    {
        return $this->addColumn('geometry', $column);
    }

    /**
     * Create a new point column
     */
    public function point(string $column): Column
    {
        return $this->addColumn('point', $column);
    }

    /**
     * Create a new linestring column
     */
    public function lineString(string $column): Column
    {
        return $this->addColumn('lineString', $column);
    }

    /**
     * Create a new polygon column
     */
    public function polygon(string $column): Column
    {
        return $this->addColumn('polygon', $column);
    }

    /**
     * Add a new column to the blueprint
     */
    protected function addColumn(string $type, string $name, array $parameters = []): Column
    {
        $column = new Column(array_merge(compact('type', 'name'), $parameters));

        $this->columns[] = $column;

        return $column;
    }

    /**
     * Add a new command to the blueprint
     */
    public function addCommand(string $name, array $parameters = []): void
    {
        $this->commands[] = array_merge(compact('name'), $parameters);
    }

    /**
     * Create a default index name for the table
     */
    protected function createIndexName(string $type, array $columns): string
    {
        $index = strtolower($this->table . '_' . implode('_', $columns) . '_' . $type);

        return str_replace(['-', '.'], '_', $index);
    }

    /**
     * Get the table the blueprint describes
     */
    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * Get the columns on the blueprint
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /**
     * Get the commands on the blueprint
     */
    public function getCommands(): array
    {
        return $this->commands;
    }
}
