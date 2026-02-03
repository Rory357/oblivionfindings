<?php

namespace App\Jobs;

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

    public function handle(): void
    {
        $days = (int) config('fleet.retention.telemetry_days', 365);
        $cutoff = now()->subDays($days);

        FleetTelemetryEvent::query()
            ->where('occurred_at', '<', $cutoff)
            ->delete();
    }
}
