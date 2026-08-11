<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Query\Core;

/**
 * Provenance of SQL entering the execution core.
 */
enum SqlOrigin
{
    case BUILDER;

    case RAW;
}
