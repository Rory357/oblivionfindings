<?php

namespace App\Observers;

use App\Models\HsEvent;
use App\Models\SiteInspectionRecord;
use App\Services\HealthSafety\HsEventService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Log;

class SiteInspectionRecordObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly HsEventService $hsEventService,
    ) {}

    public function created(SiteInspectionRecord $record): void
    {
        if ($record->result !== 'fail') {
            return;
        }

        try {
            $this->hsEventService->recordEvent([
                'source' => $record,
                'event_category' => HsEvent::CATEGORY_INSPECTION_FAILURE,
                'severity' => HsEvent::SEVERITY_HIGH,
                'occurred_at' => $record->completed_at ?? $record->created_at,
                'reported_at' => $record->created_at,
                'site_id' => $record->site_id,
                'staff_id' => $record->completed_by_user_id,
                'organization_id' => $record->tenant_id,
                'created_by' => $record->completed_by_user_id,
            ]);
        } catch (\Throwable $e) {
            Log::error('SiteInspectionRecordObserver: HsEvent creation failed', [
                'site_inspection_record_id' => $record->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
