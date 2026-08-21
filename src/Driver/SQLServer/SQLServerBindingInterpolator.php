<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Driver\SQLServer;

use Infocyph\DBLayer\Exceptions\QueryException;
use PDO;

/** Safely inline bindings for SQL Server's direct-query-only SHOWPLAN mode. */
final class SQLServerBindingInterpolator
{
    /**
     * @param array<int|string,mixed> $bindings
     */
    public static function interpolate(PDO $pdo, string $sql, array $bindings): string
    {
        $values = self::quotedBindings($pdo, $bindings);
        $result = '';
        $position = 0;
        $usedNames = [];
        $length = strlen($sql);

        for ($index = 0; $index < $length;) {
            $char = $sql[$index];

            if (in_array($char, ["'", '"', '['], true)) {
                $result .= self::consumeQuoted($sql, $index, $char);

                continue;
            }

            if (substr($sql, $index, 2) === '--') {
                $result .= self::consumeLineComment($sql, $index);

                continue;
            }

            if (substr($sql, $index, 2) === '/*') {
                $result .= self::consumeBlockComment($sql, $index);

                continue;
            }

            if ($char === '?') {
                $result .= self::positionalValue($sql, $values, $position);
                $position++;
                $index++;

                continue;
            }

            if ($char === ':' && preg_match('/\G:([A-Za-z_][A-Za-z0-9_]*)/', $sql, $match, 0, $index) === 1) {
                $result .= self::namedValue($match[1], $values, $usedNames);
                $index += strlen($match[0]);

                continue;
            }

            $result .= $char;
            $index++;
        }

        self::assertAllPositionalBindingsUsed($sql, $values, $position);
        self::assertAllNamedBindingsUsed($values, $usedNames);

        return $result;
    }

    /**
     * @param array{positional:list<string>,named:array<string,string>} $values
     * @param array<string,true> $usedNames
     */
    private static function assertAllNamedBindingsUsed(array $values, array $usedNames): void
    {
        $unused = array_diff_key($values['named'], $usedNames);

        if ($unused !== []) {
            throw QueryException::invalidParameter('bindings', 'Unused named SQL Server execution-plan bindings: ' . implode(', ', array_keys($unused)) . '.');
        }
    }

    /** @param array{positional:list<string>,named:array<string,string>} $values */
    private static function assertAllPositionalBindingsUsed(string $sql, array $values, int $position): void
    {
        $expected = count($values['positional']);

        if ($position !== $expected) {
            throw QueryException::bindingCountMismatch($sql, $position, $expected);
        }
    }

    private static function consumeBlockComment(string $sql, int &$index): string
    {
        $end = strpos($sql, '*/', $index + 2);
        $end = $end === false ? strlen($sql) : $end + 2;
        $value = substr($sql, $index, $end - $index);
        $index = $end;

        return $value;
    }

    private static function consumeLineComment(string $sql, int &$index): string
    {
        $end = strpos($sql, "\n", $index + 2);
        $end = $end === false ? strlen($sql) : $end + 1;
        $value = substr($sql, $index, $end - $index);
        $index = $end;

        return $value;
    }

    private static function consumeQuoted(string $sql, int &$index, string $opening): string
    {
        $closing = $opening === '[' ? ']' : $opening;
        $start = $index++;
        $length = strlen($sql);

        while ($index < $length) {
            if ($sql[$index] !== $closing) {
                $index++;

                continue;
            }

            $index++;
            if ($index < $length && $sql[$index] === $closing) {
                $index++;

                continue;
            }

            break;
        }

        return substr($sql, $start, $index - $start);
    }

    /**
     * @param array{positional:list<string>,named:array<string,string>} $values
     * @param array<string,true> $usedNames
     */
    private static function namedValue(string $name, array $values, array &$usedNames): string
    {
        if (!array_key_exists($name, $values['named'])) {
            throw QueryException::invalidParameter('bindings', "Missing named binding [{$name}] for SQL Server execution plan.");
        }

        $usedNames[$name] = true;

        return $values['named'][$name];
    }

    /** @param array{positional:list<string>,named:array<string,string>} $values */
    private static function positionalValue(string $sql, array $values, int $position): string
    {
        if (!array_key_exists($position, $values['positional'])) {
            throw QueryException::bindingCountMismatch($sql, $position + 1, count($values['positional']));
        }

        return $values['positional'][$position];
    }

    private static function quote(PDO $pdo, mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value) && is_finite($value)) {
            return (string) $value;
        }
        if (!is_string($value)) {
            throw QueryException::invalidParameter('bindings', 'SQL Server execution-plan bindings must be scalar or null.');
        }

        $quoted = $pdo->quote($value, PDO::PARAM_STR);
        if (!is_string($quoted)) {
            throw QueryException::invalidParameter('bindings', 'SQL Server could not quote an execution-plan binding.');
        }

        return $quoted;
    }

    /**
     * @param array<int|string,mixed> $bindings
     * @return array{positional:list<string>,named:array<string,string>}
     */
    private static function quotedBindings(PDO $pdo, array $bindings): array
    {
        $positional = [];
        $named = [];

        foreach ($bindings as $key => $value) {
            $quoted = self::quote($pdo, $value);

            if (is_int($key)) {
                $positional[] = $quoted;

                continue;
            }

            $named[ltrim($key, ':')] = $quoted;
        }

        return ['positional' => $positional, 'named' => $named];
    }
}
