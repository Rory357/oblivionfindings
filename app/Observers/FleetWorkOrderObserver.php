<?php

namespace App\Observers;

use App\Domain\Finance\Jobs\ProcessFinancialEventJob;
use App\Domain\Finance\Models\FinFinancialEvent;
use App\Models\FleetWorkOrder;
use Illuminate\Support\Facades\Log;

class FleetWorkOrderObserver
{
    /**
     * Dispatch GL posting job when a work order is completed.
     *
     * Trigger: FleetWorkOrder::updated (status → completed, cost > 0)
     * GL Entry: DR 6210 Vehicle Maintenance / CR 2000 AP
     */
    public function updated(FleetWorkOrder $workOrder): void
    {
        if (! $workOrder->wasChanged('status') || $workOrder->status !== 'completed') {
            return;
        }

        $cost = $workOrder->actual_cost ?? $workOrder->estimated_cost;
        if (! $cost || bccomp((string) $cost, '0', 2) <= 0) {
            return;
        }

        if ($workOrder->journal_id) {
            return;
        }

        try {
            $asset = $workOrder->asset;
            if (! $asset) {
                return;
            }

            $orgId = $workOrder->tenant_id;
            if (! $orgId) {
                $orgId = $asset->site?->tenant_id;
            }
            if (! $orgId) {
                return;
            }

            $accountConfig = config('finance.event_accounts.fleet_maintenance_expense');

            ProcessFinancialEventJob::dispatch([
                'organization_id' => $orgId,
                'source_type' => FleetWorkOrder::class,
                'source_id' => $workOrder->id,
                'event_type' => 'fleet_maintenance_expense',
                'description' => "Fleet maintenance: {$workOrder->title} — {$asset->name}"
                    . ($workOrder->category ? " [{$workOrder->category}]" : ''),
                'amount' => (string) $cost,
                'event_date' => ($workOrder->completed_at ?? now())->toDateString(),
                'debit_account_code' => $accountConfig['debit'],
                'payment_type' => FinFinancialEvent::PAYMENT_AP,
                'journal_type' => $accountConfig['journal_type'],
                'site_id' => $asset->site_id,
                'asset_id' => $asset->id,
                'source_updated_at' => $workOrder->updated_at?->toISOString(),
            ]);
        } catch (\Throwable $e) {
            Log::error("FleetWorkOrderObserver: Failed to dispatch GL job for work order #{$workOrder->id}: {$e->getMessage()}");
        }
    }
}
