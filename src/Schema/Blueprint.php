<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Schema;

use Infocyph\DBLayer\Exceptions\SchemaException;

final class Blueprint
{
    /** @var list<ColumnDefinition> */
    private array $columns = [];

    /** @var list<array{type:string,from?:string,to?:string,name?:string}> */
    private array $commands = [];

    /** @var list<ForeignKeyDefinition> */
    private array $foreignKeys = [];

    /** @var list<array{type:string,columns:list<string>,name:?string}> */
    private array $indexes = [];

    public function __construct(
        public readonly string $table,
        public readonly bool $creating = false,
    ) {
        self::assertIdentifier($table);
    }

    public static function assertIdentifier(string $identifier): void
    {
        foreach (explode('.', $identifier) as $part) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $part) !== 1) {
                throw SchemaException::invalid(sprintf('Invalid schema identifier "%s".', $identifier));
            }
        }
    }

    public function bigIncrements(string $name = 'id'): ColumnDefinition
    {
        return $this->bigInteger($name)->unsigned()->autoIncrement()->primary();
    }

    public function bigInteger(string $name): ColumnDefinition
    {
        return $this->addColumn('bigInteger', $name);
    }

    public function binary(string $name): ColumnDefinition
    {
        return $this->addColumn('binary', $name);
    }

    public function boolean(string $name): ColumnDefinition
    {
        return $this->addColumn('boolean', $name);
    }

    public function char(string $name, int $length = 1): ColumnDefinition
    {
        $this->assertPositive($length, 'Character length');

        return $this->addColumn('char', $name, $length);
    }

    /** @return list<ColumnDefinition> */
    public function columns(): array
    {
        return $this->columns;
    }

    /** @return list<array{type:string,from?:string,to?:string,name?:string}> */
    public function commands(): array
    {
        return $this->commands;
    }

    public function date(string $name): ColumnDefinition
    {
        return $this->addColumn('date', $name);
    }

    public function dateTime(string $name, int $precision = 0): ColumnDefinition
    {
        $this->assertTemporalPrecision($precision);

        return $this->addColumn('dateTime', $name, precision: $precision);
    }

    public function dateTimeTz(string $name, int $precision = 0): ColumnDefinition
    {
        $this->assertTemporalPrecision($precision);

        return $this->addColumn('dateTimeTz', $name, precision: $precision);
    }

    public function decimal(string $name, int $precision = 8, int $scale = 2): ColumnDefinition
    {
        if ($precision < 1 || $scale < 0 || $scale > $precision) {
            throw SchemaException::invalid('Decimal precision must be positive and scale must be between zero and precision.');
        }

        return $this->addColumn('decimal', $name, precision: $precision, scale: $scale);
    }

    public function double(string $name): ColumnDefinition
    {
        return $this->addColumn('double', $name);
    }

    public function dropColumn(string ...$columns): void
    {
        if ($columns === []) {
            throw SchemaException::invalid('At least one column is required for dropColumn().');
        }

        foreach ($columns as $column) {
            self::assertIdentifier($column);
            $this->commands[] = ['type' => 'dropColumn', 'name' => $column];
        }
    }

    public function dropForeign(string $name): void
    {
        self::assertIdentifier($name);
        $this->commands[] = ['type' => 'dropForeign', 'name' => $name];
    }

    public function dropIndex(string $name): void
    {
        self::assertIdentifier($name);
        $this->commands[] = ['type' => 'dropIndex', 'name' => $name];
    }

    public function dropPrimary(?string $name = null): void
    {
        if ($name !== null) {
            self::assertIdentifier($name);
        }

        $command = ['type' => 'dropPrimary'];
        if ($name !== null) {
            $command['name'] = $name;
        }

        $this->commands[] = $command;
    }

    public function dropRememberToken(): void
    {
        $this->dropColumn('remember_token');
    }

    public function dropSoftDeletes(string $name = 'deleted_at'): void
    {
        $this->dropColumn($name);
    }

    public function dropTimestamps(): void
    {
        $this->dropColumn('created_at', 'updated_at');
    }

    public function dropUnique(string $name): void
    {
        self::assertIdentifier($name);
        $this->commands[] = ['type' => 'dropUnique', 'name' => $name];
    }

    /** @param list<string> $allowed */
    public function enum(string $name, array $allowed): ColumnDefinition
    {
        return $this->addChoiceColumn('enum', $name, $allowed);
    }

    public function float(string $name): ColumnDefinition
    {
        return $this->addColumn('float', $name);
    }

    /** @param string|list<string> $columns */
    public function foreign(string|array $columns, ?string $name = null): ForeignKeyDefinition
    {
        $resolved = is_array($columns) ? $columns : [$columns];
        $this->assertIdentifiers($resolved);

        if ($resolved === []) {
            throw SchemaException::invalid('A foreign key requires at least one column.');
        }

        $foreign = new ForeignKeyDefinition($resolved);
        $foreign->name = $name;
        $this->foreignKeys[] = $foreign;

        return $foreign;
    }

    public function foreignId(string $name): ColumnDefinition
    {
        return $this->unsignedBigInteger($name);
    }

    /** @return list<ForeignKeyDefinition> */
    public function foreignKeys(): array
    {
        return $this->foreignKeys;
    }

    public function foreignUlid(string $name): ColumnDefinition
    {
        return $this->ulid($name);
    }

    public function foreignUuid(string $name): ColumnDefinition
    {
        return $this->uuid($name);
    }

    public function geography(string $name, string $subtype = 'geometry', int $srid = 4326): ColumnDefinition
    {
        return $this->addSpatialColumn('geography', $name, $subtype, $srid);
    }

    public function geometry(string $name, string $subtype = 'geometry', int $srid = 0): ColumnDefinition
    {
        return $this->addSpatialColumn('geometry', $name, $subtype, $srid);
    }

    public function id(string $name = 'id'): ColumnDefinition
    {
        return $this->bigIncrements($name);
    }

    public function increments(string $name = 'id'): ColumnDefinition
    {
        return $this->integer($name)->unsigned()->autoIncrement()->primary();
    }

    /** @param string|list<string> $columns */
    public function index(string|array $columns, ?string $name = null): void
    {
        $this->addIndex('index', $columns, $name);
    }

    /** @return list<array{type:string,columns:list<string>,name:?string}> */
    public function indexes(): array
    {
        return $this->indexes;
    }

    public function integer(string $name): ColumnDefinition
    {
        return $this->addColumn('integer', $name);
    }

    public function ipAddress(string $name): ColumnDefinition
    {
        return $this->addColumn('ipAddress', $name);
    }

    public function json(string $name): ColumnDefinition
    {
        return $this->addColumn('json', $name);
    }

    public function jsonb(string $name): ColumnDefinition
    {
        return $this->addColumn('jsonb', $name);
    }

    public function longText(string $name): ColumnDefinition
    {
        return $this->addColumn('longText', $name);
    }

    public function macAddress(string $name): ColumnDefinition
    {
        return $this->addColumn('macAddress', $name);
    }

    public function mediumIncrements(string $name = 'id'): ColumnDefinition
    {
        return $this->mediumInteger($name)->unsigned()->autoIncrement()->primary();
    }

    public function mediumInteger(string $name): ColumnDefinition
    {
        return $this->addColumn('mediumInteger', $name);
    }

    public function mediumText(string $name): ColumnDefinition
    {
        return $this->addColumn('mediumText', $name);
    }

    /** @param string|list<string> $columns */
    public function primary(string|array $columns, ?string $name = null): void
    {
        $this->addIndex('primary', $columns, $name);
    }

    public function rememberToken(): ColumnDefinition
    {
        return $this->string('remember_token', 100)->nullable();
    }

    public function renameColumn(string $from, string $to): void
    {
        self::assertIdentifier($from);
        self::assertIdentifier($to);
        $this->commands[] = ['type' => 'renameColumn', 'from' => $from, 'to' => $to];
    }

    public function renameIndex(string $from, string $to): void
    {
        self::assertIdentifier($from);
        self::assertIdentifier($to);
        $this->commands[] = ['type' => 'renameIndex', 'from' => $from, 'to' => $to];
    }

    /** @param list<string> $allowed */
    public function set(string $name, array $allowed): ColumnDefinition
    {
        return $this->addChoiceColumn('set', $name, $allowed);
    }

    public function smallIncrements(string $name = 'id'): ColumnDefinition
    {
        return $this->smallInteger($name)->unsigned()->autoIncrement()->primary();
    }

    public function smallInteger(string $name): ColumnDefinition
    {
        return $this->addColumn('smallInteger', $name);
    }

    public function softDeletes(string $name = 'deleted_at', int $precision = 0): ColumnDefinition
    {
        return $this->timestamp($name, $precision)->nullable();
    }

    public function softDeletesTz(string $name = 'deleted_at', int $precision = 0): ColumnDefinition
    {
        return $this->timestampTz($name, $precision)->nullable();
    }

    public function string(string $name, int $length = 255): ColumnDefinition
    {
        if ($length < 1) {
            throw SchemaException::invalid('String length must be positive.');
        }

        return $this->addColumn('string', $name, $length);
    }

    public function text(string $name): ColumnDefinition
    {
        return $this->addColumn('text', $name);
    }

    public function time(string $name, int $precision = 0): ColumnDefinition
    {
        $this->assertTemporalPrecision($precision);

        return $this->addColumn('time', $name, precision: $precision);
    }

    public function timestamp(string $name, int $precision = 0): ColumnDefinition
    {
        $this->assertTemporalPrecision($precision);

        return $this->addColumn('timestamp', $name, precision: $precision);
    }

    public function timestamps(int $precision = 0): void
    {
        $this->timestamp('created_at', $precision)->nullable();
        $this->timestamp('updated_at', $precision)->nullable();
    }

    public function timestampsTz(int $precision = 0): void
    {
        $this->timestampTz('created_at', $precision)->nullable();
        $this->timestampTz('updated_at', $precision)->nullable();
    }

    public function timestampTz(string $name, int $precision = 0): ColumnDefinition
    {
        $this->assertTemporalPrecision($precision);

        return $this->addColumn('timestampTz', $name, precision: $precision);
    }

    public function timeTz(string $name, int $precision = 0): ColumnDefinition
    {
        $this->assertTemporalPrecision($precision);

        return $this->addColumn('timeTz', $name, precision: $precision);
    }

    public function tinyIncrements(string $name = 'id'): ColumnDefinition
    {
        return $this->tinyInteger($name)->unsigned()->autoIncrement()->primary();
    }

    public function tinyInteger(string $name): ColumnDefinition
    {
        return $this->addColumn('tinyInteger', $name);
    }

    public function tinyText(string $name): ColumnDefinition
    {
        return $this->addColumn('tinyText', $name);
    }

    public function ulid(string $name): ColumnDefinition
    {
        return $this->addColumn('ulid', $name);
    }

    /** @param string|list<string> $columns */
    public function unique(string|array $columns, ?string $name = null): void
    {
        $this->addIndex('unique', $columns, $name);
    }

    public function unsignedBigInteger(string $name): ColumnDefinition
    {
        return $this->bigInteger($name)->unsigned();
    }

    public function unsignedInteger(string $name): ColumnDefinition
    {
        return $this->integer($name)->unsigned();
    }

    public function unsignedMediumInteger(string $name): ColumnDefinition
    {
        return $this->mediumInteger($name)->unsigned();
    }

    public function unsignedSmallInteger(string $name): ColumnDefinition
    {
        return $this->smallInteger($name)->unsigned();
    }

    public function unsignedTinyInteger(string $name): ColumnDefinition
    {
        return $this->tinyInteger($name)->unsigned();
    }

    public function uuid(string $name): ColumnDefinition
    {
        return $this->addColumn('uuid', $name);
    }

    public function vector(string $name, int $dimensions): ColumnDefinition
    {
        $this->assertPositive($dimensions, 'Vector dimensions');

        return $this->addColumn('vector', $name, dimensions: $dimensions);
    }

    public function year(string $name): ColumnDefinition
    {
        return $this->addColumn('year', $name);
    }

    /** @param list<string> $allowed */
    private function addChoiceColumn(string $type, string $name, array $allowed): ColumnDefinition
    {
        if ($allowed === []) {
            throw SchemaException::invalid(sprintf('%s columns require at least one allowed value.', ucfirst($type)));
        }

        foreach ($allowed as $value) {
            if ($value === '') {
                throw SchemaException::invalid(sprintf('%s values cannot be empty.', ucfirst($type)));
            }
        }

        if (count(array_unique($allowed)) !== count($allowed)) {
            throw SchemaException::invalid(sprintf('%s values must be unique.', ucfirst($type)));
        }

        return $this->addColumn($type, $name, allowedValues: $allowed);
    }

    /** @param list<string> $allowedValues */
    private function addColumn(
        string $type,
        string $name,
        ?int $length = null,
        ?int $precision = null,
        ?int $scale = null,
        array $allowedValues = [],
        ?string $spatialSubtype = null,
        ?int $srid = null,
        ?int $dimensions = null,
    ): ColumnDefinition {
        self::assertIdentifier($name);

        foreach ($this->columns as $column) {
            if ($column->name === $name) {
                throw SchemaException::invalid(sprintf('Column "%s" is already defined.', $name));
            }
        }

        $column = new ColumnDefinition(
            $name,
            $type,
            $length,
            $precision,
            $scale,
            $allowedValues,
            $spatialSubtype,
            $srid,
            $dimensions,
        );
        $this->columns[] = $column;

        return $column;
    }

    /** @param string|list<string> $columns */
    private function addIndex(string $type, string|array $columns, ?string $name): void
    {
        $resolved = is_array($columns) ? $columns : [$columns];
        $this->assertIdentifiers($resolved);

        if ($resolved === []) {
            throw SchemaException::invalid('An index requires at least one column.');
        }

        if ($name !== null) {
            self::assertIdentifier($name);
        }

        $this->indexes[] = ['type' => $type, 'columns' => $resolved, 'name' => $name];
    }

    private function addSpatialColumn(string $type, string $name, string $subtype, int $srid): ColumnDefinition
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9]*$/D', $subtype) !== 1) {
            throw SchemaException::invalid(sprintf('Invalid spatial subtype "%s".', $subtype));
        }

        if ($srid < 0) {
            throw SchemaException::invalid('A spatial reference identifier cannot be negative.');
        }

        return $this->addColumn(
            $type,
            $name,
            spatialSubtype: strtolower($subtype),
            srid: $srid,
        );
    }

    /** @param list<string> $identifiers */
    private function assertIdentifiers(array $identifiers): void
    {
        foreach ($identifiers as $identifier) {
            self::assertIdentifier($identifier);
        }
    }

    private function assertPositive(int $value, string $description): void
    {
        if ($value < 1) {
            throw SchemaException::invalid($description . ' must be positive.');
        }
    }

    private function assertTemporalPrecision(int $precision): void
    {
        if ($precision < 0 || $precision > 6) {
            throw SchemaException::invalid('Temporal precision must be between zero and six.');
        }
    }
}
