<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Connection;

/**
 * SQL statement keyword inspector.
 *
 * Resolves the first top-level statement token and handles CTE preambles.
 */
final class SqlStatementInspector
{
    /**
     * Resolve the leading top-level SQL statement keyword.
     *
     * Handles common CTE forms:
     *  WITH ... SELECT
     *  WITH ... INSERT/UPDATE/DELETE
     */
    public static function leadingStatementKeyword(string $sql): string
    {
        $length = strlen($sql);
        $index  = 0;

        self::skipIgnorable($sql, $index, $length);
        $first = self::readKeyword($sql, $index, $length);

        if ($first === '' || $first !== 'WITH') {
            return $first;
        }

        self::skipOptionalRecursiveKeyword($sql, $index, $length);

        while (self::consumeOneCte($sql, $index, $length)) {
            if (! self::consumeCteSeparator($sql, $index, $length)) {
                break;
            }
        }

        self::skipIgnorable($sql, $index, $length);

        return self::readKeyword($sql, $index, $length);
    }

    private static function advanceToNextOpeningParenthesis(string $sql, int &$index, int $length): void
    {
        while ($index < $length && $sql[$index] !== '(') {
            $char = $sql[$index];

            if (self::skipQuotedOrComment($sql, $index, $length, $char)) {
                continue;
            }

            $index++;
        }
    }

    private static function consumeCteSeparator(string $sql, int &$index, int $length): bool
    {
        self::skipIgnorable($sql, $index, $length);

        if ($index < $length && $sql[$index] === ',') {
            $index++;

            return true;
        }

        return false;
    }

    private static function consumeOneCte(string $sql, int &$index, int $length): bool
    {
        self::skipIgnorable($sql, $index, $length);

        if ($index >= $length) {
            return false;
        }

        self::skipIdentifier($sql, $index, $length);
        self::skipIgnorable($sql, $index, $length);

        if ($index < $length && $sql[$index] === '(') {
            self::skipParenthesized($sql, $index, $length);
            self::skipIgnorable($sql, $index, $length);
        }

        self::advanceToNextOpeningParenthesis($sql, $index, $length);

        if ($index >= $length || $sql[$index] !== '(') {
            return false;
        }

        self::skipParenthesized($sql, $index, $length);

        return true;
    }

    /**
     * Read a SQL keyword token from current index and advance pointer.
     */
    private static function readKeyword(string $sql, int &$index, int $length): string
    {
        if ($index >= $length) {
            return '';
        }

        if (! preg_match('/[A-Za-z_]/', $sql[$index])) {
            return '';
        }

        $start = $index;

        while ($index < $length && preg_match('/[A-Za-z0-9_]/', $sql[$index])) {
            $index++;
        }

        return strtoupper(substr($sql, $start, $index - $start));
    }

    /**
     * Skip SQL block comment beginning with "/*".
     */
    private static function skipBlockComment(string $sql, int &$index, int $length): void
    {
        $index += 2;

        while (($index + 1) < $length) {
            if ($sql[$index] === '*' && $sql[$index + 1] === '/') {
                $index += 2;

                return;
            }

            $index++;
        }
    }

    /**
     * Skip one SQL identifier token (quoted or plain).
     */
    private static function skipIdentifier(string $sql, int &$index, int $length): void
    {
        if ($index >= $length) {
            return;
        }

        $char = $sql[$index];

        if ($char === '"' || $char === '`') {
            self::skipQuoted($sql, $index, $length, $char);

            return;
        }

        while ($index < $length && preg_match('/[A-Za-z0-9_$.]/', $sql[$index])) {
            $index++;
        }
    }

    /**
     * Skip whitespace and comments.
     */
    private static function skipIgnorable(string $sql, int &$index, int $length): void
    {
        while ($index < $length) {
            $char = $sql[$index];

            if (ctype_space($char)) {
                $index++;

                continue;
            }

            if ($char === '-' && ($index + 1) < $length && $sql[$index + 1] === '-') {
                self::skipLineComment($sql, $index, $length);

                continue;
            }

            if ($char === '/' && ($index + 1) < $length && $sql[$index + 1] === '*') {
                self::skipBlockComment($sql, $index, $length);

                continue;
            }

            return;
        }
    }

    /**
     * Skip SQL line comment beginning with "--".
     */
    private static function skipLineComment(string $sql, int &$index, int $length): void
    {
        $index += 2;

        while ($index < $length && $sql[$index] !== "\n") {
            $index++;
        }
    }

    private static function skipOptionalRecursiveKeyword(string $sql, int &$index, int $length): void
    {
        self::skipIgnorable($sql, $index, $length);
        $token = self::readKeyword($sql, $index, $length);

        if ($token !== 'RECURSIVE') {
            $index -= strlen($token);
        }
    }

    /**
     * Skip balanced parenthesized expression, handling nested parentheses.
     */
    private static function skipParenthesized(string $sql, int &$index, int $length): void
    {
        if ($index >= $length || $sql[$index] !== '(') {
            return;
        }

        $depth = 0;

        while ($index < $length) {
            $char = $sql[$index];

            if (self::skipQuotedOrComment($sql, $index, $length, $char)) {
                continue;
            }

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;

                if ($depth === 0) {
                    $index++;

                    return;
                }
            }

            $index++;
        }
    }

    /**
     * Skip a quoted string/identifier.
     */
    private static function skipQuoted(string $sql, int &$index, int $length, string $quote): void
    {
        $index++; // opening quote

        while ($index < $length) {
            if ($sql[$index] === '\\') {
                $index += 2;

                continue;
            }

            if ($sql[$index] === $quote) {
                // escaped quote by duplication.
                if (($index + 1) < $length && $sql[$index + 1] === $quote) {
                    $index += 2;

                    continue;
                }

                $index++;

                return;
            }

            $index++;
        }
    }

    private static function skipQuotedOrComment(string $sql, int &$index, int $length, string $char): bool
    {
        if ($char === "'" || $char === '"' || $char === '`') {
            self::skipQuoted($sql, $index, $length, $char);

            return true;
        }

        if ($char === '-' && ($index + 1) < $length && $sql[$index + 1] === '-') {
            self::skipLineComment($sql, $index, $length);

            return true;
        }

        if ($char === '/' && ($index + 1) < $length && $sql[$index + 1] === '*') {
            self::skipBlockComment($sql, $index, $length);

            return true;
        }

        return false;
    }
}
