<?php

namespace App\Services\HealthSafety;

use App\Domain\Governance\Models\NotifiableIncident;
use App\Models\ClientIncident;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\HsRecommendationDisposition;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ControlRoom\ComprehensiveAlertBridgeService;
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
        private readonly HsInvestigationService $investigations,
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
     *     organization_id?: int|null,
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
            $hsEvent = HsEvent::create([
                'organization_id' => $data['organization_id'] ?? null,
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
                'worksafe_notifiable' => $data['worksafe_notifiable'] ?? false,
                'worksafe_status' => ($data['worksafe_notifiable'] ?? false) ? HsEvent::WORKSAFE_PENDING : null,
                'investigation_required' => $this->requiresInvestigation($severity, $data['worksafe_notifiable'] ?? false),
                'control_room_alert_id' => $data['control_room_alert_id'] ?? null,
                'handover_status' => $data['handover_status'] ?? HsEvent::HANDOVER_NOT_REQUIRED,
                'owner_user_id' => $data['owner_user_id'] ?? null,
                'idempotency_key' => $idempotencyKey,
                'created_by' => $data['created_by'] ?? auth()->id(),
            ]);

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
    /*  Governance — gated closure (E-Gap 1) */
    /* ------------------------------------------------------------------ */

    /**
     * The unmet closure gates for an event (empty array = clean to close).
     *
     * An event cannot be closed while a required investigation is incomplete or
     * any corrective action is still open/unverified — unless overridden with a
     * logged reason.
     *
     * @return list<string>
     */
    public function closeBlockers(HsEvent $event): array
    {
        return $this->closureGate($event)['blockers'];
    }

    /**
     * @return array{
     *     acceptance_ok: bool,
     *     worksafe_ok: bool,
     *     investigation_ok: bool,
     *     recommendations_ok: bool,
     *     actions_ok: bool,
     *     blockers: list<string>
     * }
     */
    public function closureGate(HsEvent $event): array
    {
        $blockers = [];
        $sourceType = ltrim((string) $event->source_type, '\\');
        $handoverRequiresAcceptance = $sourceType === ClientIncident::class
            || in_array($event->handover_status, [
                HsEvent::HANDOVER_NOT_READY,
                HsEvent::HANDOVER_AWAITING_ACCEPTANCE,
            ], true);
        $acceptanceOk = ! $handoverRequiresAcceptance
            || $event->handover_status === HsEvent::HANDOVER_ACCEPTED;

        if (! $acceptanceOk) {
            $blockers[] = 'Accept the H&S handover before closing this event.';
        }

        $worksafeOk = ! $event->worksafe_notifiable
            || in_array($event->worksafe_status, [
                HsEvent::WORKSAFE_NOTIFIED,
                HsEvent::WORKSAFE_ACKNOWLEDGED,
            ], true);

        if (! $worksafeOk) {
            $blockers[] = 'Record the WorkSafe notification before closing this event.';
        }

        $hasActiveInvestigation = $event->investigations()
            ->where('status', '!=', HsInvestigation::STATUS_COMPLETED)
            ->exists();
        $investigationOk = ! $hasActiveInvestigation
            && (! $event->investigation_required || $event->hasCompletedInvestigation());

        if (! $investigationOk) {
            $blockers[] = $hasActiveInvestigation
                ? 'Complete the active H&S investigation before closing this event.'
                : 'Complete the required H&S investigation before closing this event.';
        }

        $recommendationsOk = true;
        $completedInvestigations = $event->investigations()
            ->where('status', HsInvestigation::STATUS_COMPLETED)
            ->get();

        foreach ($completedInvestigations as $investigation) {
            $missing = $this->investigations->undispositionedRecommendationIndexes($investigation);
            if ($missing === []) {
                continue;
            }

            $recommendationsOk = false;
            $numbers = collect($missing)
                ->map(static fn (int $index): string => (string) ($index + 1))
                ->implode(', ');
            $blockers[] = "Decide the outcome of recommendation {$numbers} on investigation {$investigation->reference_number}.";
        }

        $unresolvedActionDisposition = HsRecommendationDisposition::query()
            ->whereHas('investigation', fn ($query) => $query->where('hs_event_id', $event->id))
            ->where('disposition', HsRecommendationDisposition::DISPOSITION_CORRECTIVE_ACTION)
            ->where(function ($query): void {
                $query
                    ->whereNull('hs_corrective_action_id')
                    ->orWhereHas('correctiveAction', fn ($actionQuery) => $actionQuery
                        ->whereNotIn('status', [
                            HsCorrectiveAction::STATUS_VERIFIED,
                            HsCorrectiveAction::STATUS_CLOSED,
                        ]));
            })
            ->exists();
        $actionsOk = ! $event->hasOpenCorrectiveActions() && ! $unresolvedActionDisposition;

        if (! $actionsOk) {
            $blockers[] = 'All corrective actions must be verified or closed before this event can be closed.';
        }

        return [
            'acceptance_ok' => $acceptanceOk,
            'worksafe_ok' => $worksafeOk,
            'investigation_ok' => $investigationOk,
            'recommendations_ok' => $recommendationsOk,
            'actions_ok' => $actionsOk,
            'blockers' => $blockers,
        ];
    }

    /**
     * Close an event through the governance gate.
     *
     * Blocks unless every gate in {@see closeBlockers()} is met. Bypass requires
     * both the dedicated override permission and a reason; the actor, reason and
     * exact blockers are then written to the strict audit trail. A closure summary
     * is always required.
     *
     * @throws \DomainException when the gate blocks and no override reason is given
     */
    public function closeEvent(HsEvent $event, string $summary, User $actor, ?string $overrideReason = null): HsEvent
    {
        $summary = trim($summary);
        if ($summary === '') {
            throw new \DomainException('A closure summary is required.');
        }

        return DB::transaction(function () use ($event, $summary, $actor, $overrideReason): HsEvent {
            $locked = HsEvent::query()->lockForUpdate()->findOrFail($event->id);
            if ($locked->status === HsEvent::STATUS_CLOSED) {
                throw new \DomainException('This event is already closed.');
            }

            $blockers = $this->closeBlockers($locked);
            $normalisedOverrideReason = filled($overrideReason) ? trim((string) $overrideReason) : null;

            if ($blockers !== [] && $normalisedOverrideReason === null) {
                throw new \DomainException(implode(' ', $blockers));
            }

            if ($blockers !== [] && ! $actor->canDo('healthSafety.overrideClosure')) {
                throw new \DomainException(
                    'You do not have permission to override H&S closure blockers. Complete the listed work or ask an authorised manager.'
                );
            }

            $overridden = $blockers !== [];
            $locked->update([
                'status' => HsEvent::STATUS_CLOSED,
                'closed_at' => now(),
                'closed_by' => $actor->id,
                'closure_summary' => $summary,
            ]);

            AuditLogger::logOrFail(
                $overridden
                    ? 'healthSafety.event.closureOverridden'
                    : 'healthSafety.event.closed',
                $locked,
                [
                    'actor_id' => $actor->id,
                    'closure_summary' => $summary,
                    'override_reason' => $overridden ? $normalisedOverrideReason : null,
                    'blockers' => $blockers,
                ],
            );

            Log::info('HsEventService: event closed', [
                'hs_event_id' => $locked->id,
                'reference' => $locked->reference_number,
                'actor' => $actor->id,
                'overridden' => $overridden,
                'override_reason' => $overridden ? $normalisedOverrideReason : null,
                'blockers_at_close' => $blockers,
            ]);

            return $locked->fresh();
        }, 3);
    }

    /* ------------------------------------------------------------------ */
    /*  Governance — WorkSafe NZ notification (E-Gap 2) */
    /* ------------------------------------------------------------------ */

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
    ): HsEvent {
        return DB::transaction(function () use ($event, $notifiedAt, $method, $reference, $sitePreserved): HsEvent {
            $incident = $this->lockIncidentForWorksafeProjection($event);
            $locked = HsEvent::query()->lockForUpdate()->findOrFail($event->id);

            if (! $locked->worksafe_notifiable) {
                throw new \DomainException('This event is not WorkSafe-notifiable.');
            }

            if ($locked->worksafe_status === HsEvent::WORKSAFE_ACKNOWLEDGED) {
                throw new \DomainException('WorkSafe has already acknowledged this notification.');
            }

            $locked->update([
                'worksafe_status' => HsEvent::WORKSAFE_NOTIFIED,
                'worksafe_notified_at' => $notifiedAt,
                'worksafe_method' => $method,
                'worksafe_reference' => $reference ?: $locked->worksafe_reference,
                'worksafe_site_preserved' => $sitePreserved,
            ]);
            $this->projectWorksafeCompatibility($locked->fresh(), $incident);

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
     * Keep legacy incident/governance rows as one-way projections of HsEvent.
     */
    private function projectWorksafeCompatibility(HsEvent $event, ?ClientIncident $incident): void
    {
        if (! $incident) {
            return;
        }

        $incident->updateQuietly([
            'is_notifiable' => (bool) $event->worksafe_notifiable,
            'worksafe_notification_status' => $event->worksafe_status,
            'worksafe_notified_at' => $event->worksafe_notified_at,
            'worksafe_reference' => $event->worksafe_reference,
            'site_preserved' => (bool) $event->worksafe_site_preserved,
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
                    'authority_response_tracking' => $tracking ?: null,
                ]);
            });
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
