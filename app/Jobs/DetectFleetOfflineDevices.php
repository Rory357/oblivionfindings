<?php

namespace App\Jobs;

use App\Models\FleetVehicleStateSnapshot;
use App\Services\Fleet\FleetSignalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DetectFleetOfflineDevices implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 120;

    public function __construct()
    {
    }

    public function handle(FleetSignalService $signals): void
    {
        $offlineMinutes = (int) config('fleet.signals.offline_after_minutes', 15);
        $threshold = now()->subMinutes($offlineMinutes);

        FleetVehicleStateSnapshot::query()
            ->where('status', 'online')
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '<', $threshold)
            ->chunkById(200, function ($rows) use ($signals) {
                foreach ($rows as $state) {
                    $state->update(['status' => 'offline']);

                    $signals->emit([
                        'asset_id' => $state->asset_id,
                        'signal_type' => 'device.offline',
                        'severity_hint' => 'medium',
                        'occurred_at' => now(),
                        'payload' => [
                            'last_seen_at' => optional($state->last_seen_at)->toISOString(),
                        ],
                    ]);
                }
            });
    }
}
