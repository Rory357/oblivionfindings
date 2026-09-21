<?php

namespace App\Observers;

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

    /** Keep the independently required H&S fault record in sync. Finance owns
     * invoice approval and posting through the canonical FinBill path.
     */
    public function updated(FleetWorkOrder $workOrder): void
    {
        if ($workOrder->wasChanged('priority') && $this->isSafetyRelevantFault($workOrder)) {
            $this->recordHsEvent($workOrder);
            $this->syncHsEventSeverity($workOrder);
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
