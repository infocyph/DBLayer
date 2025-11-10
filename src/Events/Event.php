<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Events;

/**
 * Event Base Class
 * 
 * Base class for all database events.
 * Provides common event functionality and properties.
 * 
 * @package Infocyph\DBLayer\Events
 * @author Hasan
 */
abstract class Event
{
    /**
     * The time the event occurred
     */
    protected float $timestamp;

    /**
     * Event metadata
     */
    protected array $metadata = [];

    /**
     * Create a new event instance
     */
    public function __construct()
    {
        $this->timestamp = microtime(true);
    }

    /**
     * Get the event timestamp
     */
    public function getTimestamp(): float
    {
        return $this->timestamp;
    }

    /**
     * Set event metadata
     */
    public function setMetadata(array $metadata): static
    {
        $this->metadata = $metadata;
        return $this;
    }

    /**
     * Get event metadata
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Add metadata item
     */
    public function addMetadata(string $key, mixed $value): static
    {
        $this->metadata[$key] = $value;
        return $this;
    }

    /**
     * Get event name
     */
    abstract public function getName(): string;

    /**
     * Get event data
     */
    abstract public function getData(): array;

    /**
     * Convert event to array
     */
    public function toArray(): array
    {
        return [
            'name' => $this->getName(),
            'timestamp' => $this->timestamp,
            'data' => $this->getData(),
            'metadata' => $this->metadata,
        ];
    }
}
