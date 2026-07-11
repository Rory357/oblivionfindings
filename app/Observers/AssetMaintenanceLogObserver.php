<?php

namespace App\Observers;

use App\Domain\Finance\Services\AccountsPayableService;
use App\Models\AssetMaintenanceLog;
use Illuminate\Support\Facades\Log;

class AssetMaintenanceLogObserver
{
    /**
     * Capture-at-source: a maintenance log with a cost becomes a DRAFT
     * accounts-payable bill against the log's vendor.
     *
     * Trigger: AssetMaintenanceLog::created (cost > 0)
     *
     * This REPLACES the old direct GL post (DR 6300 / CR 2000 with no bill):
     * maintenance is genuine on-account vendor spend, and an AP credit with no
     * bill can never be settled by a payment run. The draft bill is GL-safe —
     * approving it posts the balanced journal AND creates the FinCostAllocation
     * rows (site/asset, event_type asset_maintenance_expense) that site cost
     * reporting reads, so the move from direct posting loses no attribution.
     * Idempotent on the "MAINT-{id}" reference; non-fatal.
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

            app(AccountsPayableService::class)->captureOperationalBill($orgId, [
                'reference' => "MAINT-{$log->id}",
                'vendor_name' => $log->vendor ?: config('finance.capture.maintenance_vendor', 'Maintenance Contractor'),
                'vendor_type' => 'contractor',
                'description' => "Asset maintenance: {$log->type} — {$asset->name}",
                'amount' => (float) $log->cost,
                'account_code' => $accountConfig['debit'],
                // cost is a single recorded figure with no GST breakdown
                'gst_rate' => 0,
                'bill_date' => ($log->performed_at ?? $log->created_at)->toDateString(),
                'site_id' => $asset->site_id,
                'asset_id' => $asset->id,
                'allocation_event_type' => 'asset_maintenance_expense',
                'notes' => "Auto-captured from maintenance log #{$log->id}.",
            ]);
        } catch (\Throwable $e) {
            Log::error("AssetMaintenanceLogObserver: Failed to capture bill for log #{$log->id}: {$e->getMessage()}");
        }
    }
}
