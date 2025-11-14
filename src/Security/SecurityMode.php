<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Security;

/**
 * SecurityMode
 *
 * Controls how aggressively DBLayer applies security checks.
 *
 * OFF    - No automatic validation (only crypto helpers).
 * NORMAL - Lightweight, sane defaults for production.
 * STRICT - Aggressive checks: length, dangerous patterns, injection.
 */
enum SecurityMode: string
{
    case OFF    = 'off';
    case NORMAL = 'normal';
    case STRICT = 'strict';
}
