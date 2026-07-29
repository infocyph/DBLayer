<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Schema;

use Infocyph\DBLayer\Query\Expression;

/**
 * Mutable definition returned by Blueprint column methods.
 *
 * Definitions exist only while schema SQL is being built. Normal query
 * execution never loads or retains them.
 */
final class ColumnDefinition
{
    public bool $autoIncrement = false;

    public bool $change = false;

    public mixed $default = null;

    public ?Expression $generatedExpression = null;

    public ?string $generatedStorage = null;

    public bool $hasDefault = false;

    public bool $index = false;

    public bool $nullable = false;

    public bool $primary = false;

    public bool $unique = false;

    public bool $unsigned = false;

    public bool $useCurrent = false;

    public bool $useCurrentOnUpdate = false;

    /**
     * @param list<string> $allowedValues
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly ?int $length = null,
        public readonly ?int $precision = null,
        public readonly ?int $scale = null,
        public readonly array $allowedValues = [],
        public readonly ?string $spatialSubtype = null,
        public readonly ?int $srid = null,
        public readonly ?int $dimensions = null,
    ) {}

    public function autoIncrement(bool $enabled = true): self
    {
        $this->autoIncrement = $enabled;

        return $this;
    }

    public function change(bool $enabled = true): self
    {
        $this->change = $enabled;

        return $this;
    }

    public function default(mixed $value): self
    {
        $this->default = $value;
        $this->hasDefault = true;
        $this->useCurrent = false;

        return $this;
    }

    public function index(bool $enabled = true): self
    {
        $this->index = $enabled;

        return $this;
    }

    public function nullable(bool $enabled = true): self
    {
        $this->nullable = $enabled;

        return $this;
    }

    public function primary(bool $enabled = true): self
    {
        $this->primary = $enabled;
        if ($enabled) {
            $this->nullable = false;
        }

        return $this;
    }

    public function storedAs(string|Expression $expression): self
    {
        return $this->generated($expression, 'stored');
    }

    public function unique(bool $enabled = true): self
    {
        $this->unique = $enabled;

        return $this;
    }

    public function unsigned(bool $enabled = true): self
    {
        $this->unsigned = $enabled;

        return $this;
    }

    public function useCurrent(bool $enabled = true): self
    {
        $this->useCurrent = $enabled;
        if ($enabled) {
            $this->default = null;
            $this->hasDefault = false;
        }

        return $this;
    }

    public function useCurrentOnUpdate(bool $enabled = true): self
    {
        $this->useCurrentOnUpdate = $enabled;

        return $this;
    }

    public function virtualAs(string|Expression $expression): self
    {
        return $this->generated($expression, 'virtual');
    }

    private function generated(string|Expression $expression, string $storage): self
    {
        $expression = is_string($expression) ? Expression::make($expression) : $expression;
        if (trim($expression->getValue()) === '') {
            throw \Infocyph\DBLayer\Exceptions\SchemaException::invalid(
                'A generated column expression cannot be empty.',
            );
        }

        $this->generatedExpression = $expression;
        $this->generatedStorage = $storage;
        $this->default = null;
        $this->hasDefault = false;
        $this->useCurrent = false;
        $this->useCurrentOnUpdate = false;

        return $this;
    }
}
