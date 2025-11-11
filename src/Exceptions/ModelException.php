<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Exceptions;

use Exception;

class ModelException extends Exception
{
    public static function notFound(string $model, mixed $id): self
    {
        return new self("Model {$model} with ID {$id} not found");
    }
}
