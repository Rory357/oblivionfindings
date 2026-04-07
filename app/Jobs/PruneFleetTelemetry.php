<?php

namespace App\Jobs;

use App\Models\FleetGeofenceState;
use App\Models\FleetTelemetryEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PruneFleetTelemetry implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
    }

    public $timeout = 600;

    public function handle(): void
    {
        $days = (int) config('fleet.retention.telemetry_days', 365);
        $cutoff = now()->subDays($days);
        $batchSize = 5000;

        do {
            $deleted = FleetTelemetryEvent::query()
                ->where('occurred_at', '<', $cutoff)
                ->limit($batchSize)
                ->delete();
        } while ($deleted >= $batchSize);

        // Clean orphaned geofence states (geofence deleted)
        FleetGeofenceState::query()
            ->whereNotIn('geofence_id', function ($q) {
                $q->select('id')->from('asset_geofences');
            })
            ->delete();

        // Clean stale states for assets with no active tracker
        FleetGeofenceState::query()
            ->whereNotIn('asset_id', function ($q) {
                $q->select('asset_id')->from('asset_trackers')->where('status', 'paired');
            })
            ->delete();
    }
}
