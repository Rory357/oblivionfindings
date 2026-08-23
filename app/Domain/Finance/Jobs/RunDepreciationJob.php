<?php

namespace App\Domain\Finance\Jobs;

use App\Domain\Finance\Models\FinFixedAsset;
use App\Domain\Finance\Services\FixedAssetService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class RunDepreciationJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    public int $uniqueFor = 7200;

    public function handle(FixedAssetService $service): void
    {
        $depreciationDate = Carbon::now()->startOfMonth()->toDateString();
        $firstFailure = null;
        $failedOrganizationIds = [];

        $orgIds = FinFixedAsset::active()
            ->distinct()
            ->orderBy('organization_id')
            ->pluck('organization_id');

        foreach ($orgIds as $orgId) {
            try {
                $results = $service->runDepreciation($orgId, $depreciationDate);

                Log::info('Fixed asset depreciation: processed '.count($results)." asset(s) for organisation #{$orgId}.");
            } catch (\Throwable $e) {
                Log::error("Fixed asset depreciation failed for organisation #{$orgId}: {$e->getMessage()}");
                $firstFailure ??= $e;
                $failedOrganizationIds[] = $orgId;
            }
        }

        if ($firstFailure !== null) {
            throw new RuntimeException(
                'Fixed asset depreciation failed for organisation(s): '.implode(', ', $failedOrganizationIds).'.',
                0,
                $firstFailure,
            );
        }
    }

    public function uniqueId(): string
    {
        return 'fixed-asset-depreciation';
    }
}
