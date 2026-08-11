<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Concerns;

use Infocyph\DBLayer\Events\DatabaseEvents\QueryExecuted;

trait DBQueryTimeMonitors
{
    /**
     * Evaluate cumulative query-time thresholds and fire callbacks once.
     */
    private static function evaluateQueryTimeMonitors(QueryExecuted $event): void
    {
        foreach (static::$queryTimeMonitors as $index => $monitor) {
            if ($monitor['fired']) {
                continue;
            }

            $monitor['cumulative_ms'] += $event->time;
            if ($monitor['cumulative_ms'] >= $monitor['threshold_ms']) {
                $monitor['fired'] = true;
                self::invokeQueryTimeMonitor($monitor['callback'], $event);
            }

            static::$queryTimeMonitors[$index] = $monitor;
        }
    }
}
