<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\ORM\Concerns;

trait HasTimestamps
{
    public function freshTimestamp(): string
    {
        return date($this->getDateFormat());
    }
    public function freshTimestampString(): string
    {
        return $this->fromDateTime($this->freshTimestamp());
    }
    public function getCreatedAtColumn(): string
    {
        return static::CREATED_AT;
    }
    public function getUpdatedAtColumn(): string
    {
        return static::UPDATED_AT;
    }
    public function setCreatedAt(mixed $value): static
    {
        $this->{static::CREATED_AT} = $value;
        return $this;
    }
    public function setUpdatedAt(mixed $value): static
    {
        $this->{static::UPDATED_AT} = $value;
        return $this;
    }
    public function usesTimestamps(): bool
    {
        return $this->timestamps;
    }
    protected function updateTimestamps(): void
    {
        $time = $this->freshTimestamp();
        if (!$this->isDirty(static::UPDATED_AT)) {
            $this->setUpdatedAt($time);
        }
        if (!$this->exists && !$this->isDirty(static::CREATED_AT)) {
            $this->setCreatedAt($time);
        }
    }
}
