<?php

namespace App\Domain\Finance\Jobs;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Services\BudgetActualsService;
use App\Domain\Finance\Services\BudgetVarianceAlertService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncBudgetActualsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(BudgetActualsService $service, BudgetVarianceAlertService $alerts): void
    {
        $orgIds = FinAccount::query()
            ->distinct()
            ->pluck('organization_id')
            ->filter();

        foreach ($orgIds as $orgId) {
            try {
                $result = $service->syncActuals($orgId);

                $alerted = $alerts->dispatchForOrganization((int) $orgId);

                Log::info("Budget actuals sync: updated {$result['updated']} line items for organisation #{$orgId}.", [
                    'total_budget' => $result['total_budget'],
                    'total_actual' => $result['total_actual'],
                    'variance' => $result['variance'],
                    'variance_alerts_sent' => $alerted,
                ]);
            } catch (\Throwable $e) {
                Log::error("Budget actuals sync failed for organisation #{$orgId}: {$e->getMessage()}", [
                    'exception' => $e,
                ]);
                throw $e;
            }
        }
    }
}
