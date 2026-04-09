<?php

namespace App\Observers;

use App\Domain\Finance\Jobs\ProcessFinancialEventJob;
use App\Domain\Finance\Models\FinFinancialEvent;
use App\Models\AssetMaintenanceLog;
use Illuminate\Support\Facades\Log;

class AssetMaintenanceLogObserver
{
    /**
     * Dispatch GL posting job when a maintenance log is created.
     *
     * Trigger: AssetMaintenanceLog::created (cost > 0)
     * GL Entry: DR 6300 Equipment Maintenance / CR 2000 AP
     */
    public function created(AssetMaintenanceLog $log): void
    {
        if (! $log->cost || bccomp((string) $log->cost, '0', 2) <= 0) {
            return;
        }

        try {
            $asset = $log->asset;
            if (! $asset) {
                return;
            }

            $orgId = $asset->site?->tenant_id;
            if (! $orgId) {
                return;
            }

            $accountConfig = config('finance.event_accounts.asset_maintenance_expense');

            ProcessFinancialEventJob::dispatch([
                'organization_id' => $orgId,
                'source_type' => AssetMaintenanceLog::class,
                'source_id' => $log->id,
                'event_type' => 'asset_maintenance_expense',
                'description' => "Asset maintenance: {$log->type} — {$asset->name}"
                    . ($log->vendor ? " (vendor: {$log->vendor})" : ''),
                'amount' => (string) $log->cost,
                'event_date' => ($log->performed_at ?? $log->created_at)->toDateString(),
                'debit_account_code' => $accountConfig['debit'],
                'payment_type' => FinFinancialEvent::PAYMENT_AP,
                'journal_type' => $accountConfig['journal_type'],
                'site_id' => $asset->site_id,
                'asset_id' => $asset->id,
                'staff_id' => $log->performed_by_user_id,
                'source_updated_at' => $log->updated_at?->toISOString(),
            ]);
        } catch (\Throwable $e) {
            Log::error("AssetMaintenanceLogObserver: Failed to dispatch GL job for log #{$log->id}: {$e->getMessage()}");
        }
    }
}
