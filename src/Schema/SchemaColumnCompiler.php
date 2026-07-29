<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Schema;

use Infocyph\DBLayer\Exceptions\SchemaException;
use Infocyph\DBLayer\Query\Expression;

/**
 * Compiles one validated column definition for a selected SQL dialect.
 */
final readonly class SchemaColumnCompiler
{
    public function __construct(private string $driver) {}

    public function compile(ColumnDefinition $column): string
    {
        $this->validateAutoIncrement($column);
        $this->validateTemporalModifiers($column);

        if ($this->isSqliteAutoIncrement($column)) {
            return $this->wrap($column->name) . ' INTEGER PRIMARY KEY AUTOINCREMENT';
        }

        $sql = $this->wrap($column->name)
            . ' ' . $this->compileType($column)
            . $this->unsigned($column)
            . $this->choiceConstraint($column);

        if ($column->generatedExpression !== null) {
            return $sql . $this->generated($column);
        }

        return $sql
            . ($column->nullable ? ' NULL' : ' NOT NULL')
            . $this->defaultValue($column)
            . $this->currentOnUpdate($column)
            . ($column->autoIncrement && $this->driver === 'mysql' ? ' AUTO_INCREMENT' : '')
            . ($column->primary ? ' PRIMARY KEY' : '');
    }

    /** @return non-empty-list<string> */
    public function compileChange(string $wrappedTable, ColumnDefinition $column): array
    {
        if ($this->driver === 'sqlite') {
            throw SchemaException::unsupported('sqlite', 'change column');
        }

        if ($this->driver === 'mysql') {
            return [sprintf('ALTER TABLE %s MODIFY COLUMN %s', $wrappedTable, $this->compile($column))];
        }

        if ($column->autoIncrement || $column->generatedExpression !== null) {
            throw SchemaException::unsupported('pgsql', 'change auto-increment or generated column');
        }

        $wrappedColumn = $this->wrap($column->name);
        $statements = [
            sprintf(
                'ALTER TABLE %s ALTER COLUMN %s TYPE %s',
                $wrappedTable,
                $wrappedColumn,
                $this->compileType($column),
            ),
            sprintf(
                'ALTER TABLE %s ALTER COLUMN %s %s NOT NULL',
                $wrappedTable,
                $wrappedColumn,
                $column->nullable ? 'DROP' : 'SET',
            ),
        ];
        $default = $this->resolvedDefault($column);
        $statements[] = $default === null
            ? sprintf('ALTER TABLE %s ALTER COLUMN %s DROP DEFAULT', $wrappedTable, $wrappedColumn)
            : sprintf('ALTER TABLE %s ALTER COLUMN %s SET DEFAULT %s', $wrappedTable, $wrappedColumn, $default);

        return $statements;
    }

    public function compileType(ColumnDefinition $column): string
    {
        if ($column->autoIncrement && $this->driver === 'pgsql') {
            return $this->serialType($column);
        }

        return match ($column->type) {
            'bigInteger', 'integer', 'mediumInteger', 'smallInteger', 'tinyInteger' => $this->integerType($column),
            'binary' => $this->binaryType(),
            'boolean' => $this->driver === 'mysql' ? 'TINYINT(1)' : 'BOOLEAN',
            'char', 'longText', 'mediumText', 'string', 'text', 'tinyText' => $this->stringType($column),
            'date' => 'DATE',
            'dateTime', 'dateTimeTz', 'time', 'timeTz', 'timestamp', 'timestampTz' => $this->temporalColumnType($column),
            'decimal', 'double', 'float' => $this->numericType($column),
            'enum' => $this->choiceType($column, false),
            'geography' => $this->spatialType($column, true),
            'geometry' => $this->spatialType($column, false),
            'ipAddress', 'macAddress' => $this->networkType($column),
            'json', 'jsonb' => $this->jsonType($column),
            'set' => $this->choiceType($column, true),
            'ulid' => $this->driver === 'sqlite' ? 'TEXT' : 'CHAR(26)',
            'uuid' => $this->uuidType(),
            'vector' => $this->vectorType($column),
            'year' => $this->yearType(),
            default => throw SchemaException::invalid(sprintf('Unknown column type "%s".', $column->type)),
        };
    }

    private function binaryType(): string
    {
        return $this->driver === 'pgsql' ? 'BYTEA' : 'BLOB';
    }

    private function booleanDefault(bool $value): string
    {
        if ($this->driver === 'pgsql') {
            return $value ? 'TRUE' : 'FALSE';
        }

        return $value ? '1' : '0';
    }

    private function choiceConstraint(ColumnDefinition $column): string
    {
        if ($column->type !== 'enum' || $this->driver === 'mysql') {
            return '';
        }

        return sprintf(
            ' CHECK (%s IN (%s))',
            $this->wrap($column->name),
            $this->quoteChoices($column),
        );
    }

    private function choiceType(ColumnDefinition $column, bool $set): string
    {
        if ($column->allowedValues === []) {
            throw SchemaException::invalid('Choice columns require at least one allowed value.');
        }

        if ($set && $this->driver !== 'mysql') {
            throw SchemaException::unsupported($this->driver, 'set column');
        }

        if ($this->driver === 'mysql') {
            return sprintf('%s(%s)', $set ? 'SET' : 'ENUM', $this->quoteChoices($column));
        }

        return $this->driver === 'sqlite' ? 'TEXT' : 'VARCHAR(255)';
    }

    private function currentOnUpdate(ColumnDefinition $column): string
    {
        if (!$column->useCurrentOnUpdate) {
            return '';
        }

        if ($this->driver !== 'mysql') {
            throw SchemaException::unsupported($this->driver, 'CURRENT_TIMESTAMP on update');
        }

        return ' ON UPDATE CURRENT_TIMESTAMP';
    }

    private function defaultValue(ColumnDefinition $column): string
    {
        $default = $this->resolvedDefault($column);

        return $default === null ? '' : ' DEFAULT ' . $default;
    }

    private function generated(ColumnDefinition $column): string
    {
        if ($column->generatedExpression === null || $column->generatedStorage === null) {
            return '';
        }

        if ($this->driver === 'pgsql' && $column->generatedStorage === 'virtual') {
            throw SchemaException::unsupported('pgsql', 'virtual generated column');
        }

        return sprintf(
            ' GENERATED ALWAYS AS (%s) %s',
            $column->generatedExpression->getValue(),
            strtoupper($column->generatedStorage),
        );
    }

    private function integerType(ColumnDefinition $column): string
    {
        if ($this->driver === 'sqlite') {
            return 'INTEGER';
        }

        return match ($column->type) {
            'bigInteger' => 'BIGINT',
            'mediumInteger' => $this->driver === 'mysql' ? 'MEDIUMINT' : 'INTEGER',
            'smallInteger' => 'SMALLINT',
            'tinyInteger' => $this->driver === 'mysql' ? 'TINYINT' : 'SMALLINT',
            default => 'INTEGER',
        };
    }

    private function isSqliteAutoIncrement(ColumnDefinition $column): bool
    {
        if (!$column->autoIncrement || $this->driver !== 'sqlite') {
            return false;
        }

        if (!$column->primary) {
            throw SchemaException::invalid('SQLite AUTOINCREMENT requires an integer primary key.');
        }

        return true;
    }

    private function jsonType(ColumnDefinition $column): string
    {
        if ($this->driver === 'sqlite') {
            return 'TEXT';
        }

        return $column->type === 'jsonb' && $this->driver === 'pgsql' ? 'JSONB' : 'JSON';
    }

    private function networkType(ColumnDefinition $column): string
    {
        if ($this->driver === 'pgsql') {
            return $column->type === 'ipAddress' ? 'INET' : 'MACADDR';
        }

        if ($this->driver === 'sqlite') {
            return 'TEXT';
        }

        return $column->type === 'ipAddress' ? 'VARCHAR(45)' : 'VARCHAR(17)';
    }

    private function numericType(ColumnDefinition $column): string
    {
        if ($column->type === 'decimal') {
            return sprintf(
                'DECIMAL(%d, %d)',
                $column->precision ?? throw SchemaException::invalid('Decimal precision is required.'),
                $column->scale ?? throw SchemaException::invalid('Decimal scale is required.'),
            );
        }

        if ($this->driver === 'sqlite') {
            return 'REAL';
        }

        return $column->type === 'double' && $this->driver === 'pgsql' ? 'DOUBLE PRECISION' : strtoupper($column->type);
    }

    private function quoteChoices(ColumnDefinition $column): string
    {
        return implode(', ', array_map(
            static fn(string $value): string => "'" . str_replace("'", "''", $value) . "'",
            $column->allowedValues,
        ));
    }

    private function quoteDefault(mixed $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $this->booleanDefault($value),
            is_int($value) => (string) $value,
            is_float($value) && is_finite($value) => (string) $value,
            is_string($value) => "'" . str_replace("'", "''", $value) . "'",
            $value instanceof Expression => $value->getValue(),
            default => throw SchemaException::invalid(
                'Schema defaults must be null, boolean, finite numeric, string, or Expression values.',
            ),
        };
    }

    private function resolvedDefault(ColumnDefinition $column): ?string
    {
        if ($column->useCurrent) {
            return 'CURRENT_TIMESTAMP';
        }

        return $column->hasDefault ? $this->quoteDefault($column->default) : null;
    }

    private function serialType(ColumnDefinition $column): string
    {
        return match ($column->type) {
            'bigInteger' => 'BIGSERIAL',
            'smallInteger', 'tinyInteger' => 'SMALLSERIAL',
            default => 'SERIAL',
        };
    }

    private function spatialType(ColumnDefinition $column, bool $geography): string
    {
        if ($this->driver === 'sqlite' || ($geography && $this->driver === 'mysql')) {
            throw SchemaException::unsupported($this->driver, $column->type . ' column');
        }

        $type = strtoupper($column->spatialSubtype ?? 'geometry');
        $srid = $column->srid ?? 0;

        if ($this->driver === 'mysql') {
            return $type . ($srid > 0 ? ' SRID ' . $srid : '');
        }

        return sprintf('%s(%s, %d)', $geography ? 'GEOGRAPHY' : 'GEOMETRY', $type, $srid);
    }

    private function stringType(ColumnDefinition $column): string
    {
        if ($this->driver === 'sqlite') {
            return 'TEXT';
        }

        return match ($column->type) {
            'char' => sprintf(
                'CHAR(%d)',
                $column->length ?? throw SchemaException::invalid('Character length is required.'),
            ),
            'longText' => $this->driver === 'mysql' ? 'LONGTEXT' : 'TEXT',
            'mediumText' => $this->driver === 'mysql' ? 'MEDIUMTEXT' : 'TEXT',
            'string' => sprintf(
                'VARCHAR(%d)',
                $column->length ?? throw SchemaException::invalid('String length is required.'),
            ),
            'tinyText' => $this->driver === 'mysql' ? 'TINYTEXT' : 'TEXT',
            default => 'TEXT',
        };
    }

    private function temporalColumnType(ColumnDefinition $column): string
    {
        [$mysql, $pgsql] = match ($column->type) {
            'dateTime' => ['DATETIME', 'TIMESTAMP WITHOUT TIME ZONE'],
            'dateTimeTz' => ['DATETIME', 'TIMESTAMP WITH TIME ZONE'],
            'time' => ['TIME', 'TIME WITHOUT TIME ZONE'],
            'timeTz' => ['TIME', 'TIME WITH TIME ZONE'],
            'timestampTz' => ['TIMESTAMP', 'TIMESTAMP WITH TIME ZONE'],
            default => ['TIMESTAMP', 'TIMESTAMP WITHOUT TIME ZONE'],
        };

        return $this->temporalType($mysql, $pgsql, $column);
    }

    private function temporalType(string $mysql, string $pgsql, ColumnDefinition $column): string
    {
        if ($this->driver === 'sqlite') {
            return 'TEXT';
        }

        $precision = ($column->precision ?? 0) > 0 ? '(' . $column->precision . ')' : '';
        if ($this->driver === 'mysql') {
            return $mysql . $precision;
        }

        $separator = strpos($pgsql, ' ');
        if ($separator === false) {
            return $pgsql . $precision;
        }

        return substr($pgsql, 0, $separator) . $precision . substr($pgsql, $separator);
    }

    private function unsigned(ColumnDefinition $column): string
    {
        return $column->unsigned && $this->driver === 'mysql' ? ' UNSIGNED' : '';
    }

    private function uuidType(): string
    {
        return match ($this->driver) {
            'pgsql' => 'UUID',
            'mysql' => 'CHAR(36)',
            default => 'TEXT',
        };
    }

    private function validateAutoIncrement(ColumnDefinition $column): void
    {
        if ($column->autoIncrement && !in_array(
            $column->type,
            ['tinyInteger', 'smallInteger', 'mediumInteger', 'integer', 'bigInteger'],
            true,
        )) {
            throw SchemaException::invalid('Auto increment is supported only for integer columns.');
        }
    }

    private function validateTemporalModifiers(ColumnDefinition $column): void
    {
        if (
            ($column->useCurrent || $column->useCurrentOnUpdate)
            && !in_array($column->type, ['dateTime', 'dateTimeTz', 'timestamp', 'timestampTz'], true)
        ) {
            throw SchemaException::invalid('Current timestamp modifiers require a date-time or timestamp column.');
        }
    }

    private function vectorType(ColumnDefinition $column): string
    {
        if ($this->driver !== 'pgsql') {
            throw SchemaException::unsupported($this->driver, 'vector column');
        }

        return sprintf(
            'VECTOR(%d)',
            $column->dimensions ?? throw SchemaException::invalid('Vector dimensions are required.'),
        );
    }

    private function wrap(string $identifier): string
    {
        Blueprint::assertIdentifier($identifier);
        $quote = $this->driver === 'mysql' ? '`' : '"';

        return $quote . $identifier . $quote;
    }

    private function yearType(): string
    {
        return match ($this->driver) {
            'mysql' => 'YEAR',
            'pgsql' => 'SMALLINT',
            default => 'INTEGER',
        };
    }
}
