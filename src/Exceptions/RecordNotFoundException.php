<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Exceptions;

/** A scoped repository binding or refresh did not find a visible record. */
class RecordNotFoundException extends DBException
{
    /** @param class-string $repository */
    public static function forRepository(string $repository): static
    {
        return new static(sprintf('No visible record was found for repository [%s].', $repository));
    }
}
