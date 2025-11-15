<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Events;

/**
 * Event Subscriber Interface
 *
 * Defines the contract for event subscribers.
 * Allows subscribing to multiple events in a single class.
 */
interface EventSubscriber
{
    /**
     * Get event subscriptions.
     *
     * @return array<string, callable|string> Array of event => handler
     */
    public function subscribe(): array;
}
