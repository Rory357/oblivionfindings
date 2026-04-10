<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetAlert;
use App\Models\AssetAlertPolicy;
use App\Models\AssetTracker;
use Carbon\Carbon;

/**
 * @deprecated PR5: This service is DEAD CODE — openAlert() is never called anywhere.
 *             Asset alerts flow through FleetSignalService → ControlRoomAlert.
 *             Do NOT add new callers to this service.
 *
 * @see \App\Models\ControlRoomAlert — canonical operational alert
 */
class AssetAlertService
{
    public function openAlert(
        Asset $asset,
        string $type,
        string $severity,
        ?AssetTracker $tracker = null,
        ?AssetAlertPolicy $policy = null,
        array $context = []
    ): AssetAlert {
        $existing = AssetAlert::query()
            ->where('asset_id', $asset->id)
            ->where('alert_type', $type)
            ->where('status', 'open')
            ->where('triggered_at', '>=', now()->subMinutes(30))
            ->latest('triggered_at')
            ->first();

        if ($existing) {
            return $existing;
        }

        return AssetAlert::create([
            'asset_id' => $asset->id,
            'asset_tracker_id' => $tracker?->id,
            'asset_alert_policy_id' => $policy?->id,
            'alert_type' => $type,
            'severity' => $severity,
            'status' => 'open',
            'triggered_at' => Carbon::now(),
            'context' => $context,
        ]);
    }
}
