<?php

namespace App\Domain\SecurityDevices\Iot\Relay\Services;

use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;

/**
 * S&D Phase E (D22): Sites whose network address range another Site also uses. Unencrypted MQTT
 * is refused at both, because an address could then belong to either house.
 */
final class SensorRelaySites
{
    /** @return list<int> */
    public static function clashing(): array
    {
        $sitesByRange = [];
        DiscoveryScope::query()->where('status', 'active')->get(['site_id', 'cidrs'])
            ->each(function (DiscoveryScope $scope) use (&$sitesByRange): void {
                foreach (array_filter((array) $scope->cidrs, 'is_string') as $range) {
                    $sitesByRange[$range][(int) $scope->site_id] = true;
                }
            });
        $clashing = [];
        foreach ($sitesByRange as $sites) {
            if (count($sites) > 1) {
                foreach (array_keys($sites) as $siteId) {
                    $clashing[$siteId] = true;
                }
            }
        }

        return array_keys($clashing);
    }
}
