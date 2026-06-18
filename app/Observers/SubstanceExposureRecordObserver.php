<?php

namespace App\Observers;

use App\Models\HsEvent;
use App\Models\SubstanceExposureRecord;
use App\Services\HealthSafety\HsEventService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Log;

class SubstanceExposureRecordObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly HsEventService $hsEventService,
    ) {}

    public function created(SubstanceExposureRecord $record): void
    {
        try {
            $record->loadMissing(['relatedIncident:id,client_id,shift_id']);

            $this->hsEventService->recordEvent([
                'source' => $record,
                'event_category' => HsEvent::CATEGORY_EXPOSURE,
                'severity' => $record->medical_attention_sought
                    ? HsEvent::SEVERITY_HIGH
                    : HsEvent::SEVERITY_MEDIUM,
                'occurred_at' => $record->exposed_at,
                'reported_at' => $record->created_at,
                'site_id' => $record->site_id,
                'client_id' => $record->relatedIncident?->client_id,
                'staff_id' => $record->user_id,
                'shift_id' => $record->relatedIncident?->shift_id,
                'created_by' => $record->created_by,
            ]);
        } catch (\Throwable $e) {
            Log::error('SubstanceExposureRecordObserver: HsEvent creation failed', [
                'substance_exposure_record_id' => $record->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
