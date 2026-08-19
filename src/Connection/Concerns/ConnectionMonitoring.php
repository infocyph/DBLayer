<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Connection\Concerns;

use Infocyph\DBLayer\Monitoring\DatabaseMonitor;

/**
 * Explicit on-demand database-system monitoring entry point.
 */
trait ConnectionMonitoring
{
    public function monitor(): DatabaseMonitor
    {
        return new DatabaseMonitor($this);
    }
}
