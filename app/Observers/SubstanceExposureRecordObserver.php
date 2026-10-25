<?php

namespace App\Observers;

use App\Models\HsEvent;
use App\Models\SubstanceExposureRecord;
use App\Services\HealthSafety\ChemicalExposureDeliveryService;
use App\Services\HealthSafety\HsEventService;
use App\Services\HealthSafety\NotifiableEventClassifier;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Log;

/**
 * New source commands retain revision-bound delivery intents. No current-incident fallback certifies historical context.
 *
 * Codex's delivery consumer for those intents is not mounted in this build, so every new exposure also gets its H&S
 * event here, as on main: an exposure reaches the H&S register and its WorkSafe review the moment it is recorded.
 * Without this, no new exposure would ever reach either.
 */
final class SubstanceExposureRecordObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly ChemicalExposureDeliveryService $delivery,
        private readonly HsEventService $hsEventService,
        private readonly NotifiableEventClassifier $classifier,
    ) {}

    public function created(SubstanceExposureRecord $source): void
    {
        $this->delivery->sourceCommitted($source);
        $this->recordHsEvent($source);
    }

    public function updated(SubstanceExposureRecord $source): void
    {
        $this->delivery->sourceCommitted($source);
    }

    /** Main's exposure H&S event: same source, severity and preliminary WorkSafe trigger. */
    private function recordHsEvent(SubstanceExposureRecord $record): void
    {
        try {
            $record->loadMissing(['relatedIncident:id,client_id,shift_id']);
            $severity = $this->severityFor($record);

            $this->hsEventService->recordEvent([
                'source' => $record,
                'event_category' => HsEvent::CATEGORY_EXPOSURE,
                'severity' => $severity,
                // Reduced source fields can only raise a preliminary positive trigger. Generic severity and every
                // negative path still need the full WorkSafe tree and qualified H&S sign-off.
                'worksafe_notifiable' => $this->classifier->isNotifiable($record->medical_treatment, $severity),
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

    private function severityFor(SubstanceExposureRecord $record): string
    {
        return match ($record->medical_treatment) {
            'death' => HsEvent::SEVERITY_CRITICAL,
            'hospitalisation', 'medical' => HsEvent::SEVERITY_HIGH,
            'first_aid' => HsEvent::SEVERITY_MEDIUM,
            'none' => HsEvent::SEVERITY_LOW,
            default => $record->medical_attention_sought ? HsEvent::SEVERITY_HIGH : HsEvent::SEVERITY_MEDIUM,
        };
    }
}
