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
    private const SEVERITY_RANK = [HsEvent::SEVERITY_LOW => 0, HsEvent::SEVERITY_MEDIUM => 1, HsEvent::SEVERITY_HIGH => 2, HsEvent::SEVERITY_CRITICAL => 3];

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
        $this->raiseHsEventSeverity($source);
    }

    /**
     * Interim until the delivery pipeline is finished (job 3): a corrected exposure (say, treatment none → hospitalisation)
     * raises its open H&S event's severity, and a material escalation marks an investigation required. Severity is never
     * lowered, the WorkSafe decision is never touched, and a closed event is left as recorded (logged for review).
     */
    private function raiseHsEventSeverity(SubstanceExposureRecord $record): void
    {
        try {
            $event = HsEvent::query()
                ->where('idempotency_key', HsEvent::buildIdempotencyKey(SubstanceExposureRecord::class, $record->getKey(), HsEvent::CATEGORY_EXPOSURE))
                ->first();
            if ($event === null) {
                return;
            }
            $severity = $this->severityFor($record);
            if ((self::SEVERITY_RANK[$severity] ?? 0) <= (self::SEVERITY_RANK[$event->severity] ?? 0)) {
                return;
            }
            if ($event->status === HsEvent::STATUS_CLOSED) {
                Log::warning('SubstanceExposureRecordObserver: corrected exposure would raise a closed H&S event', [
                    'substance_exposure_record_id' => $record->id, 'hs_event_id' => $event->id,
                    'from' => $event->severity, 'to' => $severity,
                ]);

                return;
            }
            $this->hsEventService->syncSeverity($event, $severity);
        } catch (\Throwable $e) {
            Log::error('SubstanceExposureRecordObserver: HsEvent severity raise failed', [
                'substance_exposure_record_id' => $record->id,
                'error' => $e->getMessage(),
            ]);
        }
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
