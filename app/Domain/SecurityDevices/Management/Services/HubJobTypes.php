<?php

namespace App\Domain\SecurityDevices\Management\Services;

use App\Domain\Monitoring\Models\MonitoringCollector;

/**
 * The job types a hub says it can run (S&D Phase E). A hub refuses its whole config when it sees a
 * job type it doesn't know, so any job type added after Phase B goes only to hubs whose heartbeat
 * lists it (`runtime.command_adapters`). Older hubs keep working; the new job waits for the update.
 */
final class HubJobTypes
{
    public static function runs(MonitoringCollector $hub, string $adapter): bool
    {
        $adapters = $hub->runtime_status['command_adapters'] ?? null;

        return is_string($adapters) && in_array($adapter, explode(',', $adapters), true);
    }
}
