<?php

namespace App\Domain\SecurityDevices\Hubs\Services;

use App\Domain\Monitoring\Models\MonitoringCollector;

/**
 * S&D Phase B: which hub watches a Site, so its ping and offline checks run on that hub.
 *
 * Only an approved hub that hasn't been removed counts. If several cover the Site, the hub
 * whose home Site it is wins, then the lowest id.
 */
final class CoveringHubResolver
{
    /** Same states NativeMonitoringDefinitionService accepts when it gives a hub a check. */
    private const array WORKING_STATUSES = ['online', 'offline', 'degraded', 'unavailable', 'pending'];

    public function forSite(int $siteId): ?MonitoringCollector
    {
        if ($siteId < 1) {
            return null;
        }

        $covering = MonitoringCollector::query()
            ->whereNotNull('approved_at')
            ->whereNull('revoked_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (MonitoringCollector $hub): bool => $hub->isApprovedForWork()
                && in_array(strtolower((string) $hub->status), self::WORKING_STATUSES, true)
                && in_array($siteId, $hub->authorisedSiteIds(), true))
            ->values();

        return $covering->first(fn (MonitoringCollector $hub): bool => (int) $hub->site_id === $siteId)
            ?? $covering->first();
    }

    /** "Head office hub" for a hub named "Head office" or "Head office hub". */
    public static function label(MonitoringCollector $hub): string
    {
        $name = trim((string) $hub->name);
        if ($name === '') {
            return 'the Site hub';
        }

        return preg_match('/\bhub\z/i', $name) === 1 ? $name : "{$name} hub";
    }
}
