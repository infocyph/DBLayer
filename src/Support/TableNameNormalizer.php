<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Support;

/**
 * ASCII-oriented table-name normalization used by repository conventions.
 */
final class TableNameNormalizer
{
    public static function normalize(string $table): string
    {
        $table = trim($table);
        if ($table === '') {
            return '';
        }

        $table = preg_replace('/\s+/', '', $table) ?? $table;
        $table = preg_replace('/(.)(?=[A-Z])/', '$1_', $table) ?? $table;
        $table = strtolower($table);
        $table = str_replace('-', '_', $table);

        return preg_replace('/_+/', '_', $table) ?? $table;
    }
}
