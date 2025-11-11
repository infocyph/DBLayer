<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\ORM\Concerns;

trait HasEvents
{
    protected static array $dispatcher = [];
    public static function created(callable $callback): void
    {
    }
    public static function creating(callable $callback): void
    {
    }
    public static function deleted(callable $callback): void
    {
    }
    public static function deleting(callable $callback): void
    {
    }

    public static function observe(object $observer): void
    {
    }
    public static function saved(callable $callback): void
    {
    }
    public static function saving(callable $callback): void
    {
    }
    public static function updated(callable $callback): void
    {
    }
    public static function updating(callable $callback): void
    {
    }
    protected function fireModelEvent(string $event, bool $halt = true): mixed
    {
        $method = $event;
        if (method_exists($this, $method)) {
            return $this->$method();
        }
        return true;
    }
}
