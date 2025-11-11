<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\ORM\Concerns;

trait GuardsAttributes
{
    protected static bool $unguarded = false;
    protected array $fillable = [];
    protected array $guarded = ['*'];
    public static function reguard(): void
    {
        static::$unguarded = false;
    }
    public static function unguard(bool $state = true): void
    {
        static::$unguarded = $state;
    }

    public function fill(array $attributes): static
    {
        foreach ($this->fillableFromArray($attributes) as $key => $value) {
            $this->setAttribute($key, $value);
        }
        return $this;
    }
    public function fillable(array $fillable): static
    {
        $this->fillable = $fillable;
        return $this;
    }
    public function getFillable(): array
    {
        return $this->fillable;
    }
    public function getGuarded(): array
    {
        return $this->guarded;
    }
    public function guard(array $guarded): static
    {
        $this->guarded = $guarded;
        return $this;
    }
    protected function fillableFromArray(array $attributes): array
    {
        if (count($this->getFillable()) > 0 && !static::$unguarded) {
            return array_intersect_key($attributes, array_flip($this->getFillable()));
        }
        return $attributes;
    }
    protected function isFillable(string $key): bool
    {
        if (static::$unguarded) {
            return true;
        }
        if (in_array($key, $this->getFillable())) {
            return true;
        }
        if ($this->isGuarded($key)) {
            return false;
        }
        return empty($this->getFillable()) && !str_starts_with($key, '_');
    }
    protected function isGuarded(string $key): bool
    {
        return in_array($key, $this->getGuarded()) || $this->getGuarded() == ['*'];
    }
}
