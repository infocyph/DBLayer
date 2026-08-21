<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Connection\Concerns;

use PDOStatement;

/** Normalize vendor-specific result types without changing textual columns. */
trait ConnectionResultNormalization
{
    /** @return array<array-key,mixed> */
    private function fetchAllRows(PDOStatement $statement, int $fetchMode): array
    {
        $rows = $statement->fetchAll($fetchMode);

        if ($this->getDriverName() !== 'mssql' || $rows === []) {
            return $rows;
        }

        $columns = $this->sqlServerBigIntColumns($statement);

        return array_map(fn(mixed $row): mixed => $this->normalizeSqlServerRow($row, $columns), $rows);
    }

    private function normalizeSqlServerBigInt(mixed $value): mixed
    {
        if (!is_string($value) || preg_match('/\A-?(?:0|[1-9][0-9]*)\z/', $value) !== 1) {
            return $value;
        }

        $integer = (int) $value;

        return (string) $integer === $value ? $integer : $value;
    }

    /** @param array<int,string> $columns */
    private function normalizeSqlServerRow(mixed $row, array $columns): mixed
    {
        foreach ($columns as $index => $name) {
            if (is_array($row)) {
                if (array_key_exists($index, $row)) {
                    $row[$index] = $this->normalizeSqlServerBigInt($row[$index]);
                }
                if (array_key_exists($name, $row)) {
                    $row[$name] = $this->normalizeSqlServerBigInt($row[$name]);
                }
            } elseif (is_object($row) && property_exists($row, $name)) {
                $row->{$name} = $this->normalizeSqlServerBigInt($row->{$name});
            }
        }

        return $row;
    }

    /** @return array<int,string> */
    private function sqlServerBigIntColumns(PDOStatement $statement): array
    {
        $columns = [];

        for ($index = 0; $index < $statement->columnCount(); $index++) {
            $metadata = $statement->getColumnMeta($index);
            $declaredType = is_array($metadata) ? $this->sqlServerDeclaredType($metadata) : '';
            if (preg_match('/\Abigint(?:\s|\z)/', $declaredType) !== 1) {
                continue;
            }

            $columns[$index] = $metadata['name'];
        }

        return $columns;
    }

    /** @param array<array-key,mixed> $metadata */
    private function sqlServerDeclaredType(array $metadata): string
    {
        $declaredType = $metadata['sqlsrv:decl_type'] ?? null;

        return is_string($declaredType) ? strtolower($declaredType) : '';
    }
}
