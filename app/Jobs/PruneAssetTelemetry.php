<?php

namespace App\Jobs;

use App\Models\AssetTelemetryHistory;
use App\Models\AssetTelemetrySnapshot;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PruneAssetTelemetry implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
    }

    public function handle(): void
    {
        $snapshotDays = (int) config('fleet.retention.asset_snapshot_days', 365);
        $historyDays = (int) config('fleet.retention.asset_history_days', 730);

        $snapshotCutoff = now()->subDays($snapshotDays);
        $historyCutoff = now()->subDays($historyDays);

        AssetTelemetrySnapshot::query()
            ->where('occurred_at', '<', $snapshotCutoff)
            ->delete();

        AssetTelemetryHistory::query()
            ->where('summary_date', '<', $historyCutoff->toDateString())
            ->delete();
    }
}
