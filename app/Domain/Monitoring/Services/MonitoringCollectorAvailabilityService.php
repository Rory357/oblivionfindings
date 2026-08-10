<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Models\MonitoringCollector;
use Carbon\CarbonInterface;

final class MonitoringCollectorAvailabilityService
{
    public const AVAILABLE = 'available';

    public const UNAVAILABLE = 'unavailable';

    public const REVOKED = 'revoked';

    public function state(?MonitoringCollector $collector): string
    {
        if ($collector === null) {
            return self::UNAVAILABLE;
        }

        if ($collector->revoked_at !== null || $collector->status === self::REVOKED) {
            return self::REVOKED;
        }

        $lastHeartbeat = $this->lastHeartbeat($collector);
        $staleAfter = max(
            60,
            min(3600, (int) config('monitoring.collector.heartbeat_stale_seconds', 180)),
        );

        return $collector->status === 'online'
            && $lastHeartbeat !== null
            && $lastHeartbeat->gte(now()->subSeconds($staleAfter))
                ? self::AVAILABLE
                : self::UNAVAILABLE;
    }

    public function isAvailable(?MonitoringCollector $collector): bool
    {
        return $this->state($collector) === self::AVAILABLE;
    }

    public function lastHeartbeat(?MonitoringCollector $collector): ?CarbonInterface
    {
        return $collector?->last_heartbeat_at ?? $collector?->last_seen_at;
    }
}
