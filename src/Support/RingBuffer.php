<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Support;

/**
 * Generic ring-buffer insertion utility.
 */
final class RingBuffer
{
    /**
     * @template TValue
     * @param array<int,TValue> $buffer
     * @param TValue $entry
     */
    public static function append(array &$buffer, int &$start, int &$count, int $max, $entry): void
    {
        if ($max <= 0) {
            $buffer = [];
            $start = 0;
            $count = 0;

            return;
        }

        if ($count < $max) {
            $index = ($start + $count) % $max;
            $buffer[$index] = $entry;
            $count++;

            return;
        }

        $buffer[$start] = $entry;
        $start = ($start + 1) % $max;
    }

    /**
     * @template TValue
     * @param array<int,TValue> $buffer
     * @return list<TValue>
     */
    public static function ordered(array $buffer, int $start, int $count, int $max): array
    {
        $ordered = [];
        for ($offset = 0; $offset < $count; ++$offset) {
            $index = ($start + $offset) % $max;
            if (array_key_exists($index, $buffer)) {
                $ordered[] = $buffer[$index];
            }
        }

        return $ordered;
    }
}
