<?php

namespace App\Observers;

use App\Models\HsEvent;
use App\Models\SubstanceExposureRecord;
use App\Services\HealthSafety\ChemicalExposureDeliveryService;
use App\Services\HealthSafety\ChemicalExposureSystemDeliveryService;
use App\Services\HealthSafety\HsEventReviewFlagService;
use App\Services\HealthSafety\HsEventService;
use App\Services\HealthSafety\NotifiableEventClassifier;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Log;

/**
 * Job 3 (Stephan, 26 Oct): every saved exposure command stages a revision-bound delivery, and the system delivers it to
 * the H&S register straight after the save commits. Failures stay pending for the every-minute recovery and show to
 * exposure managers. Old-style records (no governed provenance, source_schema_version 0) keep main's direct event,
 * so no exposure can ever be saved without reaching the register and its WorkSafe review.
 */
final class SubstanceExposureRecordObserver implements ShouldHandleEventsAfterCommit
{
    private const SEVERITY_RANK = [HsEvent::SEVERITY_LOW => 0, HsEvent::SEVERITY_MEDIUM => 1, HsEvent::SEVERITY_HIGH => 2, HsEvent::SEVERITY_CRITICAL => 3];

    /** Facts whose correction after a signed WorkSafe decision needs a person to look again. */
    private const BOOKKEEPING = ['created_at', 'updated_at', 'updated_by', 'source_schema_version', 'source_revision'];

    public function __construct(
        private readonly ChemicalExposureDeliveryService $delivery,
        private readonly ChemicalExposureSystemDeliveryService $system,
        private readonly HsEventService $hsEventService,
        private readonly NotifiableEventClassifier $classifier,
        private readonly HsEventReviewFlagService $flags,
    ) {}

    public function created(SubstanceExposureRecord $source): void
    {
        $this->delivery->sourceCommitted($source);
        if ($this->governed($source)) {
            $this->deliver($source);

            return;
        }
        $this->recordHsEvent($source);
    }

    public function updated(SubstanceExposureRecord $source): void
    {
        $this->delivery->sourceCommitted($source);
        if ($this->governed($source)) {
            $this->deliver($source);

            return;
        }
        $this->raiseHsEventSeverity($source);
    }

    private function governed(SubstanceExposureRecord $source): bool
    {
        return (int) $source->source_schema_version >= 1;
    }

    private function deliver(SubstanceExposureRecord $source): void
    {
        try {
            $this->system->afterCommit((int) $source->getKey());
        } catch (\Throwable $e) {
            // The pending delivery stays for the every-minute recovery and shows to exposure managers.
            Log::error('SubstanceExposureRecordObserver: automatic H&S delivery could not start', [
                'substance_exposure_record_id' => $source->getKey(),
                'error' => $e::class,
            ]);
        }
    }

    /**
     * Old-style records only: a corrected exposure raises its H&S event's severity and a material escalation marks an
     * investigation required. Severity is never lowered and the WorkSafe decision is never touched. A closed event is
     * raised but never reopened, and is flagged for review; a correction after a signed WorkSafe decision is flagged.
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
            $before = clone $event;
            $severity = $this->severityFor($record);
            if ((self::SEVERITY_RANK[$severity] ?? 0) > (self::SEVERITY_RANK[$event->severity] ?? 0)) {
                $this->hsEventService->syncSeverity($event, $severity);
            }
            $corrected = array_diff(array_keys($record->getChanges()), self::BOOKKEEPING) !== [];
            $this->flags->afterSourceChange($before, $record, null, (string) $event->fresh()->severity, $corrected);
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
