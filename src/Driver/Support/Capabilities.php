<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Driver\Support;

/**
 * Declared capabilities for a driver / dialect.
 *
 * Core can branch on these instead of using method_exists/instanceof hacks.
 */
final readonly class Capabilities
{
    public function __construct(
        public bool $supportsReturning = false,
        public bool $supportsInsertIgnore = false,
        public bool $supportsUpsert = false,
        public bool $supportsSavepoints = true,
        public bool $supportsSchemas = false,
        public bool $supportsJson = false,
        public bool $supportsWindowFunctions = false,
    ) {}

    public function withInsertIgnore(bool $enabled = true): self
    {
        return new self(
            supportsReturning: $this->supportsReturning,
            supportsInsertIgnore: $enabled,
            supportsUpsert: $this->supportsUpsert,
            supportsSavepoints: $this->supportsSavepoints,
            supportsSchemas: $this->supportsSchemas,
            supportsJson: $this->supportsJson,
            supportsWindowFunctions: $this->supportsWindowFunctions,
        );
    }

    public function withJson(bool $enabled = true): self
    {
        return new self(
            supportsReturning: $this->supportsReturning,
            supportsInsertIgnore: $this->supportsInsertIgnore,
            supportsUpsert: $this->supportsUpsert,
            supportsSavepoints: $this->supportsSavepoints,
            supportsSchemas: $this->supportsSchemas,
            supportsJson: $enabled,
            supportsWindowFunctions: $this->supportsWindowFunctions,
        );
    }

    public function withReturning(bool $enabled = true): self
    {
        return new self(
            supportsReturning: $enabled,
            supportsInsertIgnore: $this->supportsInsertIgnore,
            supportsUpsert: $this->supportsUpsert,
            supportsSavepoints: $this->supportsSavepoints,
            supportsSchemas: $this->supportsSchemas,
            supportsJson: $this->supportsJson,
            supportsWindowFunctions: $this->supportsWindowFunctions,
        );
    }

    public function withSavepoints(bool $enabled = true): self
    {
        return new self(
            supportsReturning: $this->supportsReturning,
            supportsInsertIgnore: $this->supportsInsertIgnore,
            supportsUpsert: $this->supportsUpsert,
            supportsSavepoints: $enabled,
            supportsSchemas: $this->supportsSchemas,
            supportsJson: $this->supportsJson,
            supportsWindowFunctions: $this->supportsWindowFunctions,
        );
    }

    public function withSchemas(bool $enabled = true): self
    {
        return new self(
            supportsReturning: $this->supportsReturning,
            supportsInsertIgnore: $this->supportsInsertIgnore,
            supportsUpsert: $this->supportsUpsert,
            supportsSavepoints: $this->supportsSavepoints,
            supportsSchemas: $enabled,
            supportsJson: $this->supportsJson,
            supportsWindowFunctions: $this->supportsWindowFunctions,
        );
    }

    public function withUpsert(bool $enabled = true): self
    {
        return new self(
            supportsReturning: $this->supportsReturning,
            supportsInsertIgnore: $this->supportsInsertIgnore,
            supportsUpsert: $enabled,
            supportsSavepoints: $this->supportsSavepoints,
            supportsSchemas: $this->supportsSchemas,
            supportsJson: $this->supportsJson,
            supportsWindowFunctions: $this->supportsWindowFunctions,
        );
    }

    public function withWindowFunctions(bool $enabled = true): self
    {
        return new self(
            supportsReturning: $this->supportsReturning,
            supportsInsertIgnore: $this->supportsInsertIgnore,
            supportsUpsert: $this->supportsUpsert,
            supportsSavepoints: $this->supportsSavepoints,
            supportsSchemas: $this->supportsSchemas,
            supportsJson: $this->supportsJson,
            supportsWindowFunctions: $enabled,
        );
    }
}
