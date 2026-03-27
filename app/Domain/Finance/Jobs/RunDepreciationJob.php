<?php

namespace App\Domain\Finance\Jobs;

use App\Domain\Finance\Models\FinFixedAsset;
use App\Domain\Finance\Services\FixedAssetService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class RunDepreciationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(FixedAssetService $service): void
    {
        $depreciationDate = Carbon::now()->startOfMonth()->toDateString();

        $orgIds = FinFixedAsset::active()
            ->distinct()
            ->pluck('organization_id');

        foreach ($orgIds as $orgId) {
            try {
                $results = $service->runDepreciation($orgId, $depreciationDate);

                Log::info("Fixed asset depreciation: processed " . count($results) . " asset(s) for organisation #{$orgId}.");
            } catch (\Throwable $e) {
                Log::error("Fixed asset depreciation failed for organisation #{$orgId}: {$e->getMessage()}");
            }
        }
    }
}
