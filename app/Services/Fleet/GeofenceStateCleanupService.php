<?php

namespace App\Services\Fleet;

use App\Models\AssetGeofence;
use App\Models\FleetGeofenceState;
use Illuminate\Support\Facades\Log;

class GeofenceStateCleanupService
{
    public function cleanup(AssetGeofence $geofence): void
    {
        $insideStates = FleetGeofenceState::query()
            ->where('geofence_id', $geofence->id)
            ->where('status', 'inside')
            ->get();

        $signalService = app(FleetSignalService::class);

        foreach ($insideStates as $state) {
            try {
                $signalService->emit([
                    'asset_id' => $state->asset_id,
                    'geofence_id' => $geofence->id,
                    'signal_type' => 'geofence.exit',
                    'severity_hint' => 'low',
                    'occurred_at' => now(),
                    'payload' => [
                        'geofence_name' => $geofence->name,
                        'reason' => 'geofence_removed',
                    ],
                ]);
            } catch (\Throwable $e) {
                Log::warning("Failed to emit exit signal for geofence {$geofence->id}, asset {$state->asset_id}: {$e->getMessage()}");
            }
        }

        FleetGeofenceState::query()
            ->where('geofence_id', $geofence->id)
            ->delete();
    }
}
