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
}
