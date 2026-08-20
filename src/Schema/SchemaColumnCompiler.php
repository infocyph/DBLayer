<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Schema;

use Infocyph\DBLayer\Exceptions\SchemaException;
use Infocyph\DBLayer\Query\Expression;
use Infocyph\DBLayer\Schema\Concerns\CompilesColumnDefinitions;
use Infocyph\DBLayer\Schema\Dialect\SchemaDialect;
use Infocyph\DBLayer\Schema\Dialect\SchemaDialectRegistry;

final readonly class SchemaColumnCompiler
{
    use CompilesColumnDefinitions;

    private SchemaDialect $dialect;

    public function __construct(string|SchemaDialect $dialect)
    {
        $this->dialect = is_string($dialect) ? SchemaDialectRegistry::resolve($dialect) : $dialect;
    }

    public function compileType(ColumnDefinition $column): string
    {
        if ($column->autoIncrement && $this->dialect->isPostgreSql()) {
            return $this->serialType($column);
        }

        return match ($column->type) {
            'bigInteger', 'integer', 'mediumInteger', 'smallInteger', 'tinyInteger' => $this->integerType($column),
            'binary' => $this->binaryType(),
            'boolean' => match (true) {
                $this->dialect->isMySqlFamily() => 'TINYINT(1)', $this->dialect->isSqlServer() => 'BIT', default => 'BOOLEAN'
            },
            'char', 'longText', 'mediumText', 'string', 'text', 'tinyText' => $this->stringType($column),
            'date' => 'DATE',
            'dateTime', 'dateTimeTz', 'time', 'timeTz', 'timestamp', 'timestampTz' => $this->temporalColumnType($column),
            'decimal', 'double', 'float' => $this->numericType($column),
            'enum' => $this->choiceType($column, false), 'geography' => $this->spatialType($column, true), 'geometry' => $this->spatialType($column, false),
            'ipAddress', 'macAddress' => $this->networkType($column), 'json', 'jsonb' => $this->jsonType($column), 'set' => $this->choiceType($column, true),
            'ulid' => $this->dialect->isSqlite() ? 'TEXT' : 'CHAR(26)', 'uuid' => $this->uuidType(), 'vector' => $this->vectorType($column), 'year' => $this->yearType(),
            default => throw SchemaException::invalid(sprintf('Unknown column type "%s".', $column->type)),
        };
    }

    private function binaryType(): string
    {
        return match (true) {
            $this->dialect->isPostgreSql() => 'BYTEA', $this->dialect->isSqlServer() => 'VARBINARY(MAX)', default => 'BLOB'
        };
    }

    private function booleanDefault(bool $value): string
    {
        return $this->dialect->isPostgreSql() ? ($value ? 'TRUE' : 'FALSE') : ($value ? '1' : '0');
    }

    private function choiceConstraint(ColumnDefinition $column): string
    {
        if ($column->type !== 'enum' || $this->dialect->isMySqlFamily()) {
            return '';
        }

        return sprintf(' CHECK (%s IN (%s))', $this->wrap($column->name), $this->quoteChoices($column));
    }

    private function choiceType(ColumnDefinition $column, bool $set): string
    {
        if ($column->allowedValues === []) {
            throw SchemaException::invalid('Choice columns require at least one allowed value.');
        }
        if ($set && !$this->dialect->isMySqlFamily()) {
            throw SchemaException::unsupported($this->dialect->name(), 'set column');
        }
        if ($this->dialect->isMySqlFamily()) {
            return sprintf('%s(%s)', $set ? 'SET' : 'ENUM', $this->quoteChoices($column));
        }

        return match (true) {
            $this->dialect->isSqlite() => 'TEXT', $this->dialect->isSqlServer() => 'NVARCHAR(255)', default => 'VARCHAR(255)'
        };
    }

    private function currentOnUpdate(ColumnDefinition $column): string
    {
        if (!$column->useCurrentOnUpdate) {
            return '';
        }
        if (!$this->dialect->isMySqlFamily()) {
            throw SchemaException::unsupported($this->dialect->name(), 'CURRENT_TIMESTAMP on update');
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
        if ($this->dialect->isPostgreSql() && $column->generatedStorage === 'virtual') {
            throw SchemaException::unsupported($this->dialect->name(), 'virtual generated column');
        }
        if ($this->dialect->isSqlServer()) {
            return sprintf(' AS (%s)%s', $column->generatedExpression->getValue(), $column->generatedStorage === 'stored' ? ' PERSISTED' : '');
        }

        return sprintf(' GENERATED ALWAYS AS (%s) %s', $column->generatedExpression->getValue(), strtoupper($column->generatedStorage));
    }

    private function integerType(ColumnDefinition $column): string
    {
        if ($this->dialect->isSqlite()) {
            return 'INTEGER';
        }

        return match ($column->type) {
            'bigInteger' => 'BIGINT', 'mediumInteger' => $this->dialect->isMySqlFamily() ? 'MEDIUMINT' : 'INTEGER', 'smallInteger' => 'SMALLINT', 'tinyInteger' => ($this->dialect->isMySqlFamily() || $this->dialect->isSqlServer()) ? 'TINYINT' : 'SMALLINT', default => 'INTEGER'
        };
    }

    private function isSqliteAutoIncrement(ColumnDefinition $column): bool
    {
        if (!$column->autoIncrement || !$this->dialect->isSqlite()) {
            return false;
        }
        if (!$column->primary) {
            throw SchemaException::invalid('SQLite AUTOINCREMENT requires an integer primary key.');
        }

        return true;
    }

    private function jsonType(ColumnDefinition $column): string
    {
        if ($this->dialect->isSqlite()) {
            return 'TEXT';
        }
        if ($this->dialect->isSqlServer()) {
            return 'NVARCHAR(MAX)';
        }

        return $column->type === 'jsonb' && $this->dialect->isPostgreSql() ? 'JSONB' : 'JSON';
    }

    private function networkType(ColumnDefinition $column): string
    {
        if ($this->dialect->isPostgreSql()) {
            return $column->type === 'ipAddress' ? 'INET' : 'MACADDR';
        }
        if ($this->dialect->isSqlite()) {
            return 'TEXT';
        }
        if ($this->dialect->isSqlServer()) {
            return $column->type === 'ipAddress' ? 'NVARCHAR(45)' : 'NVARCHAR(17)';
        }

        return $column->type === 'ipAddress' ? 'VARCHAR(45)' : 'VARCHAR(17)';
    }

    private function numericType(ColumnDefinition $column): string
    {
        if ($column->type === 'decimal') {
            return sprintf('DECIMAL(%d, %d)', $column->precision ?? throw SchemaException::invalid('Decimal precision is required.'), $column->scale ?? throw SchemaException::invalid('Decimal scale is required.'));
        }
        if ($this->dialect->isSqlite()) {
            return 'REAL';
        }
        if ($this->dialect->isSqlServer()) {
            return 'FLOAT';
        }

        return $column->type === 'double' && $this->dialect->isPostgreSql() ? 'DOUBLE PRECISION' : strtoupper($column->type);
    }

    private function quoteChoices(ColumnDefinition $column): string
    {
        return implode(', ', array_map(static fn(string $value): string => "'" . str_replace("'", "''", $value) . "'", $column->allowedValues));
    }

    private function quoteDefault(mixed $value): string
    {
        return match (true) {
            $value === null => 'NULL', is_bool($value) => $this->booleanDefault($value), is_int($value) => (string) $value, is_float($value) && is_finite($value) => (string) $value, is_string($value) => "'" . str_replace("'", "''", $value) . "'", $value instanceof Expression => $value->getValue(), default => throw SchemaException::invalid('Schema defaults must be null, boolean, finite numeric, string, or Expression values.')
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
            'bigInteger' => 'BIGSERIAL', 'smallInteger', 'tinyInteger' => 'SMALLSERIAL', default => 'SERIAL'
        };
    }

    private function spatialType(ColumnDefinition $column, bool $geography): string
    {
        if ($this->dialect->isSqlite() || ($geography && $this->dialect->isMySqlFamily())) {
            throw SchemaException::unsupported($this->dialect->name(), $column->type . ' column');
        }
        $type = strtoupper($column->spatialSubtype ?? 'geometry');
        $srid = $column->srid ?? 0;
        if ($this->dialect->isMySqlFamily()) {
            return $type . ($srid > 0 ? ' SRID ' . $srid : '');
        }
        if ($this->dialect->isSqlServer()) {
            return $geography ? 'GEOGRAPHY' : 'GEOMETRY';
        }

        return sprintf('%s(%s, %d)', $geography ? 'GEOGRAPHY' : 'GEOMETRY', $type, $srid);
    }

    private function stringType(ColumnDefinition $column): string
    {
        if ($this->dialect->isSqlite()) {
            return 'TEXT';
        }
        if ($this->dialect->isSqlServer()) {
            return match ($column->type) {
                'char' => sprintf('NCHAR(%d)', $column->length ?? throw SchemaException::invalid('Character length is required.')), 'string' => sprintf('NVARCHAR(%d)', $column->length ?? throw SchemaException::invalid('String length is required.')), default => 'NVARCHAR(MAX)'
            };
        }

        return match ($column->type) {
            'char' => sprintf('CHAR(%d)', $column->length ?? throw SchemaException::invalid('Character length is required.')), 'longText' => $this->dialect->isMySqlFamily() ? 'LONGTEXT' : 'TEXT', 'mediumText' => $this->dialect->isMySqlFamily() ? 'MEDIUMTEXT' : 'TEXT', 'string' => sprintf('VARCHAR(%d)', $column->length ?? throw SchemaException::invalid('String length is required.')), 'tinyText' => $this->dialect->isMySqlFamily() ? 'TINYTEXT' : 'TEXT', default => 'TEXT'
        };
    }

    private function temporalColumnType(ColumnDefinition $column): string
    {
        [$mysql, $pgsql] = match ($column->type) {
            'dateTime' => ['DATETIME', 'TIMESTAMP WITHOUT TIME ZONE'], 'dateTimeTz' => ['DATETIME', 'TIMESTAMP WITH TIME ZONE'], 'time' => ['TIME', 'TIME WITHOUT TIME ZONE'], 'timeTz' => ['TIME', 'TIME WITH TIME ZONE'], 'timestampTz' => ['TIMESTAMP', 'TIMESTAMP WITH TIME ZONE'], default => ['TIMESTAMP', 'TIMESTAMP WITHOUT TIME ZONE']
        };

        return $this->temporalType($mysql, $pgsql, $column);
    }

    private function temporalType(string $mysql, string $pgsql, ColumnDefinition $column): string
    {
        if ($this->dialect->isSqlite()) {
            return 'TEXT';
        }
        $precision = ($column->precision ?? 0) > 0 ? '(' . $column->precision . ')' : '';
        if ($this->dialect->isSqlServer()) {
            return match ($column->type) {
                'dateTime', 'timestamp' => 'DATETIME2' . $precision, 'dateTimeTz', 'timestampTz' => 'DATETIMEOFFSET' . $precision, 'time' => 'TIME' . $precision, 'timeTz' => throw SchemaException::unsupported($this->dialect->name(), 'time with timezone column'), default => 'DATETIME2' . $precision
            };
        }
        if ($this->dialect->isMySqlFamily()) {
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
        return $column->unsigned && $this->dialect->isMySqlFamily() ? ' UNSIGNED' : '';
    }

    private function uuidType(): string
    {
        return match (true) {
            $this->dialect->isPostgreSql() => 'UUID', $this->dialect->isMySqlFamily() => 'CHAR(36)', $this->dialect->isSqlServer() => 'UNIQUEIDENTIFIER', default => 'TEXT'
        };
    }

    private function validateAutoIncrement(ColumnDefinition $column): void
    {
        if ($column->autoIncrement && !in_array($column->type, ['tinyInteger', 'smallInteger', 'mediumInteger', 'integer', 'bigInteger'], true)) {
            throw SchemaException::invalid('Auto increment is supported only for integer columns.');
        }
    }

    private function validateTemporalModifiers(ColumnDefinition $column): void
    {
        if (($column->useCurrent || $column->useCurrentOnUpdate) && !in_array($column->type, ['dateTime', 'dateTimeTz', 'timestamp', 'timestampTz'], true)) {
            throw SchemaException::invalid('Current timestamp modifiers require a date-time or timestamp column.');
        }
    }

    private function vectorType(ColumnDefinition $column): string
    {
        if (!$this->dialect->isPostgreSql()) {
            throw SchemaException::unsupported($this->dialect->name(), 'vector column');
        }

        return sprintf('VECTOR(%d)', $column->dimensions ?? throw SchemaException::invalid('Vector dimensions are required.'));
    }

    private function wrap(string $identifier): string
    {
        return $this->dialect->wrap($identifier);
    }

    private function yearType(): string
    {
        return match (true) {
            $this->dialect->isMySqlFamily() => 'YEAR', $this->dialect->isPostgreSql(), $this->dialect->isSqlServer() => 'SMALLINT', default => 'INTEGER'
        };
    }
}
