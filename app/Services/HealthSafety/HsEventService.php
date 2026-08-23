<?php

namespace App\Services\HealthSafety;

use App\Domain\Governance\Models\NotifiableIncident;
use App\Models\ClientIncident;
use App\Models\HsEvent;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ControlRoom\ComprehensiveAlertBridgeService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Central service for creating and updating HsEvent records.
 *
 * All source-model observers delegate to this service rather than
 * creating HsEvents directly. This guarantees:
 *  - consistent idempotency
 *  - normalised severity
 *  - safe Control Room bridge dispatch
 *  - escalation-aware dedup bypass
 */
class HsEventService
{
    public function __construct(
        private readonly ComprehensiveAlertBridgeService $bridge,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Public API */
    /* ------------------------------------------------------------------ */

    /**
     * Create an HsEvent for a source model if one does not already exist.
     *
     * Returns the HsEvent (new or existing).
     * Returns null only if creation fails for an unexpected reason.
     *
     * @param  array{
     *     source: Model,
     *     event_category: string,
     *     severity: string,
     *     occurred_at?: \DateTimeInterface|string|null,
     *     reported_at?: \DateTimeInterface|string|null,
     *     site_id?: int|null,
     *     client_id?: int|null,
     *     staff_id?: int|null,
     *     asset_id?: int|null,
     *     shift_id?: int|null,
     *     worksafe_notifiable?: bool,
     *     worksafe_decided_at?: \DateTimeInterface|string|null,
     *     worksafe_decided_by_user_id?: int|null,
     *     worksafe_decision_reason?: string|null,
     *     worksafe_decision_source?: string|null,
     *     worksafe_decision_tree_version?: string|null,
     *     worksafe_source_effective_date?: \DateTimeInterface|string|null,
     *     created_by?: int|null,
     *     control_room_alert_id?: int|null,
     *     handover_status?: string,
     *     owner_user_id?: int|null,
     * } $data
     */
    public function recordEvent(array $data): ?HsEvent
    {
        $source = $data['source'];
        $category = $data['event_category'];
        $severity = self::normaliseSeverity($data['severity']);
        $hasWorksafeDecision = array_key_exists('worksafe_notifiable', $data)
            && $data['worksafe_notifiable'] !== null;
        $worksafeNotifiable = $hasWorksafeDecision
            ? (bool) $data['worksafe_notifiable']
            : null;
        $candidateDecisionSource = trim((string) ($data['worksafe_decision_source']
            ?? ($source instanceof ClientIncident ? 'incident_report' : 'classifier')));
        $decisionActorId = $hasWorksafeDecision
            ? ($candidateDecisionSource === 'classifier'
                ? ($data['worksafe_decided_by_user_id'] ?? null)
                : ($data['worksafe_decided_by_user_id']
                    ?? $data['created_by']
                    ?? auth()->id()
                    ?? $data['staff_id']
                    ?? null))
            : null;
        $decisionTreeVersion = trim((string) ($data['worksafe_decision_tree_version']
            ?? NotifiableEventClassifier::DECISION_TREE_VERSION));
        $sourceEffectiveDate = $data['worksafe_source_effective_date']
            ?? NotifiableEventClassifier::SOURCE_EFFECTIVE_DATE;
        $decisionActor = is_numeric($decisionActorId)
            ? User::query()->find((int) $decisionActorId)
            : null;
        $hasCompleteDecision = $hasWorksafeDecision
            && $decisionActor?->canDo('hazards.manage') === true
            && in_array($candidateDecisionSource, ['manual', 'incident_report', 'classifier'], true)
            && $decisionTreeVersion !== ''
            && filled($sourceEffectiveDate);
        $decisionSource = $hasCompleteDecision
            ? $candidateDecisionSource
            : null;
        $decisionReason = trim((string) ($data['worksafe_decision_reason'] ?? ''));
        if ($hasCompleteDecision && mb_strlen($decisionReason) < 10) {
            $classification = $worksafeNotifiable
                ? 'WorkSafe-notifiable'
                : 'not WorkSafe-notifiable';
            $decisionReason = "The source record classified this event as {$classification}.";
        }
        $decisionReason = $hasCompleteDecision ? $decisionReason : null;
        $decisionAt = $hasCompleteDecision
            ? ($data['worksafe_decided_at'] ?? $data['reported_at'] ?? now())
            : null;

        $idempotencyKey = HsEvent::buildIdempotencyKey(
            get_class($source),
            $source->getKey(),
            $category,
        );

        // ── Idempotency: return existing if already recorded ──
        $existing = HsEvent::where('idempotency_key', $idempotencyKey)->first();

        if ($existing) {
            return $existing;
        }

        try {
            $hsEvent = DB::transaction(function () use (
                $category,
                $data,
                $decisionActorId,
                $decisionAt,
                $decisionReason,
                $decisionSource,
                $decisionTreeVersion,
                $hasCompleteDecision,
                $idempotencyKey,
                $severity,
                $source,
                $sourceEffectiveDate,
                $worksafeNotifiable,
            ): HsEvent {
                $event = HsEvent::create([
                    'reference_number' => HsEvent::generateReferenceNumber(),
                    'source_type' => get_class($source),
                    'source_id' => $source->getKey(),
                    'event_category' => $category,
                    'severity' => $severity,
                    'status' => HsEvent::STATUS_OPEN,
                    'occurred_at' => $data['occurred_at'] ?? null,
                    'reported_at' => $data['reported_at'] ?? now(),
                    'site_id' => $data['site_id'] ?? null,
                    'client_id' => $data['client_id'] ?? null,
                    'staff_id' => $data['staff_id'] ?? null,
                    'asset_id' => $data['asset_id'] ?? null,
                    'shift_id' => $data['shift_id'] ?? null,
                    'worksafe_notifiable' => $hasCompleteDecision ? $worksafeNotifiable : null,
                    'worksafe_decided_at' => $decisionAt,
                    'worksafe_decided_by_user_id' => $hasCompleteDecision ? $decisionActorId : null,
                    'worksafe_decision_reason' => $decisionReason,
                    'worksafe_decision_source' => $decisionSource,
                    'worksafe_decision_tree_version' => $hasCompleteDecision ? $decisionTreeVersion : null,
                    'worksafe_source_effective_date' => $hasCompleteDecision ? $sourceEffectiveDate : null,
                    'worksafe_status' => $hasCompleteDecision && $worksafeNotifiable
                        ? HsEvent::WORKSAFE_PENDING
                        : null,
                    'investigation_required' => $this->requiresInvestigation(
                        $severity,
                        $hasCompleteDecision && $worksafeNotifiable,
                    ),
                    'control_room_alert_id' => $data['control_room_alert_id'] ?? null,
                    'handover_status' => $data['handover_status'] ?? HsEvent::HANDOVER_NOT_REQUIRED,
                    'owner_user_id' => $data['owner_user_id'] ?? null,
                    'idempotency_key' => $idempotencyKey,
                    'created_by' => $data['created_by'] ?? auth()->id(),
                ]);

                if ($hasCompleteDecision) {
                    AuditLogger::logOrFail(
                        'healthSafety.event.worksafeDecisionRecorded',
                        $event,
                        [
                            'actor_id' => (int) $decisionActorId,
                            'before' => $this->emptyWorksafeDecisionSnapshot(),
                            'after' => $this->worksafeDecisionSnapshot($event),
                        ],
                    );
                }

                return $event;
            }, 3);

            Log::info('HsEventService: event created', [
                'hs_event_id' => $hsEvent->id,
                'reference' => $hsEvent->reference_number,
                'source' => class_basename($source).':'.$source->getKey(),
                'category' => $category,
                'severity' => $severity,
            ]);

            return $hsEvent;
        } catch (\Throwable $e) {
            // Catch unique-constraint race condition (concurrent request created the same key)
            if (str_contains($e->getMessage(), 'Duplicate') || str_contains($e->getMessage(), 'UNIQUE')) {
                return HsEvent::where('idempotency_key', $idempotencyKey)->first();
            }

            Log::error('HsEventService: failed to create event', [
                'source' => class_basename($source).':'.$source->getKey(),
                'category' => $category,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Project a source-model severity onto an existing H&S event.
     *
     * Callers own any monotonic floor or linked Control Room journey update.
     * This method normalises the requested value and marks an investigation
     * required when the change is a material escalation.
     */
    public function syncSeverity(HsEvent $hsEvent, string $newSeverity): void
    {
        $normalised = self::normaliseSeverity($newSeverity);
        $previousSeverity = $hsEvent->severity;

        if ($normalised === $previousSeverity) {
            return;
        }

        $hsEvent->update(['severity' => $normalised]);

        // If severity materially escalated, mark investigation required
        if ($this->isMaterialEscalation($previousSeverity, $normalised)) {
            if (! $hsEvent->investigation_required) {
                $hsEvent->update(['investigation_required' => true]);
            }
        }

        Log::info('HsEventService: severity updated', [
            'hs_event_id' => $hsEvent->id,
            'from' => $previousSeverity,
            'to' => $normalised,
        ]);
    }

    /**
     * Link a Control Room alert ID back to the HsEvent.
     * Called after the observer's bridge dispatch succeeds.
     */
    public function linkControlRoomAlert(HsEvent $hsEvent, int $alertId): void
    {
        if ($hsEvent->control_room_alert_id === $alertId) {
            return;
        }

        $hsEvent->updateQuietly(['control_room_alert_id' => $alertId]);
    }

    /**
     * Accept an incident-backed H&S handover without changing its governance stage.
     *
     * Acceptance is monotonic: retries return the first accepted record and never
     * replace its owner, actor, timestamp, or notes.
     */
    public function acceptHandover(
        HsEvent $event,
        User $actor,
        ?User $owner = null,
        ?string $notes = null,
    ): HsEvent {
        return DB::transaction(function () use ($event, $actor, $owner, $notes): HsEvent {
            $locked = HsEvent::query()->lockForUpdate()->findOrFail($event->id);

            if ($locked->handover_status === HsEvent::HANDOVER_ACCEPTED) {
                return $locked->loadMissing(['owner:id,name', 'acceptedBy:id,name']);
            }

            if ($locked->handover_status !== HsEvent::HANDOVER_AWAITING_ACCEPTANCE) {
                throw new \DomainException('This H&S event is not awaiting handover acceptance.');
            }

            if ($locked->source_type !== ClientIncident::class) {
                throw new \DomainException('Only submitted incident handovers can be accepted.');
            }

            $incident = ClientIncident::query()->find($locked->source_id);
            if (! $incident || $incident->status === 'draft' || $incident->submitted_at === null) {
                throw new \DomainException('Submit the incident before accepting its H&S handover.');
            }

            $owner ??= $actor;
            $locked->update([
                'handover_status' => HsEvent::HANDOVER_ACCEPTED,
                'owner_user_id' => $owner->id,
                'accepted_by_user_id' => $actor->id,
                'accepted_at' => now(),
                'acceptance_notes' => filled($notes) ? trim((string) $notes) : null,
            ]);

            Log::info('HsEventService: incident handover accepted', [
                'hs_event_id' => $locked->id,
                'incident_id' => $incident->id,
                'accepted_by' => $actor->id,
                'owner_user_id' => $owner->id,
            ]);

            return $locked->fresh(['owner:id,name', 'acceptedBy:id,name']);
        }, 3);
    }

    /* ------------------------------------------------------------------ */
    /*  Governance — WorkSafe NZ notification (E-Gap 2) */
    /* ------------------------------------------------------------------ */

    /**
     * Record or revise the explicit WorkSafe notifiability decision.
     */
    public function recordWorksafeDecision(
        HsEvent $event,
        bool $notifiable,
        string $reason,
        User $actor,
        string $source = 'manual',
    ): HsEvent {
        $reason = trim($reason);
        $source = trim($source);
        $decisionTreeVersion = NotifiableEventClassifier::DECISION_TREE_VERSION;
        $sourceEffectiveDate = NotifiableEventClassifier::SOURCE_EFFECTIVE_DATE;

        if (mb_strlen($reason) < 10) {
            throw new \DomainException('A WorkSafe decision reason of at least 10 characters is required.');
        }

        if (! in_array($source, ['manual', 'incident_report', 'classifier'], true)) {
            throw new \DomainException('The WorkSafe decision source is not supported.');
        }

        if (! $actor->canDo('hazards.manage')) {
            throw new \DomainException('The WorkSafe decision requires qualified H&S authority.');
        }

        return DB::transaction(function () use (
            $actor,
            $decisionTreeVersion,
            $event,
            $notifiable,
            $reason,
            $source,
            $sourceEffectiveDate,
        ): HsEvent {
            $incident = $this->lockIncidentForWorksafeProjection($event);
            $locked = HsEvent::query()->lockForUpdate()->findOrFail($event->id);
            $this->assertWorksafeDecisionMutable($locked);
            $hasCompletedNotification = in_array($locked->worksafe_status, [
                HsEvent::WORKSAFE_NOTIFIED,
                HsEvent::WORKSAFE_ACKNOWLEDGED,
            ], true)
                || $locked->worksafe_notified_at !== null
                || $locked->worksafe_acknowledged_at !== null;

            if ($hasCompletedNotification && ! $notifiable) {
                throw new \DomainException('A notified WorkSafe event cannot be changed to not notifiable.');
            }

            $desiredStatus = $notifiable
                ? ($locked->worksafe_status ?: HsEvent::WORKSAFE_PENDING)
                : null;
            $sameDecision = $locked->worksafe_notifiable === $notifiable
                && $locked->worksafe_status === $desiredStatus
                && $locked->worksafe_decided_at !== null
                && $locked->worksafe_decided_by_user_id !== null
                && trim((string) $locked->worksafe_decision_reason) === $reason
                && (string) $locked->worksafe_decision_source === $source
                && (string) $locked->worksafe_decision_tree_version === $decisionTreeVersion
                && $locked->worksafe_source_effective_date?->toDateString() === $sourceEffectiveDate
                && ($notifiable
                    || ($locked->worksafe_reference === null
                        && $locked->worksafe_method === null
                        && $locked->worksafe_notified_at === null
                        && $locked->worksafe_acknowledged_at === null
                        && ! $locked->worksafe_site_preserved));

            if ($sameDecision) {
                return $locked->fresh(['worksafeDecidedBy:id,name']);
            }

            $before = $this->worksafeDecisionSnapshot($locked);
            $changes = [
                'worksafe_notifiable' => $notifiable,
                'worksafe_status' => $desiredStatus,
                'worksafe_decided_at' => now(),
                'worksafe_decided_by_user_id' => $actor->id,
                'worksafe_decision_reason' => $reason,
                'worksafe_decision_source' => $source,
                'worksafe_decision_tree_version' => $decisionTreeVersion,
                'worksafe_source_effective_date' => $sourceEffectiveDate,
            ];

            if ($notifiable) {
                $changes['investigation_required'] = true;
            } else {
                $changes = [
                    ...$changes,
                    'worksafe_reference' => null,
                    'worksafe_notified_at' => null,
                    'worksafe_method' => null,
                    'worksafe_acknowledged_at' => null,
                    'worksafe_site_preserved' => false,
                    'worksafe_site_preservation_status' => null,
                    'worksafe_site_preservation_decided_at' => null,
                    'worksafe_site_preservation_decided_by_user_id' => null,
                    'worksafe_site_preservation_decision_reference' => null,
                    'worksafe_site_preservation_released_at' => null,
                    'worksafe_site_preservation_released_by_user_id' => null,
                    'worksafe_site_preservation_release_reference' => null,
                ];
            }

            $locked->forceFill($changes)->save();
            $this->projectWorksafeCompatibility($locked->fresh(), $incident);

            AuditLogger::logOrFail(
                'healthSafety.event.worksafeDecisionRecorded',
                $locked,
                [
                    'actor_id' => $actor->id,
                    'before' => $before,
                    'after' => $this->worksafeDecisionSnapshot($locked->fresh()),
                ],
            );

            return $locked->fresh(['worksafeDecidedBy:id,name']);
        }, 3);
    }

    /**
     * Record the WorkSafe NZ notification (HSWA 2015 — notify ASAP). Transitions
     * worksafe_status pending → notified, persisting when/how/reference and the
     * site-preservation status. Re-recording while notified is allowed (e.g. to
     * correct the reference); once acknowledged the notification is locked.
     *
     * @throws \DomainException when the event is not notifiable or already acknowledged
     */
    public function recordWorksafeNotification(
        HsEvent $event,
        \DateTimeInterface|string $notifiedAt,
        string $method,
        ?string $reference = null,
        bool $sitePreserved = false,
        ?User $actor = null,
    ): HsEvent {
        return DB::transaction(function () use ($event, $notifiedAt, $method, $reference, $sitePreserved, $actor): HsEvent {
            $incident = $this->lockIncidentForWorksafeProjection($event);
            $locked = HsEvent::query()->lockForUpdate()->findOrFail($event->id);
            if ($locked->worksafe_notifiable !== true) {
                throw new \DomainException($locked->worksafe_notifiable === null
                    ? 'Record the WorkSafe notifiability decision before recording a notification.'
                    : 'This event is not WorkSafe-notifiable.');
            }

            if (! $locked->hasSignedWorksafeDecision()) {
                throw new \DomainException(
                    'Record a signed WorkSafe decision with the current ruleset and effective source date before recording a notification.',
                );
            }

            if ($locked->worksafe_status === HsEvent::WORKSAFE_ACKNOWLEDGED) {
                throw new \DomainException('WorkSafe has already acknowledged this notification.');
            }

            $changes = [
                'worksafe_status' => HsEvent::WORKSAFE_NOTIFIED,
                'worksafe_notified_at' => $notifiedAt,
                'worksafe_method' => $method,
                'worksafe_reference' => $reference ?: $locked->worksafe_reference,
                'worksafe_site_preserved' => in_array(
                    $locked->worksafe_site_preservation_status,
                    [HsEvent::SITE_PRESERVATION_ACTIVE, HsEvent::SITE_PRESERVATION_RELEASED],
                    true,
                ) || ($sitePreserved && $locked->worksafe_site_preservation_status === null),
            ];
            $siteDecisionRecorded = $sitePreserved
                && $locked->worksafe_site_preservation_status === null;
            if ($siteDecisionRecorded) {
                $decisionActor = $actor ?? auth()->user();
                if (! $decisionActor) {
                    throw new \DomainException('The Site-preservation decision requires an identified actor.');
                }
                $changes = [
                    ...$changes,
                    'worksafe_site_preservation_status' => HsEvent::SITE_PRESERVATION_ACTIVE,
                    'worksafe_site_preservation_decided_at' => now(),
                    'worksafe_site_preservation_decided_by_user_id' => $decisionActor->id,
                    'worksafe_site_preservation_decision_reference' => $reference
                        ? 'WorkSafe notification '.$reference
                        : 'WorkSafe notification record',
                    'worksafe_site_preservation_released_at' => null,
                    'worksafe_site_preservation_released_by_user_id' => null,
                    'worksafe_site_preservation_release_reference' => null,
                ];
            }
            $locked->update($changes);
            $this->projectWorksafeCompatibility($locked->fresh(), $incident);

            if ($siteDecisionRecorded) {
                AuditLogger::logOrFail('healthSafety.event.sitePreservationDecisionRecorded', $locked, [
                    'actor_id' => $decisionActor->id,
                    'required' => true,
                    'evidence_reference' => $changes['worksafe_site_preservation_decision_reference'],
                    'source' => 'worksafe_notification',
                ]);
            }

            Log::info('HsEventService: WorkSafe notification recorded', [
                'hs_event_id' => $locked->id,
                'reference' => $locked->fresh()->worksafe_reference,
                'method' => $method,
                'site_preserved' => $sitePreserved,
            ]);

            return $locked->fresh();
        }, 3);
    }

    /**
     * Record WorkSafe's acknowledgement of a notification (notified → acknowledged).
     *
     * @throws \DomainException when the event has not been notified yet
     */
    public function acknowledgeWorksafe(HsEvent $event, \DateTimeInterface|string $acknowledgedAt): HsEvent
    {
        return DB::transaction(function () use ($event, $acknowledgedAt): HsEvent {
            $incident = $this->lockIncidentForWorksafeProjection($event);
            $locked = HsEvent::query()->lockForUpdate()->findOrFail($event->id);
            if (! $locked->hasSignedWorksafeDecision()) {
                throw new \DomainException(
                    'Record a signed WorkSafe decision with the current ruleset and effective source date before recording an acknowledgement.',
                );
            }

            if ($locked->worksafe_status !== HsEvent::WORKSAFE_NOTIFIED) {
                throw new \DomainException('Record the WorkSafe notification before its acknowledgement.');
            }

            $locked->update([
                'worksafe_status' => HsEvent::WORKSAFE_ACKNOWLEDGED,
                'worksafe_acknowledged_at' => $acknowledgedAt,
            ]);
            $this->projectWorksafeCompatibility($locked->fresh(), $incident);

            Log::info('HsEventService: WorkSafe acknowledgement recorded', [
                'hs_event_id' => $locked->id,
            ]);

            return $locked->fresh();
        }, 3);
    }

    /**
     * Record the product owner's event-specific Site-preservation applicability
     * decision without inferring legal applicability in code.
     */
    public function recordSitePreservationDecision(
        HsEvent $event,
        bool $required,
        string $evidenceReference,
        User $actor,
    ): HsEvent {
        $evidenceReference = trim($evidenceReference);
        if (mb_strlen($evidenceReference) < 5) {
            throw new \DomainException('A Site-preservation evidence reference is required.');
        }

        return DB::transaction(function () use ($event, $required, $evidenceReference, $actor): HsEvent {
            $incident = $this->lockIncidentForWorksafeProjection($event);
            $locked = HsEvent::query()->lockForUpdate()->findOrFail($event->id);
            if ($locked->worksafe_notifiable !== true
                || ! in_array($locked->worksafe_status, [HsEvent::WORKSAFE_NOTIFIED, HsEvent::WORKSAFE_ACKNOWLEDGED], true)
            ) {
                throw new \DomainException('Record the applicable WorkSafe notification before reviewing Site preservation.');
            }
            if ($locked->worksafe_site_preservation_status === HsEvent::SITE_PRESERVATION_RELEASED) {
                throw new \DomainException('The Site-preservation release has already been recorded.');
            }
            if ($locked->worksafe_site_preservation_status === HsEvent::SITE_PRESERVATION_ACTIVE && ! $required) {
                throw new \DomainException(
                    'Active Site-preservation work must be released with evidence; it cannot be changed to not required.',
                );
            }

            $before = [
                'status' => $locked->worksafe_site_preservation_status,
                'decided_at' => $locked->worksafe_site_preservation_decided_at?->toIso8601String(),
                'decided_by_user_id' => $locked->worksafe_site_preservation_decided_by_user_id,
                'decision_reference' => $locked->worksafe_site_preservation_decision_reference,
            ];
            $locked->forceFill([
                'worksafe_site_preserved' => $required,
                'worksafe_site_preservation_status' => $required
                    ? HsEvent::SITE_PRESERVATION_ACTIVE
                    : HsEvent::SITE_PRESERVATION_NOT_REQUIRED,
                'worksafe_site_preservation_decided_at' => now(),
                'worksafe_site_preservation_decided_by_user_id' => $actor->id,
                'worksafe_site_preservation_decision_reference' => $evidenceReference,
                'worksafe_site_preservation_released_at' => null,
                'worksafe_site_preservation_released_by_user_id' => null,
                'worksafe_site_preservation_release_reference' => null,
            ])->save();
            $this->projectWorksafeCompatibility($locked->fresh(), $incident);

            AuditLogger::logOrFail('healthSafety.event.sitePreservationDecisionRecorded', $locked, [
                'actor_id' => $actor->id,
                'required' => $required,
                'evidence_reference' => $evidenceReference,
                'before' => $before,
                'after' => [
                    'status' => $locked->worksafe_site_preservation_status,
                    'decided_at' => $locked->worksafe_site_preservation_decided_at?->toIso8601String(),
                    'decided_by_user_id' => $locked->worksafe_site_preservation_decided_by_user_id,
                    'decision_reference' => $locked->worksafe_site_preservation_decision_reference,
                ],
            ]);

            return $locked->fresh();
        }, 3);
    }

    public function releaseSitePreservation(
        HsEvent $event,
        \DateTimeInterface|string $releasedAt,
        string $evidenceReference,
        User $actor,
    ): HsEvent {
        $evidenceReference = trim($evidenceReference);
        if (mb_strlen($evidenceReference) < 5) {
            throw new \DomainException('A Site-preservation release reference is required.');
        }

        return DB::transaction(function () use ($event, $releasedAt, $evidenceReference, $actor): HsEvent {
            $incident = $this->lockIncidentForWorksafeProjection($event);
            $locked = HsEvent::query()->lockForUpdate()->findOrFail($event->id);
            if ($locked->worksafe_site_preservation_status !== HsEvent::SITE_PRESERVATION_ACTIVE) {
                throw new \DomainException('Only active Site-preservation work can be released.');
            }
            $release = CarbonImmutable::parse($releasedAt);
            $started = $locked->worksafe_site_preservation_decided_at ?? $locked->worksafe_notified_at;
            if ($started && $release->isBefore($started)) {
                throw new \DomainException('The Site-preservation release cannot predate the recorded obligation.');
            }

            $locked->forceFill([
                'worksafe_site_preservation_status' => HsEvent::SITE_PRESERVATION_RELEASED,
                'worksafe_site_preservation_released_at' => $release,
                'worksafe_site_preservation_released_by_user_id' => $actor->id,
                'worksafe_site_preservation_release_reference' => $evidenceReference,
            ])->save();
            $this->projectWorksafeCompatibility($locked->fresh(), $incident);

            AuditLogger::logOrFail('healthSafety.event.sitePreservationReleased', $locked, [
                'actor_id' => $actor->id,
                'released_at' => $release->toIso8601String(),
                'evidence_reference' => $evidenceReference,
            ]);

            return $locked->fresh();
        }, 3);
    }

    /**
     * Keep legacy incident/governance rows as one-way projections of HsEvent.
     */
    private function projectWorksafeCompatibility(HsEvent $event, ?ClientIncident $incident): void
    {
        if (! $incident) {
            return;
        }

        $releasedByName = $event->worksafe_site_preservation_released_by_user_id
            ? $event->worksafeSitePreservationReleasedBy()->value('name')
            : null;

        $incident->updateQuietly([
            'is_notifiable' => (bool) $event->worksafe_notifiable,
            'worksafe_notification_status' => $event->worksafe_status,
            'worksafe_notified_at' => $event->worksafe_notified_at,
            'worksafe_reference' => $event->worksafe_reference,
            'site_preserved' => (bool) $event->worksafe_site_preserved,
            'site_preservation_released_at' => $event->worksafe_site_preservation_released_at,
            'site_preservation_released_by' => $releasedByName,
        ]);

        NotifiableIncident::query()
            ->where('related_incident_id', $incident->id)
            ->where('notification_authority', 'worksafe')
            ->get()
            ->each(function (NotifiableIncident $legacy) use ($event): void {
                $tracking = $legacy->authority_response_tracking ?? [];
                if ($event->worksafe_acknowledged_at) {
                    $tracking['worksafe_acknowledged_at'] = $event->worksafe_acknowledged_at->toIso8601String();
                }

                $legacy->updateQuietly([
                    'status' => $event->worksafe_status,
                    'notified_at' => $event->worksafe_notified_at,
                    'notification_reference' => $event->worksafe_reference,
                    'notified_by' => auth()->id() ?: $legacy->notified_by,
                    'site_preserved' => (bool) $event->worksafe_site_preserved,
                    'site_preservation_released_at' => $event->worksafe_site_preservation_released_at,
                    'site_preservation_released_by' => $event->worksafe_site_preservation_released_by_user_id,
                    'authority_response_tracking' => $tracking ?: null,
                ]);
            });
    }

    /**
     * @return array{
     *     notifiable: bool|null,
     *     status: string|null,
     *     decided_at: string|null,
     *     decided_by_user_id: int|null,
     *     reason: string|null,
     *     source: string|null,
     *     decision_tree_version: string|null,
     *     source_effective_date: string|null
     * }
     */
    private function worksafeDecisionSnapshot(HsEvent $event): array
    {
        return [
            'notifiable' => $event->worksafe_notifiable,
            'status' => $event->worksafe_status,
            'decided_at' => $event->worksafe_decided_at?->toIso8601String(),
            'decided_by_user_id' => $event->worksafe_decided_by_user_id,
            'reason' => $event->worksafe_decision_reason,
            'source' => $event->worksafe_decision_source,
            'decision_tree_version' => $event->worksafe_decision_tree_version,
            'source_effective_date' => $event->worksafe_source_effective_date?->toDateString(),
        ];
    }

    /**
     * @return array{
     *     notifiable: null,
     *     status: null,
     *     decided_at: null,
     *     decided_by_user_id: null,
     *     reason: null,
     *     source: null,
     *     decision_tree_version: null,
     *     source_effective_date: null
     * }
     */
    private function emptyWorksafeDecisionSnapshot(): array
    {
        return [
            'notifiable' => null,
            'status' => null,
            'decided_at' => null,
            'decided_by_user_id' => null,
            'reason' => null,
            'source' => null,
            'decision_tree_version' => null,
            'source_effective_date' => null,
        ];
    }

    private function assertWorksafeDecisionMutable(HsEvent $event): void
    {
        if ($event->status === HsEvent::STATUS_CLOSED) {
            throw new \DomainException('A closed H&S event cannot change its WorkSafe record.');
        }

        if (! in_array($event->handover_status, [
            HsEvent::HANDOVER_ACCEPTED,
            HsEvent::HANDOVER_NOT_REQUIRED,
        ], true)) {
            throw new \DomainException('Accept the H&S handover before recording or revising the WorkSafe decision.');
        }
    }

    /**
     * Match IncidentJourneyService's incident -> HsEvent lock order before
     * projecting canonical WorkSafe state back to the compatibility columns.
     */
    private function lockIncidentForWorksafeProjection(HsEvent $event): ?ClientIncident
    {
        $incidentId = ClientIncident::query()
            ->where('hs_event_id', $event->id)
            ->value('id');

        if (! $incidentId && $event->source_type === ClientIncident::class) {
            $incidentId = $event->source_id;
        }

        return $incidentId
            ? ClientIncident::query()->whereKey($incidentId)->lockForUpdate()->first()
            : null;
    }

    /* ------------------------------------------------------------------ */
    /*  Severity normalisation */
    /* ------------------------------------------------------------------ */

    /**
     * Normalise severity from various source model formats into the
     * HsEvent standard: low, medium, high, critical.
     *
     * Source models use inconsistent scales:
     *  - ClientIncident: low, medium, high
     *  - WorkplaceInjury: minor, moderate, serious, critical
     *  - SiteHazard risk_rating: low, medium, high, extreme
     *  - SafeguardingConcern: low, medium, high, critical
     *  - FleetIncident: low, medium, high, critical
     *  - RestraintEvent: low, medium, high
     */
    public static function normaliseSeverity(string $severity): string
    {
        return match (strtolower(trim($severity))) {
            'critical', 'extreme' => HsEvent::SEVERITY_CRITICAL,
            'high', 'serious' => HsEvent::SEVERITY_HIGH,
            'medium', 'moderate' => HsEvent::SEVERITY_MEDIUM,
            default => HsEvent::SEVERITY_LOW,
        };
    }

    /* ------------------------------------------------------------------ */
    /*  Internal helpers */
    /* ------------------------------------------------------------------ */

    private function requiresInvestigation(string $normalisedSeverity, bool $worksafeNotifiable): bool
    {
        if ($worksafeNotifiable) {
            return true;
        }

        return in_array($normalisedSeverity, [HsEvent::SEVERITY_HIGH, HsEvent::SEVERITY_CRITICAL], true);
    }

    /**
     * Determine if a severity change requires an H&S investigation.
     *
     * Material escalation = crossing from below-high to high-or-above,
     * or from high to critical.
     */
    private function isMaterialEscalation(string $from, string $to): bool
    {
        $order = [
            HsEvent::SEVERITY_LOW => 0,
            HsEvent::SEVERITY_MEDIUM => 1,
            HsEvent::SEVERITY_HIGH => 2,
            HsEvent::SEVERITY_CRITICAL => 3,
        ];

        $fromRank = $order[$from] ?? 0;
        $toRank = $order[$to] ?? 0;

        // Must cross the high threshold (rank 2) or go from high to critical
        return $toRank > $fromRank && $toRank >= 2;
    }
}
