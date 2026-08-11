<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Support;

/**
 * Canonical SQL shape identity that preserves literal content.
 */
final class SqlFingerprint
{
    public static function hash(string $sql, int $length = 16): string
    {
        return substr(hash('sha256', self::normalize($sql)), 0, $length);
    }

    public static function normalize(string $sql): string
    {
        $sql = preg_replace('/\A\s*\/\*\s*dblayer\b.*?\*\/\s*/is', '', $sql, 1) ?? $sql;
        $length = strlen($sql);
        $result = '';
        $pendingSpace = false;

        for ($index = 0; $index < $length;) {
            $char = $sql[$index];

            if ($char === "'" || $char === '"' || $char === '`') {
                [$token, $index] = self::consumeQuoted($sql, $index, $char);
                $result = self::appendToken($result, $token, $pendingSpace);

                continue;
            }

            $dollarTag = $char === '$' ? self::dollarTagAt($sql, $index) : null;
            if ($dollarTag !== null) {
                [$token, $index] = self::consumeDollarQuoted($sql, $index, $dollarTag);
                $result = self::appendToken($result, $token, $pendingSpace);

                continue;
            }

            if (ctype_space($char)) {
                $pendingSpace = true;
                ++$index;

                continue;
            }

            $result = self::appendToken($result, $char, $pendingSpace);
            ++$index;
        }

        return trim($result);
    }

    public static function statement(string $sql): string
    {
        $normalized = self::normalize($sql);

        return strtoupper(substr($normalized, 0, strcspn($normalized, " \t\n\r")));
    }

    private static function appendToken(string $result, string $token, bool &$pendingSpace): string
    {
        if ($pendingSpace && $result !== '') {
            $result .= ' ';
        }
        $pendingSpace = false;

        return $result . $token;
    }

    /** @return array{string,int} */
    private static function consumeDollarQuoted(string $sql, int $start, string $tag): array
    {
        $contentStart = $start + strlen($tag);
        $end = strpos($sql, $tag, $contentStart);
        if ($end === false) {
            return [substr($sql, $start), strlen($sql)];
        }

        $next = $end + strlen($tag);

        return [substr($sql, $start, $next - $start), $next];
    }

    /** @return array{string,int} */
    private static function consumeQuoted(string $sql, int $start, string $quote): array
    {
        $length = strlen($sql);
        $token = $quote;

        for ($index = $start + 1; $index < $length; ++$index) {
            $char = $sql[$index];
            $token .= $char;
            if ($char === '\\' && $index + 1 < $length) {
                $token .= $sql[++$index];

                continue;
            }
            if ($char !== $quote) {
                continue;
            }
            if ($index + 1 < $length && $sql[$index + 1] === $quote) {
                $token .= $sql[++$index];

                continue;
            }

            return [$token, $index + 1];
        }

        return [$token, $length];
    }

    private static function dollarTagAt(string $sql, int $offset): ?string
    {
        if (preg_match('/\G(?:\$[A-Za-z_][A-Za-z0-9_]*\$|\$\$)/', $sql, $match, 0, $offset) !== 1) {
            return null;
        }

        return $match[0];
    }
}
