<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Support;

final class ArrayNormalizer
{
    private function __construct() {}

    /**
     * @return array<string,mixed>
     */
    public static function stringKeyArray(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $normalized = [];

        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                continue;
            }

            $normalized[$key] = $item;
        }

        return $normalized;
    }
}
