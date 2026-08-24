<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Repository\Casts;

use InvalidArgumentException;

/**
 * Fixed-scale decimal cast that preserves string precision for ordinary
 * decimal inputs instead of routing every value through binary floating point.
 */
final readonly class DecimalCast implements AttributeCast
{
    public function __construct(private int $scale)
    {
        if ($scale < 0 || $scale > 30) {
            throw new InvalidArgumentException('Decimal cast scale must be between 0 and 30.');
        }
    }

    #[\Override]
    public function get(mixed $value, array $row): mixed
    {
        unset($row);

        return $this->normalize($value);
    }

    #[\Override]
    public function set(mixed $value, array $attributes): mixed
    {
        unset($attributes);

        return $this->normalize($value);
    }

    private function normalize(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $this->withScale((string) $value, '');
        }

        if (is_float($value)) {
            return number_format($value, $this->scale, '.', '');
        }

        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf(
                'Decimal cast expects int, float, string, or null; %s given.',
                get_debug_type($value),
            ));
        }

        $value = trim($value);
        if (!preg_match('/^([+-]?)(\d+)(?:\.(\d+))?$/D', $value, $matches)) {
            if (is_numeric($value)) {
                return number_format((float) $value, $this->scale, '.', '');
            }

            throw new InvalidArgumentException(sprintf('Invalid decimal value [%s].', $value));
        }

        $sign = $matches[1];
        $integer = ltrim($matches[2], '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = $matches[3] ?? '';

        if (strlen($fraction) > $this->scale) {
            [$integer, $fraction] = $this->round($integer, $fraction);
        }

        $normalized = $this->withScale($integer, $fraction);

        return $sign === '-' && $normalized !== $this->zero() ? '-' . $normalized : $normalized;
    }

    /** @return array{0:string,1:string} */
    private function round(string $integer, string $fraction): array
    {
        $kept = substr($fraction, 0, $this->scale);
        $next = (int) ($fraction[$this->scale] ?? '0');

        if ($next < 5) {
            return [$integer, $kept];
        }

        if ($this->scale === 0) {
            return [$this->increment($integer), ''];
        }

        $digits = str_split(str_pad($kept, $this->scale, '0'));
        for ($index = count($digits) - 1; $index >= 0; --$index) {
            if ($digits[$index] !== '9') {
                $digits[$index] = (string) ((int) $digits[$index] + 1);

                return [$integer, implode('', $digits)];
            }

            $digits[$index] = '0';
        }

        return [$this->increment($integer), implode('', $digits)];
    }

    private function increment(string $integer): string
    {
        $digits = str_split($integer);

        for ($index = count($digits) - 1; $index >= 0; --$index) {
            if ($digits[$index] !== '9') {
                $digits[$index] = (string) ((int) $digits[$index] + 1);

                return implode('', $digits);
            }

            $digits[$index] = '0';
        }

        return '1' . implode('', $digits);
    }

    private function withScale(string $integer, string $fraction): string
    {
        if ($this->scale === 0) {
            return $integer;
        }

        return $integer . '.' . str_pad(substr($fraction, 0, $this->scale), $this->scale, '0');
    }

    private function zero(): string
    {
        return $this->scale === 0 ? '0' : '0.' . str_repeat('0', $this->scale);
    }
}
