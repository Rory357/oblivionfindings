<?php

namespace App\Domain\Governance\Jobs;

use App\Domain\Governance\Models\ComplianceObligation;
use App\Domain\Governance\Models\RiskHeatmapSnapshot;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Monthly record of the risk register for Risk trends — scheduled on the 1st
 * of each month in routes/console.php.
 */
class CaptureRiskHeatmapSnapshot implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        // At most one record per NZ calendar day.
        if (! RiskHeatmapSnapshot::whereDate('snapshot_date', ComplianceObligation::nzToday())->exists()) {
            RiskHeatmapSnapshot::capture();
        }
    }
}
