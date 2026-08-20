<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Schema\Concerns;

use Infocyph\DBLayer\Exceptions\SchemaException;
use Infocyph\DBLayer\Schema\ColumnDefinition;

trait CompilesColumnDefinitions
{
    public function compile(ColumnDefinition $column): string
    {
        $this->validateAutoIncrement($column);
        $this->validateTemporalModifiers($column);
        if ($this->isSqliteAutoIncrement($column)) {
            return $this->wrap($column->name) . ' INTEGER PRIMARY KEY AUTOINCREMENT';
        }
        if ($this->dialect->isSqlServer() && $column->generatedExpression !== null) {
            return $this->wrap($column->name) . $this->generated($column);
        }
        $sql = $this->wrap($column->name) . ' ' . $this->compileType($column) . $this->unsigned($column)
            . ($column->autoIncrement && $this->dialect->isSqlServer() ? ' IDENTITY(1,1)' : '') . $this->choiceConstraint($column);
        if ($column->generatedExpression !== null) {
            return $sql . $this->generated($column);
        }

        return $sql . ($column->nullable ? ' NULL' : ' NOT NULL') . $this->defaultValue($column) . $this->currentOnUpdate($column)
            . ($column->autoIncrement && $this->dialect->isMySqlFamily() ? ' AUTO_INCREMENT' : '')
            . ($column->primary && !$this->dialect->isSqlServer() ? ' PRIMARY KEY' : '');
    }

    /** @return non-empty-list<string> */
    public function compileChange(string $wrappedTable, ColumnDefinition $column): array
    {
        if ($this->dialect->isSqlite()) {
            throw SchemaException::unsupported($this->dialect->name(), 'change column');
        }
        if ($this->dialect->isMySqlFamily()) {
            return [sprintf('ALTER TABLE %s MODIFY COLUMN %s', $wrappedTable, $this->compile($column))];
        }
        if ($this->dialect->isSqlServer()) {
            if ($column->autoIncrement || $column->generatedExpression !== null || $column->hasDefault || $column->useCurrent) {
                throw SchemaException::unsupported($this->dialect->name(), 'change identity/generated/default column');
            }

            return [sprintf(
                'ALTER TABLE %s ALTER COLUMN %s %s %s',
                $wrappedTable,
                $this->wrap($column->name),
                $this->compileType($column),
                $column->nullable ? 'NULL' : 'NOT NULL',
            )];
        }
        if ($column->autoIncrement || $column->generatedExpression !== null) {
            throw SchemaException::unsupported($this->dialect->name(), 'change auto-increment or generated column');
        }

        $wrappedColumn = $this->wrap($column->name);
        $statements = [
            sprintf('ALTER TABLE %s ALTER COLUMN %s TYPE %s', $wrappedTable, $wrappedColumn, $this->compileType($column)),
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
}
