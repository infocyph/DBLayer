<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Events;

/**
 * Event Dispatcher
 * 
 * Manages event listeners and dispatches events.
 * Simple event system for database operations.
 * 
 * @package Infocyph\DBLayer\Events
 * @author Hasan
 */
class EventDispatcher
{
    /**
     * Registered event listeners
     */
    protected array $listeners = [];

    /**
     * Event queue for deferred dispatch
     */
    protected array $queue = [];

    /**
     * Whether to queue events
     */
    protected bool $queueing = false;

    /**
     * Register an event listener
     */
    public function listen(string $event, callable $listener, int $priority = 0): void
    {
        if (!isset($this->listeners[$event])) {
            $this->listeners[$event] = [];
        }

        $this->listeners[$event][] = [
            'callback' => $listener,
            'priority' => $priority,
        ];

        // Sort by priority (higher priority first)
        usort($this->listeners[$event], function ($a, $b) {
            return $b['priority'] <=> $a['priority'];
        });
    }

    /**
     * Dispatch an event
     */
    public function dispatch(Event $event): void
    {
        if ($this->queueing) {
            $this->queue[] = $event;
            return;
        }

        $eventName = $event->getName();

        if (!isset($this->listeners[$eventName])) {
            return;
        }

        foreach ($this->listeners[$eventName] as $listener) {
            call_user_func($listener['callback'], $event);
        }
    }

    /**
     * Check if event has listeners
     */
    public function hasListeners(string $event): bool
    {
        return isset($this->listeners[$event]) && !empty($this->listeners[$event]);
    }

    /**
     * Get listeners for an event
     */
    public function getListeners(string $event): array
    {
        return $this->listeners[$event] ?? [];
    }

    /**
     * Remove all listeners for an event
     */
    public function forget(string $event): void
    {
        unset($this->listeners[$event]);
    }

    /**
     * Remove all listeners
     */
    public function forgetAll(): void
    {
        $this->listeners = [];
    }

    /**
     * Start queuing events
     */
    public function startQueuing(): void
    {
        $this->queueing = true;
    }

    /**
     * Stop queuing and dispatch queued events
     */
    public function flushQueue(): void
    {
        $this->queueing = false;

        foreach ($this->queue as $event) {
            $this->dispatch($event);
        }

        $this->queue = [];
    }

    /**
     * Clear the event queue without dispatching
     */
    public function clearQueue(): void
    {
        $this->queue = [];
    }

    /**
     * Get queued events
     */
    public function getQueue(): array
    {
        return $this->queue;
    }

    /**
     * Subscribe multiple listeners at once
     */
    public function subscribe(array $listeners): void
    {
        foreach ($listeners as $event => $callbacks) {
            if (!is_array($callbacks)) {
                $callbacks = [$callbacks];
            }

            foreach ($callbacks as $callback) {
                $priority = 0;
                
                if (is_array($callback)) {
                    $priority = $callback[1] ?? 0;
                    $callback = $callback[0];
                }

                $this->listen($event, $callback, $priority);
            }
        }
    }

    /**
     * Create a simple event listener for query logging
     */
    public function logQueries(callable $logger): void
    {
        $this->listen('query.executed', function (QueryExecutedEvent $event) use ($logger) {
            $logger($event->getSql(), $event->getBindings(), $event->getTime());
        });
    }

    /**
     * Create a simple event listener for slow queries
     */
    public function logSlowQueries(callable $logger, float $threshold = 1000.0): void
    {
        $this->listen('query.executed', function (QueryExecutedEvent $event) use ($logger, $threshold) {
            if ($event->isSlow($threshold)) {
                $logger($event->getSql(), $event->getBindings(), $event->getTime());
            }
        });
    }
}
