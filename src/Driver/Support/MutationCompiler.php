<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Driver\Support;

use LogicException;

/**
 * Shared SQL assembly helpers for core mutation queries.
 */
final class MutationCompiler
{
    /**
     * @param list<array<string,mixed>> $rows
     * @param callable(string):string $wrapIdentifier
     * @return array{0:string,1:list<mixed>}
     */
    public static function compileInsert(
        string $table,
        array $rows,
        callable $wrapIdentifier,
    ): array {
        if ($rows === []) {
            throw new LogicException('INSERT payload requires non-empty insertRows.');
        }

        $columns = array_keys($rows[0]);

        if ($columns === []) {
            throw new LogicException('INSERT payload requires at least one target column.');
        }

        $wrappedColumns = [];
        foreach ($columns as $column) {
            if ($column === '') {
                throw new LogicException('INSERT payload contains an empty column name.');
            }

            $wrappedColumns[] = $wrapIdentifier($column);
        }

        $placeholdersPerRow = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $valueGroups = [];
        $bindings = [];

        foreach ($rows as $row) {
            $valueGroups[] = $placeholdersPerRow;

            foreach ($columns as $column) {
                if (!array_key_exists($column, $row)) {
                    throw new LogicException(
                        sprintf('INSERT row is missing expected column [%s].', $column),
                    );
                }

                $bindings[] = $row[$column];
            }
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES %s',
            $wrapIdentifier($table),
            implode(', ', $wrappedColumns),
            implode(', ', $valueGroups),
        );

        return [$sql, $bindings];
    }

    /**
     * @param array<string,mixed> $values
     * @param list<mixed> $whereBindings
     * @param callable(string):string $wrapIdentifier
     * @return array{0:string,1:list<mixed>}
     */
    public static function compileUpdate(
        string $table,
        array $values,
        array $whereBindings,
        callable $wrapIdentifier,
    ): array {
        if ($values === []) {
            throw new LogicException('UPDATE payload requires non-empty updateValues.');
        }

        $assignments = [];
        $bindings = [];

        foreach ($values as $column => $value) {
            if ($column === '') {
                throw new LogicException('UPDATE payload contains an empty column name.');
            }

            $assignments[] = $wrapIdentifier($column) . ' = ?';
            $bindings[] = $value;
        }

        $sql = sprintf(
            'UPDATE %s SET %s',
            $wrapIdentifier($table),
            implode(', ', $assignments),
        );

        $bindings = array_merge($bindings, $whereBindings);

        return [$sql, $bindings];
    }
}
