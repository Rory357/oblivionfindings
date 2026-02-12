<?php

namespace App\Domain\Governance\Jobs;

use App\Domain\Governance\Models\RiskHeatmapSnapshot;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CaptureRiskHeatmapSnapshot implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        // Only capture one snapshot per day
        $today = now()->toDateString();
        if (!RiskHeatmapSnapshot::where('snapshot_date', $today)->exists()) {
            RiskHeatmapSnapshot::capture();
        }
    }
}
