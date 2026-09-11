<?php

namespace App\Jobs;

use App\Models\FleetVehicleStateSnapshot;
use App\Services\Fleet\FleetSignalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DetectFleetOfflineDevices implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $timeout = 120;

    public function __construct() {}

    public function handle(FleetSignalService $signals): void
    {
        $configuredMinutes = config('fleet.signals.offline_after_minutes', 15);
        $offlineMinutes = is_int($configuredMinutes) || is_string($configuredMinutes)
            ? filter_var($configuredMinutes, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;
        if ($offlineMinutes === false) {
            throw new InvalidArgumentException('Fleet offline timeout must be a positive whole number of minutes.');
        }
        $threshold = now()->subMinutes($offlineMinutes);

        FleetVehicleStateSnapshot::query()
            ->where('status', 'online')
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '<', $threshold)
            ->chunkById(200, function ($rows) use ($signals, $threshold) {
                foreach ($rows as $state) {
                    DB::transaction(function () use ($state, $signals, $threshold): void {
                        // A heartbeat or another detector may have changed this candidate.
                        $current = FleetVehicleStateSnapshot::query()->whereKey($state->asset_id)
                            ->where('status', 'online')->whereNotNull('last_seen_at')
                            ->where('last_seen_at', '<', $threshold)->lockForUpdate()->first();
                        if ($current === null) {
                            return;
                        }
                        $signals->emitOffline($current);
                        $current->update(['status' => 'offline']);
                    }, 3);
                }
            });
    }
}
