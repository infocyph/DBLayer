<?php

// src/Driver/Support/Capabilities.php

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
        public bool $supportsReturning    = false,
        public bool $supportsInsertIgnore = false,
        public bool $supportsUpsert       = false,
        public bool $supportsSavepoints   = true,
        public bool $supportsSchemas      = false,
    ) {
    }

    public function withInsertIgnore(bool $enabled = true): self
    {
        return new self(
            supportsReturning: $this->supportsReturning,
            supportsInsertIgnore: $enabled,
            supportsUpsert: $this->supportsUpsert,
            supportsSavepoints: $this->supportsSavepoints,
            supportsSchemas: $this->supportsSchemas,
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
        );
    }
}
