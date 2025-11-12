<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Events;

/**
 * Event Subscriber Interface
 *
 * Defines the contract for event subscribers.
 * Allows subscribing to multiple events in a single class.
 *
 * @package Infocyph\DBLayer\Events
 * @author Hasan
 */
interface EventSubscriber
{
    /**
     * Get event subscriptions
     *
     * @return array<string, string|callable> Array of event => handler
     */
    public function subscribe(): array;
}