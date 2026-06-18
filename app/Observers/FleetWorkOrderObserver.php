<?php

namespace App\Observers;

use App\Domain\Finance\Jobs\ProcessFinancialEventJob;
use App\Domain\Finance\Models\FinFinancialEvent;
use App\Models\FleetWorkOrder;
use App\Models\HsEvent;
use App\Services\HealthSafety\HsEventService;
use Illuminate\Support\Facades\Log;

class FleetWorkOrderObserver
{
    public function __construct(
        private readonly HsEventService $hsEventService,
    ) {}

    /**
     * High/critical work orders represent safety-relevant equipment faults for
     * the H&S governance register. Lower-priority maintenance stays in Fleet.
     */
    public function created(FleetWorkOrder $workOrder): void
    {
        if (! $this->isSafetyRelevantFault($workOrder)) {
            return;
        }

        $this->recordHsEvent($workOrder);
    }

    /**
     * Dispatch GL posting job when a work order is completed.
     *
     * Trigger: FleetWorkOrder::updated (status → completed, cost > 0)
     * GL Entry: DR 6210 Vehicle Maintenance / CR 2000 AP
     */
    public function updated(FleetWorkOrder $workOrder): void
    {
        if ($workOrder->wasChanged('priority') && $this->isSafetyRelevantFault($workOrder)) {
            $this->recordHsEvent($workOrder);
            $this->syncHsEventSeverity($workOrder);
        }

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

    private function recordHsEvent(FleetWorkOrder $workOrder): void
    {
        try {
            $workOrder->loadMissing(['asset:id,site_id']);

            $this->hsEventService->recordEvent([
                'source' => $workOrder,
                'event_category' => HsEvent::CATEGORY_EQUIPMENT_FAULT,
                'severity' => $this->hsSeverity($workOrder),
                'occurred_at' => $workOrder->created_at,
                'reported_at' => $workOrder->created_at,
                'site_id' => $workOrder->asset?->site_id,
                'asset_id' => $workOrder->asset_id,
                'staff_id' => $workOrder->reported_by_user_id,
                'organization_id' => $workOrder->tenant_id,
                'created_by' => $workOrder->reported_by_user_id,
            ]);
        } catch (\Throwable $e) {
            Log::error('FleetWorkOrderObserver: HsEvent creation failed', [
                'fleet_work_order_id' => $workOrder->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function syncHsEventSeverity(FleetWorkOrder $workOrder): void
    {
        try {
            $key = HsEvent::buildIdempotencyKey(
                get_class($workOrder),
                $workOrder->getKey(),
                HsEvent::CATEGORY_EQUIPMENT_FAULT,
            );
            $hsEvent = HsEvent::where('idempotency_key', $key)->first();

            if ($hsEvent) {
                $this->hsEventService->syncSeverity($hsEvent, $this->hsSeverity($workOrder));
            }
        } catch (\Throwable $e) {
            Log::error('FleetWorkOrderObserver: HsEvent severity sync failed', [
                'fleet_work_order_id' => $workOrder->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function isSafetyRelevantFault(FleetWorkOrder $workOrder): bool
    {
        return in_array($workOrder->priority, ['high', 'critical', 'urgent'], true);
    }

    private function hsSeverity(FleetWorkOrder $workOrder): string
    {
        return match ($workOrder->priority) {
            'critical', 'urgent' => HsEvent::SEVERITY_CRITICAL,
            'high' => HsEvent::SEVERITY_HIGH,
            'medium' => HsEvent::SEVERITY_MEDIUM,
            default => HsEvent::SEVERITY_LOW,
        };
    }
}
