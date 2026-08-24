<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Exceptions;

use InvalidArgumentException;

final class UnwritableAttributeException extends InvalidArgumentException
{
    public static function forCreate(string $attribute): self
    {
        return new self(sprintf(
            'Attribute [%s] is not creatable by this repository.',
            $attribute,
        ));
    }

    public static function forUpdate(string $attribute): self
    {
        return new self(sprintf(
            'Attribute [%s] is not updatable by this repository.',
            $attribute,
        ));
    }
}
