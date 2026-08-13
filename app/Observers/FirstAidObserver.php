<?php

namespace App\Observers;

use App\Models\FirstAidRecord;
use App\Models\HsEvent;
use App\Services\AuditLogger;
use App\Services\HealthSafety\HsEventService;
use App\Services\HealthSafety\NotifiableEventClassifier;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Log;

/**
 * Auto-escalation: a SIGNIFICANT first-aid treatment (ambulance called or sent to hospital)
 * that is NOT linked to an incident is recorded as an HsEvent so it enters the H&S events
 * spine + governance — a safety net for when a coordinator forgets to raise an incident.
 *
 * DE-DUP CONTRACT (critical): the user-driven escalation path (FirstAidController::linkIncident
 * → ClientIncident → ClientIncidentObserver) ALSO raises an HsEvent. Because the idempotency key
 * is keyed on the source model, the two keys never collide, so dedup cannot save us — this
 * observer must NOT also escalate a linked record, else one real event yields two HsEvents.
 * We therefore escalate ONLY when related_incident_id is null. If it later links to an incident,
 * provenance is recorded but H&S alone owns the eventual canonical closure decision.
 */
class FirstAidObserver implements ShouldHandleEventsAfterCommit
{
    /** Mirrors FirstAidController::REPORTABLE_OUTCOME — the single source of truth. */
    private const REPORTABLE_OUTCOME = 'sent_to_hospital';

    public function __construct(
        private readonly HsEventService $hsEventService,
        private readonly NotifiableEventClassifier $classifier,
    ) {}

    public function created(FirstAidRecord $record): void
    {
        // Linked at create → the incident path owns governance. Do nothing.
        if ($record->related_incident_id) {
            return;
        }

        if ($this->isSignificant($record)) {
            $this->recordHsEvent($record);
        }
    }

    public function updated(FirstAidRecord $record): void
    {
        // Just linked to an incident → ClientIncidentObserver now owns the event; retire ours.
        if ($record->wasChanged('related_incident_id') && $record->related_incident_id) {
            $this->retireHsEvent($record);

            return;
        }

        // Became significant while still UNLINKED (e.g. outcome edited to sent_to_hospital).
        if ($record->related_incident_id === null
            && $this->isSignificant($record)
            && $this->becameSignificant($record)
        ) {
            $this->recordHsEvent($record);
        }
    }

    /* ------------------------------------------------------------------ */

    /** Reuse the controller's reportable predicate: ambulance OR sent-to-hospital. */
    private function isSignificant(FirstAidRecord $record): bool
    {
        return (bool) $record->ambulance_called
            || $record->treatment_outcome === self::REPORTABLE_OUTCOME;
    }

    private function becameSignificant(FirstAidRecord $record): bool
    {
        $wasSignificant = (bool) $record->getOriginal('ambulance_called')
            || $record->getOriginal('treatment_outcome') === self::REPORTABLE_OUTCOME;

        return ! $wasSignificant;
    }

    private function recordHsEvent(FirstAidRecord $record): void
    {
        try {
            // Hospital admission → HSWA s.23 notifiable injury/illness; ambulance-only (assessed,
            // not admitted) is significant enough to enter the spine but not auto-notifiable.
            $harm = $record->treatment_outcome === self::REPORTABLE_OUTCOME
                ? NotifiableEventClassifier::HARM_HOSPITALISATION
                : NotifiableEventClassifier::HARM_MEDICAL;
            $severity = 'high';

            $this->hsEventService->recordEvent([
                'source' => $record,
                'event_category' => HsEvent::CATEGORY_INJURY,
                'severity' => $severity,
                'occurred_at' => $record->treatment_date,
                'reported_at' => $record->created_at,
                'site_id' => $record->site_id,
                // Staff treatments link a user; client treatments carry client_id.
                'staff_id' => $record->treated_person_type === 'client' ? null : $record->treated_person_id,
                'client_id' => $record->treated_person_type === 'client' ? $record->client_id : null,
                'worksafe_notifiable' => $this->classifier->isNotifiable($harm, $severity),
                'created_by' => $record->created_by,
            ]);
        } catch (\Throwable $e) {
            Log::error('FirstAidObserver: HsEvent creation failed', [
                'first_aid_record_id' => $record->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Record supersession provenance without bypassing canonical H&S closure. */
    private function retireHsEvent(FirstAidRecord $record): void
    {
        try {
            $key = HsEvent::buildIdempotencyKey(get_class($record), $record->getKey(), HsEvent::CATEGORY_INJURY);
            $hsEvent = HsEvent::where('idempotency_key', $key)->first();

            if ($hsEvent) {
                AuditLogger::log('healthSafety.event.firstAidSupersessionRecorded', $hsEvent, [
                    'first_aid_record_id' => $record->id,
                    'linked_incident_id' => $record->related_incident_id,
                    'closure_required' => $hsEvent->status !== HsEvent::STATUS_CLOSED,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('FirstAidObserver: failed to retire superseded HsEvent', [
                'first_aid_record_id' => $record->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
