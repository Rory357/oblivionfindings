<?php

namespace App\Services\Incidents;

use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ControlRoomAlert;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use App\Services\ControlRoom\ControlRoomAlertProvenanceService;
use App\Services\ControlRoom\IncidentAlertOperationalInitializer;
use App\Services\HealthSafety\HsEventService;
use App\Services\References\ReferenceNumberGenerator;
use App\Support\Journeys\JourneyGate;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class IncidentJourneyService
{
    private const TRANSACTION_ATTEMPTS = 3;

    /** @var list<string> */
    private const INCIDENT_INPUT_FIELDS = [
        'client_id',
        'site_id',
        'shift_id',
        'respite_stay_id',
        'service_context_id',
        'template_id',
        'type',
        'severity',
        'occurred_at',
        'title',
        'description',
        'metadata',
        'requires_followup',
        'immediate_action_taken',
        'witnesses',
        'location',
        'immediate_action',
        'follow_up_required',
        'portal_visible',
        'potential_severity',
        'potential_consequence',
        'is_notifiable',
        'worksafe_notification_status',
        'worksafe_notified_at',
        'worksafe_reference',
        'site_preserved',
        'site_preservation_released_at',
        'site_preservation_released_by',
        'injured_person_name',
        'injured_person_role',
        'injured_person_age',
        'injury_body_part',
        'injury_nature',
        'injury_classification',
        'medical_treatment_type',
    ];

    public function __construct(
        private readonly HsEventService $hsEventService,
        private readonly ReferenceNumberGenerator $references,
        private readonly IncidentAlertOperationalInitializer $alertOperations,
        private readonly ControlRoomAlertProvenanceService $alertProvenance,
    ) {}

    /**
     * Submit the canonical incident represented by an existing operational alert.
     *
     * The alert is the lock anchor, so concurrent retries cannot create two
     * incidents for the same operational record.
     *
     * @param  array<string, mixed>  $input
     */
    public function submitFromAlert(ControlRoomAlert $alert, array $input, User $actor): IncidentJourney
    {
        return DB::transaction(function () use ($alert, $input, $actor): IncidentJourney {
            $lockedAlert = ControlRoomAlert::query()
                ->whereKey($alert->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $incident = $this->lockedIncidentForAlert($lockedAlert);
            $submittedNow = $incident === null || $incident->status === 'draft';

            if ($incident === null) {
                $attributes = $this->incidentAttributes($lockedAlert, null, $input, $actor);
                $incident = ClientIncident::withoutEvents(
                    fn () => ClientIncident::query()->create($attributes),
                );
            } elseif ($incident->status === 'draft') {
                $attributes = $this->incidentAttributes($lockedAlert, $incident, $input, $actor);
                $incident->forceFill($attributes)->saveQuietly();
                $incident->refresh();
            } elseif ($incident->status === 'submitted'
                && blank($incident->immediate_action_taken)
                && filled($input['immediate_action_taken'] ?? null)
            ) {
                $incident->forceFill([
                    'immediate_action_taken' => trim((string) $input['immediate_action_taken']),
                ])->saveQuietly();
                $incident->refresh();
            }

            if ($incident->status === 'submitted') {
                $this->assertSeriousIncidentHasImmediateAction($incident, $lockedAlert);
            }

            $hsEvent = $this->lockedOrCreatedHsEvent($incident, $actor);
            $this->assertHsTupleCanBeCanonicalised($incident, $hsEvent);

            $this->assertJourneyLinksDoNotConflict($incident, $lockedAlert, $hsEvent);
            $this->linkJourney($incident, $lockedAlert, $hsEvent, $actor, [
                'incident_id' => $incident->id,
            ]);

            if ($submittedNow) {
                $this->stageSubmissionRouting($incident, $actor);
            }

            return $this->freshJourney($incident, $lockedAlert, $hsEvent);
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * Ensure a submitted incident has its canonical H&S event and, when high or
     * critical-equivalent, its one incident-backed alert.
     */
    public function ensureForSubmittedIncident(ClientIncident $incident, ?User $actor = null, bool $newlySubmitted = false): IncidentJourney
    {
        $newlySubmitted = $newlySubmitted || $this->isNewSubmission($incident);
        return DB::transaction(function () use ($incident, $actor, $newlySubmitted): IncidentJourney {
            $lockedIncident = $this->lockIncident($incident);
            $this->assertSubmitted($lockedIncident);

            $hsEvent = $this->lockedOrCreatedHsEvent($lockedIncident, $actor);
            $this->assertHsTupleCanBeCanonicalised($lockedIncident, $hsEvent);
            $alert = $this->lockedAlertForIncident($lockedIncident, $hsEvent);
            $alertReason = $alert === null
                ? 'Automatic high-severity incident escalation'
                : data_get($alert->context, 'reason');
            $alertWasCreated = false;

            if ($alert === null && $this->requiresAutomaticAlert($lockedIncident)) {
                $alertActor = $this->actorRequiredForAlert($lockedIncident, $actor);
                $alert = $this->createIncidentAlert(
                    $lockedIncident,
                    $alertActor,
                    $alertReason,
                );
                $alertWasCreated = true;
            }

            $alertWasPromoted = $alert !== null
                && $this->promoteAlertToIncidentFloor($lockedIncident, $alert);

            $this->assertJourneyLinksDoNotConflict($lockedIncident, $alert, $hsEvent);
            $this->linkJourney(
                $lockedIncident,
                $alert,
                $hsEvent,
                $actor,
                $alert === null ? [] : $this->incidentAlertContext($lockedIncident, $alertReason),
            );

            if ($alertWasCreated || $alertWasPromoted) {
                $this->alertOperations->initialiseNewAlert($alert);
            }

            if ($newlySubmitted) {
                $this->stageSubmissionRouting($lockedIncident, $actor);
            }

            return $this->freshJourney($lockedIncident, $alert, $hsEvent);
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * Explicitly escalate a submitted incident into the Control Room regardless
     * of severity. Exact incident linkage is the idempotency boundary; the legacy
     * fuzzy 30-minute bridge is deliberately not used.
     */
    public function ensureAlertForIncident(
        ClientIncident $incident,
        User $actor,
        ?string $reason = null,
        ?string $requestedSeverity = null,
        ?IncidentResponseJourneyReadContext $responseSource = null,
    ): IncidentJourney {
        return DB::transaction(function () use ($incident, $actor, $reason, $requestedSeverity, $responseSource): IncidentJourney {
            $responseSource?->assertFor($incident, $actor);
            $lockedIncident = $this->lockIncident($incident, $responseSource);
            $this->assertSubmitted($lockedIncident);
            $this->applyRequestedSeverityFloor($lockedIncident, $requestedSeverity, $responseSource);

            $hsEvent = $this->lockedOrCreatedHsEvent($lockedIncident, $actor, $responseSource);
            $this->assertHsTupleCanBeCanonicalised($lockedIncident, $hsEvent, $responseSource);
            $alert = $this->lockedAlertForIncident($lockedIncident, $hsEvent, $responseSource);
            $alertWasCreated = $alert === null;
            $alert ??= $this->createIncidentAlert($lockedIncident, $actor, $reason);

            $alertWasPromoted = $this->promoteAlertToIncidentFloor($lockedIncident, $alert, $responseSource);
            $this->adoptExplicitIncidentReason($alert, $reason);
            $this->assertJourneyLinksDoNotConflict($lockedIncident, $alert, $hsEvent, $responseSource);
            $this->linkJourney(
                $lockedIncident,
                $alert,
                $hsEvent,
                $actor,
                $this->incidentAlertContext($lockedIncident, $reason),
                $responseSource,
            );

            if ($alertWasCreated || $alertWasPromoted) {
                $this->alertOperations->initialiseNewAlert($alert);
            }

            return $this->freshJourney($lockedIncident, $alert, $hsEvent, $responseSource);
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * Adopt an already-created operational alert into an incident journey.
     *
     * Draft incidents receive only the alert compatibility link. Submitted
     * incidents receive the complete alert/incident/H&S backlink set. This is
     * the canonical write boundary for integrations that create their own alert.
     */
    public function attachAlertToIncident(
        ClientIncident $incident,
        ControlRoomAlert $alert,
        ?User $actor = null,
        bool $newlySubmitted = false,
    ): IncidentJourney {
        $newlySubmitted = $newlySubmitted || $this->isNewSubmission($incident);
        return DB::transaction(function () use ($incident, $alert, $actor, $newlySubmitted): IncidentJourney {
            $lockedAlert = ControlRoomAlert::query()
                ->whereKey($alert->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $lockedIncident = $this->lockIncident($incident);

            if ($lockedIncident->status === 'draft') {
                $this->assertDraftAlertLinkDoesNotConflict($lockedIncident, $lockedAlert);
                $this->stampAlertProvenance($lockedIncident, $lockedAlert);
                $this->linkDraftAlert($lockedIncident, $lockedAlert);

                return new IncidentJourney(
                    $lockedIncident->fresh(),
                    $lockedAlert->fresh(),
                    null,
                );
            }

            $this->assertSubmitted($lockedIncident);
            $this->assertAlertMatchesIncident($lockedIncident, $lockedAlert);
            if ($lockedIncident->status === 'submitted') {
                $this->assertSeriousIncidentHasImmediateAction($lockedIncident, $lockedAlert);
            }
            $alertWasPromoted = $this->promoteAlertToIncidentFloor($lockedIncident, $lockedAlert);
            $hsEvent = $this->lockedOrCreatedHsEvent($lockedIncident, $actor);
            $this->assertHsTupleCanBeCanonicalised($lockedIncident, $hsEvent);
            $this->assertJourneyLinksDoNotConflict($lockedIncident, $lockedAlert, $hsEvent);
            $this->linkJourney(
                $lockedIncident,
                $lockedAlert,
                $hsEvent,
                $actor,
                $this->incidentAlertContext(
                    $lockedIncident,
                    data_get($lockedAlert->context, 'reason'),
                ),
            );

            if ($alertWasPromoted) {
                $this->alertOperations->initialiseNewAlert($lockedAlert);
            }

            if ($newlySubmitted) {
                $this->stageSubmissionRouting($lockedIncident, $actor);
            }

            return $this->freshJourney($lockedIncident, $lockedAlert, $hsEvent);
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * Resolve the journey without taking locks or repairing legacy gaps.
     */
    public function journeyForIncident(ClientIncident $incident): IncidentJourney
    {
        $incident = ClientIncident::query()->findOrFail($incident->getKey());
        $hsEvent = $this->readHsEventForIncident($incident);
        $alert = $this->readAlertForIncident($incident, $hsEvent);

        return new IncidentJourney($incident, $alert, $hsEvent);
    }

    public function hsEventIsCanonicalForIncident(ClientIncident $incident, HsEvent $event): bool
    {
        try {
            $this->assertHsEventMatchesIncidentForRead(
                $incident,
                $event,
            );
        } catch (\DomainException) {
            return false;
        }

        return true;
    }

    public function incidentForAlert(ControlRoomAlert $alert): ?ClientIncident
    {
        $direct = ClientIncident::query()
            ->where('control_room_alert_id', $alert->id)
            ->orderBy('id')
            ->limit(2)
            ->get();

        if ($direct->count() > 1) {
            throw new \DomainException('Incident journey conflict: the alert is directly linked to multiple incidents.');
        }

        if ($direct->isNotEmpty()) {
            $incident = $direct->first();
            $this->assertAlertMatchesIncident($incident, $alert);

            return $incident;
        }

        $legacyIncidentId = data_get($alert->context, 'incident_id');
        if (! is_numeric($legacyIncidentId)) {
            return null;
        }

        $legacy = ClientIncident::query()->find((int) $legacyIncidentId);
        if ($legacy?->control_room_alert_id !== null
            && (int) $legacy->control_room_alert_id !== (int) $alert->id
        ) {
            throw new \DomainException('Incident journey conflict: the legacy incident already has a different direct alert.');
        }

        if ($legacy !== null) {
            $this->assertAlertMatchesIncident($legacy, $alert);
        }

        return $legacy;
    }

    /**
     * Resolve the incident and H&S records that canonically belong to an alert
     * without repairing legacy links.
     *
     * @return array{incident: ?ClientIncident, hs_event: ?HsEvent}
     */
    public function governanceRecordsForAlert(ControlRoomAlert $alert): array
    {
        $alert = ControlRoomAlert::query()->findOrFail($alert->getKey());
        $directEvents = HsEvent::query()
            ->where('control_room_alert_id', $alert->id)
            ->orderBy('id')
            ->limit(2)
            ->get();

        if ($directEvents->count() > 1) {
            throw new \DomainException(
                'Incident journey conflict: the alert is directly linked to multiple H&S events.',
            );
        }

        $directEvent = $directEvents->first();
        if ($directEvent !== null) {
            $this->alertProvenance->assertHealthSafetyEventTuple($alert, $directEvent);
        }

        $incident = $this->incidentForAlert($alert);
        if ($incident === null) {
            return [
                'incident' => null,
                'hs_event' => $directEvent,
            ];
        }

        $journey = $this->journeyForIncident($incident);
        if ($journey->alert === null || ! $journey->alert->is($alert)) {
            throw new \DomainException(
                'Incident journey conflict: the canonical incident resolves to a different alert.',
            );
        }

        if ($journey->hsEvent !== null) {
            $this->alertProvenance->assertHealthSafetyEventTuple($alert, $journey->hsEvent);
        }

        if ($directEvent !== null
            && $journey->hsEvent !== null
            && ! $directEvent->is($journey->hsEvent)
        ) {
            throw new \DomainException(
                'Incident journey conflict: the alert and incident resolve to different H&S events.',
            );
        }

        return [
            'incident' => $incident,
            'hs_event' => $journey->hsEvent ?? $directEvent,
        ];
    }

    /** Metadata-only current relational footprint; canonical IncidentAccess remains the body authority. */
    public static function incidentReadOwnershipExpression(CurrentAuthorizationReads $reads): string
    {
        $reads->assertActive();
        $review="(SELECT JSON_OBJECT('id', source_review.id, 'source_type', source_review.source_type, 'source_id', source_review.source_id, 'client_id', source_review.client_id, 'site_id', source_review.site_id) FROM hs_events source_review WHERE source_review.id=client_incidents.hs_event_id AND source_review.deleted_at IS NULL LIMIT 1 FOR SHARE NOWAIT)";
        $shift="(SELECT JSON_OBJECT('id', source_shift.id, 'client_id', source_shift.client_id, 'site_id', source_shift.site_id) FROM shifts source_shift WHERE source_shift.id=client_incidents.shift_id LIMIT 1 FOR SHARE NOWAIT)";
        $client="(SELECT JSON_OBJECT('id', source_client.id, 'site_id', source_client.site_id) FROM clients source_client WHERE source_client.id=client_incidents.client_id AND source_client.deleted_at IS NULL LIMIT 1 FOR SHARE NOWAIT)";
        return "JSON_OBJECT('review', $review, 'shift', $shift, 'client', $client)";
    }

    /**
     * Resolve canonical governance records for an uncapped alert collection in
     * bounded queries. Known ownership conflicts become an unavailable row;
     * single-record resolution and canonical commands remain strict.
     *
     * @param  iterable<ControlRoomAlert>  $alerts
     * @return Collection<int, array{incident: ?ClientIncident, hs_event: ?HsEvent, integrity?: string}>
     */
    public function governanceRecordsForAlerts(iterable $alerts, ?CurrentAuthorizationReads $reads = null): Collection
    {
        $alerts = collect($alerts)
            ->filter(fn ($alert): bool => $alert instanceof ControlRoomAlert)
            ->keyBy(fn (ControlRoomAlert $alert): int => (int) $alert->id);
        if ($alerts->isEmpty()) {
            return collect();
        }

        $unavailable = ['incident' => null, 'hs_event' => null, 'integrity' => 'unavailable'];
        // Collection reads isolate only these known canonical integrity failures.
        // Unexpected domain failures and infrastructure errors still propagate.
        $resolve = static function (callable $callback, array $fallback): array {
            try {
                return $callback();
            } catch (\DomainException $exception) {
                if (! in_array($exception->getMessage(), [
                    'Incident journey conflict: the legacy incident already has a different direct alert.',
                    'Incident journey conflict: the alert client does not match the incident client.',
                    'Incident journey conflict: the alert context claims a different incident.',
                    'Incident journey conflict: the canonical incident resolves to a different alert.',
                    'Incident journey conflict: the alert and incident resolve to different H&S events.',
                    'Incident journey conflict: the direct H&S event does not match the canonical incident tuple.',
                    'Incident journey conflict: the H&S event has a different direct alert.',
                    'Incident journey conflict: the direct H&S event client_id does not match the incident.',
                    'Incident journey conflict: the direct H&S event site_id does not match the incident.',
                    'H&S handover provenance conflict: the alert and H&S event do not share one Client/Site ownership tuple.',
                ], true)) {
                    throw $exception;
                }

                return $fallback;
            }
        };

        $alertIds = $alerts->keys()->map(fn ($id): int => (int) $id)->all();
        $read = function ($query) use ($reads) {
            if ($reads === null) { return $query; }
            // Current reads establish lifetime, not source body authority. Discovery contains metadata only.
            $model = $query->getModel();
            if ($model instanceof ClientIncident) {
                $query->select(['id', 'reference_number', 'client_id', 'site_id', 'control_room_alert_id', 'hs_event_id', 'type', 'status',
                    'created_at', 'submitted_at', 'reviewed_at', 'investigation_started_at', 'investigation_completed_at', 'reopened_at', 'closed_at', 'shift_id'])
                    ->selectRaw(self::incidentReadOwnershipExpression($reads).' as _incident_source_ownership');
            } elseif ($model instanceof HsEvent) {
                $query->select(['id', 'reference_number', 'source_type', 'source_id', 'event_category', 'idempotency_key',
                    'client_id', 'site_id', 'staff_id', 'asset_id', 'control_room_alert_id', 'status', 'handover_status',
                    'created_at', 'accepted_at', 'worksafe_decided_at', 'worksafe_notified_at', 'worksafe_acknowledged_at', 'closed_at']);
            } elseif ($model instanceof ControlRoomAlert) {
                $query->select(['id', 'client_id', 'site_id', 'asset_id', 'fleet_signal_id', 'device_id', 'source', 'alert_type', 'status'])
                    ->selectRaw("JSON_OBJECT('incident_id', JSON_EXTRACT(control_room_alerts.context, '$.incident_id')) as context");
            }
            return $reads->query($query);
        };
        $directEvents = $read(HsEvent::query()
            ->whereIn('control_room_alert_id', $alertIds)
            ->orderBy('id'))
            ->get()
            ->groupBy(fn (HsEvent $event): int => (int) $event->control_room_alert_id);

        $directIncidents = $read(ClientIncident::query()
            ->whereIn('control_room_alert_id', $alertIds)
            ->orderBy('id'))
            ->get()
            ->groupBy(fn (ClientIncident $incident): int => (int) $incident->control_room_alert_id);

        $legacyIncidentIds = $alerts
            ->reject(fn (ControlRoomAlert $alert): bool => $directIncidents->has((int) $alert->id))
            ->map(fn (ControlRoomAlert $alert) => data_get($alert->context, 'incident_id'))
            ->filter(fn ($id): bool => is_numeric($id))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
        $legacyIncidents = $legacyIncidentIds->isEmpty()
            ? collect()
            : $read(ClientIncident::query()
                ->whereIn('id', $legacyIncidentIds))
                ->get()
                ->keyBy(fn (ClientIncident $incident): int => (int) $incident->id);
        $records = $alerts->map(function (ControlRoomAlert $alert) use (
            $directEvents,
            $directIncidents,
            $legacyIncidents,
            $resolve,
            $unavailable,
        ): array {
            $fallback = ['alert' => $alert, 'direct_event' => null] + $unavailable;
            if (($directEvents->get((int) $alert->id)?->count() ?? 0) > 1
                || ($directIncidents->get((int) $alert->id)?->count() ?? 0) > 1
            ) {
                return $fallback;
            }

            return $resolve(function () use ($alert, $directIncidents, $legacyIncidents, $directEvents): array {
                $incident = $directIncidents->get((int) $alert->id)?->first();
                if ($incident === null) {
                    $legacyIncidentId = data_get($alert->context, 'incident_id');
                    $incident = is_numeric($legacyIncidentId)
                        ? $legacyIncidents->get((int) $legacyIncidentId)
                        : null;
                    if ($incident?->control_room_alert_id !== null
                        && (int) $incident->control_room_alert_id !== (int) $alert->id
                    ) {
                        throw new \DomainException(
                            'Incident journey conflict: the legacy incident already has a different direct alert.',
                        );
                    }
                }

                if ($incident !== null) {
                    $this->assertAlertMatchesIncident($incident, $alert);
                }

                $directEvent = $directEvents->get((int) $alert->id)?->first();

                return [
                    'alert' => $alert,
                    'incident' => $incident,
                    'direct_event' => $directEvent,
                    'hs_event' => null,
                ];
            }, $fallback);
        });

        /** @var EloquentCollection<int, ClientIncident> $journeyIncidents */
        $journeyIncidents = new EloquentCollection(
            $records
                ->pluck('incident')
                ->filter()
                ->unique(fn (ClientIncident $incident): int => (int) $incident->id)
                ->values()
                ->all(),
        );
        if ($reads === null) { $journeyIncidents->loadMissing('client:id,site_id'); }
        else { $journeyIncidents->loadMissing(['client' => fn ($client) => $reads->query($client->getQuery()->select(['clients.id', 'clients.site_id']))]); }
        $directHsIds = $journeyIncidents
            ->pluck('hs_event_id')
            ->filter(fn ($id): bool => is_numeric($id))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
        $idempotencyKeys = $journeyIncidents
            ->map(fn (ClientIncident $incident): string => $this->hsIdempotencyKey($incident))
            ->unique()
            ->values();
        $journeyEvents = ($directHsIds->isEmpty() && $idempotencyKeys->isEmpty())
            ? collect()
            : $read(HsEvent::query()
                ->where(function ($query) use ($directHsIds, $idempotencyKeys): void {
                    if ($directHsIds->isNotEmpty()) {
                        $query->whereIn('id', $directHsIds);
                    }
                    if ($idempotencyKeys->isNotEmpty()) {
                        $method = $directHsIds->isNotEmpty() ? 'orWhereIn' : 'whereIn';
                        $query->{$method}('idempotency_key', $idempotencyKeys);
                    }
                })
                ->orderBy('id'))
                ->get();
        $journeyEventsById = $journeyEvents->keyBy(fn (HsEvent $event): int => (int) $event->id);
        $journeyEventsByKey = $journeyEvents->groupBy('idempotency_key');

        $legacyJourneyIncidentIds = $journeyIncidents
            ->filter(function (ClientIncident $incident) use ($journeyEventsById, $journeyEventsByKey): bool {
                $event = $incident->hs_event_id === null
                    ? null
                    : $journeyEventsById->get((int) $incident->hs_event_id);
                $event ??= $journeyEventsByKey
                    ->get($this->hsIdempotencyKey($incident))
                    ?->first();

                return $incident->control_room_alert_id === null
                    && $event?->control_room_alert_id === null;
            })
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values();
        $legacyJourneyAlerts = $legacyJourneyIncidentIds->isEmpty()
            ? collect()
            : $read(ControlRoomAlert::query()
                ->whereIn('context->incident_id', $legacyJourneyIncidentIds)
                ->orderBy('id'))
                ->get()
                ->groupBy(fn (ControlRoomAlert $alert): int => (int) data_get($alert->context, 'incident_id'));

        $resolved = $records->map(function (array $record) use (
            $journeyEventsById,
            $journeyEventsByKey,
            $legacyJourneyAlerts,
            $resolve,
            $unavailable,
        ): array {
            if (($record['integrity'] ?? null) === 'unavailable') {
                return $unavailable;
            }

            return $resolve(function () use ($record, $journeyEventsById, $journeyEventsByKey, $legacyJourneyAlerts, $unavailable): array {
                /** @var ControlRoomAlert $alert */
                $alert = $record['alert'];
                /** @var ClientIncident|null $incident */
                $incident = $record['incident'];
                /** @var HsEvent|null $directEvent */
                $directEvent = $record['direct_event'];
                $journeyEvent = null;

                if ($incident !== null) {
                    if (($journeyEventsByKey->get($this->hsIdempotencyKey($incident))?->count() ?? 0) > 1
                        || ($legacyJourneyAlerts->get((int) $incident->id)?->count() ?? 0) > 1
                        || ($incident->hs_event_id !== null && ! $journeyEventsById->has((int) $incident->hs_event_id))
                    ) {
                        return $unavailable;
                    }

                    $journeyEvent = $incident->hs_event_id === null
                        ? null
                        : $journeyEventsById->get((int) $incident->hs_event_id);
                    $journeyEvent ??= $journeyEventsByKey
                        ->get($this->hsIdempotencyKey($incident))
                        ?->first();
                    if ($journeyEvent !== null) {
                        $this->assertHsEventMatchesIncidentForRead(
                            $incident,
                            $journeyEvent,
                        );
                    }

                    $canonicalAlertId = $incident->control_room_alert_id;
                    $canonicalAlertId ??= $journeyEvent?->control_room_alert_id;
                    $canonicalAlertId ??= $legacyJourneyAlerts
                        ->get((int) $incident->id)
                        ?->first()
                        ?->id;
                    if (! is_numeric($canonicalAlertId)
                        || (int) $canonicalAlertId !== (int) $alert->id
                    ) {
                        throw new \DomainException(
                            'Incident journey conflict: the canonical incident resolves to a different alert.',
                        );
                    }
                }

                if ($directEvent !== null) {
                    $this->alertProvenance->assertHealthSafetyEventTuple(
                        $alert,
                        $directEvent,
                    );
                }
                if ($journeyEvent !== null) {
                    $this->alertProvenance->assertHealthSafetyEventTuple(
                        $alert,
                        $journeyEvent,
                    );
                }
                if ($directEvent !== null
                    && $journeyEvent !== null
                    && ! $directEvent->is($journeyEvent)
                ) {
                    throw new \DomainException(
                        'Incident journey conflict: the alert and incident resolve to different H&S events.',
                    );
                }

                return [
                    'incident' => $incident,
                    'hs_event' => $journeyEvent ?? $directEvent,
                ];
            }, $unavailable);
        });

        /** @var EloquentCollection<int, HsEvent> $resolvedEvents */
        $resolvedEvents = new EloquentCollection(
            $resolved
                ->pluck('hs_event')
                ->filter()
                ->unique(fn (HsEvent $event): int => (int) $event->id)
                ->values()
                ->all(),
        );
        if ($reads === null) { $resolvedEvents->loadMissing('correctiveActions'); }
        else { $resolvedEvents->loadMissing(['correctiveActions' => fn ($actions) => $reads->query($actions->getQuery()
            ->select(['id', 'hs_event_id', 'completed_at', 'verified_at', 'closed_at']))]); }

        return $resolved;
    }

    public function closeGate(ClientIncident $incident): JourneyGate
    {
        return $this->buildCloseGate($incident);
    }

    public function closeGateForDisplay(ClientIncident $incident): JourneyGate
    {
        return $this->buildCloseGate($incident);
    }

    private function buildCloseGate(ClientIncident $incident): JourneyGate
    {
        $incident = ClientIncident::query()->findOrFail($incident->getKey());
        $hsEvent = $this->readHsEventForIncident($incident);
        $reviewComplete = in_array($incident->status, ['reviewed', 'closed'], true)
            && $incident->reviewed_at !== null
            && $incident->reviewed_by !== null;
        $followupsComplete = ! $incident->followups()
            ->whereNull('completed_at')
            ->exists();
        $investigationRequired = in_array(
            HsEventService::normaliseSeverity((string) $incident->severity),
            [HsEvent::SEVERITY_HIGH, HsEvent::SEVERITY_CRITICAL],
            true,
        );
        $investigationComplete = ! $investigationRequired
            || $incident->investigation_status === 'completed'
            || $hsEvent?->hasCompletedInvestigation() === true;
        $hasActiveHsInvestigation = $hsEvent?->investigations()
            ->where('status', '!=', HsInvestigation::STATUS_COMPLETED)
            ->exists() === true;
        $healthSafetyComplete = $hsEvent === null
            || $hsEvent->status === HsEvent::STATUS_CLOSED;

        return JourneyGate::fromRequirements([
            [
                'key' => 'incident_review',
                'complete' => $reviewComplete,
                'label' => $reviewComplete
                    ? 'Incident review complete'
                    : 'Complete manager review before closing this incident.',
                'href' => "/incidents/{$incident->id}",
            ],
            [
                'key' => 'incident_followups',
                'complete' => $followupsComplete,
                'label' => $followupsComplete
                    ? 'Incident follow-ups complete'
                    : 'Complete all incident follow-ups before closing this incident.',
                'href' => "/incidents/{$incident->id}",
            ],
            [
                'key' => 'incident_investigation',
                'complete' => $investigationComplete,
                'label' => $investigationComplete
                    ? 'Required incident investigation complete'
                    : 'Complete the required incident investigation before closing this incident.',
                'href' => $hsEvent
                    ? ($hasActiveHsInvestigation
                        ? "/health-safety/events/{$hsEvent->id}?section=investigation"
                        : "/health-safety/events/{$hsEvent->id}?action=investigation")
                    : "/incidents/{$incident->id}",
            ],
            [
                'key' => 'health_safety_governance',
                'complete' => $healthSafetyComplete,
                'label' => $healthSafetyComplete
                    ? ($hsEvent ? 'Linked H&S governance closed' : 'No linked H&S governance requires closure')
                    : 'Close linked H&S governance '.($hsEvent->reference_number ?: "HS-{$hsEvent->id}").' before closing this incident.',
                'href' => $hsEvent
                    ? "/health-safety/events/{$hsEvent->id}"
                    : "/incidents/{$incident->id}",
            ],
        ]);
    }

    private function isNewSubmission(ClientIncident $incident): bool
    {
        return $incident->status === 'submitted' && ($incident->wasRecentlyCreated
            || ($incident->wasChanged('status') && $incident->getOriginal('status') === 'draft'));
    }

    /** Keep the source intent atomic; configuration and assignment happen after the outer commit. */
    private function stageSubmissionRouting(ClientIncident $incident, ?User $actor): void
    {
        $intentId = app(IncidentRoutingService::class)->stageForSubmitted((int) $incident->id, $actor);
        if ($intentId === null) {
            return;
        }
        DB::afterCommit(static function () use ($intentId): void {
            try {
                app(IncidentRoutingService::class)->consume($intentId);
            } catch (\Throwable $exception) {
                // The committed report and durable intent remain available for recovery.
                report($exception);
            }
        });
    }

    private function lockIncident(ClientIncident $incident, ?IncidentResponseJourneyReadContext $responseSource = null): ClientIncident
    {
        if ($responseSource !== null) {
            $responseSource->assertFor($incident);
            $row = $responseSource->incidentQuery(ClientIncident::query()->whereKey($incident->id), true)->first();
            abort_unless($row, 403, 'The original incident source changed before its body read.');
            return $row;
        }
        return ClientIncident::query()
            ->whereKey($incident->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertSubmitted(ClientIncident $incident): void
    {
        if ($incident->status === 'draft' || ! $incident->isSubmitted()) {
            throw new \DomainException('The incident must be submitted before its H&S journey can be ensured.');
        }
    }

    private function lockedIncidentForAlert(ControlRoomAlert $alert): ?ClientIncident
    {
        $direct = ClientIncident::query()
            ->where('control_room_alert_id', $alert->id)
            ->lockForUpdate()
            ->get();

        if ($direct->count() > 1) {
            throw new \DomainException('Incident journey conflict: the alert is directly linked to multiple incidents.');
        }

        if ($direct->isNotEmpty()) {
            return $direct->first();
        }

        $legacyIncidentId = data_get($alert->context, 'incident_id');
        if (! is_numeric($legacyIncidentId)) {
            return null;
        }

        $legacy = ClientIncident::query()
            ->whereKey((int) $legacyIncidentId)
            ->lockForUpdate()
            ->first();

        if ($legacy === null) {
            return null;
        }

        if ($legacy->control_room_alert_id !== null
            && (int) $legacy->control_room_alert_id !== (int) $alert->id
        ) {
            throw new \DomainException('Incident journey conflict: the legacy incident already has a different direct alert.');
        }

        return $legacy;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function incidentAttributes(
        ControlRoomAlert $alert,
        ?ClientIncident $incident,
        array $input,
        User $actor,
    ): array {
        $safe = Arr::only($input, self::INCIDENT_INPUT_FIELDS);
        $clientId = $safe['client_id']
            ?? $incident?->client_id
            ?? $alert->client_id
            ?? data_get($alert->context, 'client_id');

        if (! is_numeric($clientId)) {
            throw new \DomainException('A valid client is required to submit an incident from this alert.');
        }

        $client = Client::query()->find((int) $clientId);
        if ($client === null) {
            throw new \DomainException('A valid client is required to submit an incident from this alert.');
        }

        $siteId = $safe['site_id']
            ?? $incident?->site_id
            ?? $alert->site_id
            ?? $client->site_id;
        $siteId = is_numeric($siteId) ? (int) $siteId : null;
        $this->alertProvenance->assertIncidentTuple(
            $alert,
            (int) $clientId,
            $siteId,
        );
        $type = trim((string) ($safe['type'] ?? $incident?->type ?? $this->typeFromAlert($alert)));
        $type = $type === '' ? 'other' : $type;
        $requestedSeverity = $alert->severity === HsEvent::SEVERITY_CRITICAL
            ? HsEvent::SEVERITY_CRITICAL
            : ($safe['severity'] ?? $incident?->severity ?? $alert->severity ?? HsEvent::SEVERITY_LOW);
        $normalisedSeverity = HsEventService::normaliseSeverity((string) $requestedSeverity);
        $incidentSource = $this->incidentSourceForAlert($alert);
        $hasImmediateActionInput = array_key_exists('immediate_action_taken', $safe);
        $immediateAction = $hasImmediateActionInput
            ? trim((string) ($safe['immediate_action_taken'] ?? ''))
            : trim((string) ($incident?->immediate_action_taken ?? ''));
        if (in_array($normalisedSeverity, [HsEvent::SEVERITY_HIGH, HsEvent::SEVERITY_CRITICAL], true)
            && $immediateAction === ''
        ) {
            throw new \DomainException(
                'Immediate action is required for a high or critical incident.',
            );
        }
        if ($hasImmediateActionInput) {
            $safe['immediate_action_taken'] = $immediateAction === ''
                ? null
                : $immediateAction;
        }

        $inputMetadata = is_array($safe['metadata'] ?? null) ? $safe['metadata'] : [];
        $existingMetadata = is_array($incident?->metadata) ? $incident->metadata : [];
        $metadata = array_replace($inputMetadata, $existingMetadata);
        $existingJourney = is_array($metadata['journey'] ?? null) ? $metadata['journey'] : [];
        $metadata['journey'] = array_replace($existingJourney, [
            'source' => $incidentSource === 'sensor' ? 'sensor_signal' : 'control_room_alert',
            'control_room_alert_id' => $alert->id,
            'original_alert_source' => $existingJourney['original_alert_source'] ?? $alert->source,
            'original_alert_severity' => $alert->severity,
            'submitted_by_user_id' => $actor->id,
        ]);

        unset($safe['metadata']);

        return array_replace($safe, [
            'reference_number' => $incident?->reference_number
                ?? $this->references->next(ClientIncident::REFERENCE_PREFIX),
            'client_id' => (int) $clientId,
            'site_id' => $siteId,
            'type' => $type,
            'severity' => $normalisedSeverity === HsEvent::SEVERITY_CRITICAL
                ? HsEvent::SEVERITY_HIGH
                : $normalisedSeverity,
            'title' => $safe['title']
                ?? $incident?->title
                ?? data_get($alert->context, 'title')
                ?? ucfirst(str_replace(['.', '_'], ' ', $type)).' incident',
            'description' => array_key_exists('description', $safe)
                ? $safe['description']
                : ($incident?->description ?? data_get($alert->context, 'description') ?? $alert->notes),
            'occurred_at' => $safe['occurred_at'] ?? $incident?->occurred_at ?? $alert->triggered_at,
            'metadata' => $metadata,
            'source' => $incidentSource,
            'status' => 'submitted',
            'submitted_at' => $incident?->submitted_at ?? now(),
            'reported_by' => $actor->id,
            'control_room_alert_id' => $alert->id,
        ]);
    }

    private function assertSeriousIncidentHasImmediateAction(
        ClientIncident $incident,
        ?ControlRoomAlert $alert = null,
    ): void {
        $severity = HsEventService::normaliseSeverity((string) $incident->severity);
        $isSerious = $alert?->severity === HsEvent::SEVERITY_CRITICAL
            || in_array($severity, [HsEvent::SEVERITY_HIGH, HsEvent::SEVERITY_CRITICAL], true);
        if ($isSerious
            && blank($incident->immediate_action_taken)
        ) {
            throw new \DomainException(
                'Immediate action is required for a high or critical incident.',
            );
        }
    }

    private function typeFromAlert(ControlRoomAlert $alert): string
    {
        $type = (string) $alert->alert_type;

        return str_starts_with($type, 'incident.')
            ? substr($type, strlen('incident.'))
            : $type;
    }

    private function incidentSourceForAlert(ControlRoomAlert $alert): string
    {
        $trustedSensorSource = in_array(
            (string) $alert->source,
            ['sensor', 'personal_tracker'],
            true,
        );

        return $trustedSensorSource && $alert->signals()->exists()
            ? 'sensor'
            : 'control_room';
    }

    private function ensureCanonicalIncidentSite(ClientIncident $incident, ?IncidentResponseJourneyReadContext $responseSource = null): void
    {
        if ($incident->site_id !== null) {
            return;
        }

        if ($responseSource !== null) {
            $responseSource->recoverSite($incident, $responseSource->discoverHsMetadata());
            return;
        }

        $client = Client::query()
            ->select(['id', 'site_id'])
            ->whereKey($incident->client_id)
            ->lockForUpdate()
            ->first();
        if ($client?->site_id === null) {
            throw new \DomainException(
                'A canonical Site is required before an incident can enter the H&S journey.',
            );
        }

        $incident->forceFill(['site_id' => (int) $client->site_id])->saveQuietly();
        $incident->site_id = (int) $client->site_id;
    }

    private function lockedOrCreatedHsEvent(ClientIncident $incident, ?User $actor, ?IncidentResponseJourneyReadContext $responseSource = null): HsEvent
    {
        $historicSourceEvent = $responseSource !== null
            ? $responseSource->discoverHsMetadata()
            : ($incident->site_id === null ? $this->lockedExactSourceHsEventForSiteRecovery($incident) : null);
        if ($responseSource !== null && $incident->site_id === null) {
            $responseSource->recoverSite($incident, $historicSourceEvent);
        }
        if ($responseSource === null && $historicSourceEvent?->site_id !== null) {
            // A source-owned H&S event is the surviving incident-time snapshot.
            // Restore it before any fallback can consult the client's current Site.
            $incident->forceFill(['site_id' => (int) $historicSourceEvent->site_id])->saveQuietly();
            $incident->site_id = (int) $historicSourceEvent->site_id;
        }

        $this->ensureCanonicalIncidentSite($incident, $responseSource);
        if ($incident->isSubmitted()) {
            $this->assertSeriousIncidentHasImmediateAction($incident);
        }

        $this->ensureOfficialIncidentReference($incident, $responseSource);
        $hsEvent = $responseSource?->existingEvent();

        if ($responseSource === null && $incident->hs_event_id !== null) {
            $hsEvent = HsEvent::query()
                ->whereKey($incident->hs_event_id)
                ->lockForUpdate()
                ->first();

            if ($hsEvent === null) {
                throw new \DomainException('Incident journey conflict: the direct H&S event no longer exists.');
            }
        }

        if ($responseSource === null && $hsEvent === null) {
            $hsEvent = $historicSourceEvent ?? HsEvent::query()
                ->where('idempotency_key', $this->hsIdempotencyKey($incident))
                ->lockForUpdate()
                ->first();
        }

        if ($hsEvent === null) {
            $hsEvent = $this->hsEventService->recordEvent([
                'source' => $incident,
                'event_category' => $this->hsCategory($incident),
                'severity' => $this->hsSeverity($incident),
                'occurred_at' => $incident->occurred_at,
                'reported_at' => $incident->submitted_at ?? $incident->created_at,
                'site_id' => $this->incidentSiteId($incident),
                'client_id' => $incident->client_id,
                'staff_id' => $incident->reported_by,
                'shift_id' => $incident->shift_id,
                'created_by' => $actor?->id ?? $incident->reported_by,
                'handover_status' => HsEvent::HANDOVER_AWAITING_ACCEPTANCE,
                // Ownership starts only when an authorised H&S user accepts the handover.
                'owner_user_id' => null,
            ], $responseSource);

            if ($hsEvent === null) {
                throw new \RuntimeException('The H&S event could not be recorded; the incident journey was rolled back.');
            }

            $hsEvent = $responseSource !== null ? $responseSource->existingEvent() : HsEvent::query()
                ->whereKey($hsEvent->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($hsEvent, 403);
        }

        $otherIncidentQuery = ClientIncident::query()
            ->where('hs_event_id', $hsEvent->id)
            ->where('id', '!=', $incident->id)
            ->lockForUpdate();
        $otherIncident = $responseSource !== null
            ? $responseSource->payloadQuery($otherIncidentQuery)->first(['id']) : $otherIncidentQuery->first();

        if ($otherIncident !== null) {
            throw new \DomainException('Incident journey conflict: the H&S event is directly linked to a different incident.');
        }

        return $hsEvent;
    }

    private function lockedExactSourceHsEventForSiteRecovery(ClientIncident $incident): ?HsEvent
    {
        $events = HsEvent::query()
            ->where('source_type', ClientIncident::class)
            ->where('source_id', $incident->id)
            ->orderBy('id')
            ->limit(2)
            ->lockForUpdate()
            ->get();

        if ($events->count() > 1) {
            throw new \DomainException(
                'Incident journey conflict: multiple H&S events claim the incident source tuple.',
            );
        }

        $event = $events->first();
        if ($event === null) {
            return null;
        }

        if (! $this->hsTupleIsCanonical($incident, $event)) {
            throw new \DomainException(
                'Incident journey conflict: the historic H&S event does not own the canonical incident tuple.',
            );
        }

        $this->assertHsEventMatchesIncidentForRead(
            $incident,
            $event,
        );

        return $event;
    }

    private function ensureOfficialIncidentReference(ClientIncident $incident, ?IncidentResponseJourneyReadContext $responseSource = null): void
    {
        if (filled($incident->reference_number)) {
            return;
        }

        $attributes = ['reference_number' => $this->references->next(ClientIncident::REFERENCE_PREFIX)];
        if ($responseSource !== null) { $responseSource->persist($incident, $attributes); }
        else { $incident->forceFill($attributes)->saveQuietly(); }
    }

    private function lockedAlertForIncident(ClientIncident $incident, HsEvent $hsEvent, ?IncidentResponseJourneyReadContext $responseSource = null): ?ControlRoomAlert
    {
        if ($incident->control_room_alert_id !== null) {
            $query = ControlRoomAlert::query()->whereKey($incident->control_room_alert_id)->lockForUpdate();
            return ($responseSource?->payloadQuery($query) ?? $query)->first();
        }

        if ($hsEvent->control_room_alert_id !== null) {
            $query = ControlRoomAlert::query()->whereKey($hsEvent->control_room_alert_id)->lockForUpdate();
            return ($responseSource?->payloadQuery($query) ?? $query)->first();
        }

        $legacyQuery = ControlRoomAlert::query()
            ->where('context->incident_id', $incident->id)
            ->orderBy('id')
            ->lockForUpdate();
        $legacyAlerts = ($responseSource?->payloadQuery($legacyQuery) ?? $legacyQuery)->get();

        if ($legacyAlerts->count() > 1) {
            throw new \DomainException('Incident journey conflict: multiple legacy alerts claim the same incident.');
        }

        return $legacyAlerts->first();
    }

    private function createIncidentAlert(ClientIncident $incident, User $actor, ?string $reason): ControlRoomAlert
    {
        return ControlRoomAlert::query()->create([
            'source' => 'incident',
            'alert_type' => 'incident.'.$incident->type,
            'severity' => $this->alertSeverity($incident),
            'status' => ControlRoomAlert::STATUS_OPEN,
            'site_id' => $this->incidentSiteId($incident),
            'client_id' => $incident->client_id,
            'triggered_at' => $incident->occurred_at ?? $incident->submitted_at ?? now(),
            'created_by_user_id' => $actor->id,
            'context' => $this->incidentAlertContext($incident, $reason),
        ]);
    }

    private function actorRequiredForAlert(ClientIncident $incident, ?User $actor): User
    {
        if ($actor !== null) {
            return $actor;
        }

        if ($incident->reported_by !== null) {
            $reporter = User::query()->find($incident->reported_by);
            if ($reporter !== null) {
                return $reporter;
            }
        }

        throw new \DomainException('An actor is required to create the Control Room alert for this incident.');
    }

    private function assertJourneyLinksDoNotConflict(
        ClientIncident $incident,
        ?ControlRoomAlert $alert,
        HsEvent $hsEvent,
        ?IncidentResponseJourneyReadContext $responseSource = null,
    ): void {
        if ($alert !== null) {
            $this->assertAlertMatchesIncident($incident, $alert);
        }

        if ($incident->hs_event_id !== null && (int) $incident->hs_event_id !== (int) $hsEvent->id) {
            throw new \DomainException('Incident journey conflict: the incident has a different direct H&S event.');
        }

        if ($alert !== null
            && $incident->control_room_alert_id !== null
            && (int) $incident->control_room_alert_id !== (int) $alert->id
        ) {
            throw new \DomainException('Incident journey conflict: the incident has a different direct alert.');
        }

        if ($alert !== null
            && $hsEvent->control_room_alert_id !== null
            && (int) $hsEvent->control_room_alert_id !== (int) $alert->id
        ) {
            throw new \DomainException('Incident journey conflict: the H&S event has a different direct alert.');
        }

        if ($alert === null && $hsEvent->control_room_alert_id !== null) {
            throw new \DomainException('Incident journey conflict: the H&S event has an alert that could not be resolved.');
        }

        if ($alert !== null) {
            $otherIncidentQuery = ClientIncident::query()
                ->where('control_room_alert_id', $alert->id)
                ->where('id', '!=', $incident->id)
                ->lockForUpdate();
            $otherIncident = $responseSource !== null ? $responseSource->payloadQuery($otherIncidentQuery)->first(['id']) : $otherIncidentQuery->first();

            if ($otherIncident !== null) {
                throw new \DomainException('Incident journey conflict: the alert is directly linked to a different incident.');
            }

            $otherHsQuery = HsEvent::query()
                ->where('control_room_alert_id', $alert->id)
                ->where('id', '!=', $hsEvent->id)
                ->lockForUpdate();
            $otherHsEvent = $responseSource !== null ? $responseSource->payloadQuery($otherHsQuery)->first(['id']) : $otherHsQuery->first();

            if ($otherHsEvent !== null) {
                throw new \DomainException('Incident journey conflict: the alert is directly linked to a different H&S event.');
            }
        }
    }

    private function assertDraftAlertLinkDoesNotConflict(
        ClientIncident $incident,
        ControlRoomAlert $alert,
    ): void {
        $this->assertAlertMatchesIncident($incident, $alert);
        $existingHsEvent = HsEvent::query()
            ->where('source_type', ClientIncident::class)
            ->where('source_id', $incident->id)
            ->lockForUpdate()
            ->first();

        if ($incident->hs_event_id !== null
            || $existingHsEvent !== null
        ) {
            throw new \DomainException('Incident journey conflict: a draft incident cannot own an H&S event.');
        }

        if ($incident->control_room_alert_id !== null
            && (int) $incident->control_room_alert_id !== (int) $alert->id
        ) {
            throw new \DomainException('Incident journey conflict: the draft incident has a different direct alert.');
        }

        $otherIncident = ClientIncident::query()
            ->where('control_room_alert_id', $alert->id)
            ->where('id', '!=', $incident->id)
            ->lockForUpdate()
            ->first();
        if ($otherIncident !== null) {
            throw new \DomainException('Incident journey conflict: the alert is directly linked to a different incident.');
        }

        $claimedHsEvent = HsEvent::query()
            ->where('control_room_alert_id', $alert->id)
            ->lockForUpdate()
            ->first();
        if ($claimedHsEvent !== null) {
            throw new \DomainException('Incident journey conflict: a draft incident alert is already linked to H&S.');
        }
    }

    private function assertAlertMatchesIncident(
        ClientIncident $incident,
        ControlRoomAlert $alert,
    ): void {
        if ($incident->client_id === null
            || $alert->client_id === null
            || (int) $incident->client_id !== (int) $alert->client_id
        ) {
            throw new \DomainException('Incident journey conflict: the alert client does not match the incident client.');
        }

        $hasCanonicalDirectLink = $incident->control_room_alert_id !== null
            && (int) $incident->control_room_alert_id === (int) $alert->id;

        foreach (['incident_id', 'normalized_data.incident_id'] as $path) {
            $claim = data_get($alert->context, $path);
            if ($claim === null || $claim === '') {
                continue;
            }

            if ((! is_numeric($claim) || (int) $claim !== (int) $incident->id)
                && ! $hasCanonicalDirectLink
            ) {
                throw new \DomainException('Incident journey conflict: the alert context claims a different incident.');
            }
        }
    }

    private function linkDraftAlert(ClientIncident $incident, ControlRoomAlert $alert): void
    {
        $incident->forceFill(['control_room_alert_id' => $alert->id])->saveQuietly();
        $context = array_replace((array) $alert->context, [
            'incident_id' => $incident->id,
        ]);
        $alert->forceFill(['context' => $context])->saveQuietly();
    }

    private function applyRequestedSeverityFloor(
        ClientIncident $incident,
        ?string $requestedSeverity,
        ?IncidentResponseJourneyReadContext $responseSource = null,
    ): void {
        if ($requestedSeverity === null || trim($requestedSeverity) === '') {
            return;
        }

        $metadata = is_array($incident->metadata) ? $incident->metadata : [];
        $journey = is_array($metadata['journey'] ?? null) ? $metadata['journey'] : [];
        $journey['original_alert_severity'] = $this->higherSeverity(
            $this->hsSeverity($incident),
            HsEventService::normaliseSeverity($requestedSeverity),
        );
        $metadata['journey'] = $journey;

        if ($responseSource !== null) { $responseSource->persist($incident, ['metadata' => $metadata]); }
        else { $incident->forceFill(['metadata' => $metadata])->saveQuietly(); }
    }

    private function promoteAlertToIncidentFloor(
        ClientIncident $incident,
        ControlRoomAlert $alert,
        ?IncidentResponseJourneyReadContext $responseSource = null,
    ): bool {
        $severity = $this->higherSeverity($alert->severity, $this->alertSeverity($incident));
        $wasPromoted = $severity !== $alert->severity;
        if ($wasPromoted) {
            $alert->forceFill(['severity' => $severity])->saveQuietly();
        }

        $this->stampAlertProvenance($incident, $alert, $responseSource);

        return $wasPromoted;
    }

    private function stampAlertProvenance(ClientIncident $incident, ControlRoomAlert $alert, ?IncidentResponseJourneyReadContext $responseSource = null): void
    {
        $metadata = is_array($incident->metadata) ? $incident->metadata : [];
        $journey = is_array($metadata['journey'] ?? null) ? $metadata['journey'] : [];
        $alertSeverity = HsEventService::normaliseSeverity((string) $alert->severity);

        $journey['control_room_alert_id'] = $alert->id;
        $journey['original_alert_source'] ??= $alert->source;
        $journey['original_alert_severity'] = $this->higherSeverity(
            is_string($journey['original_alert_severity'] ?? null)
                ? $journey['original_alert_severity']
                : null,
            $alertSeverity,
        );
        $metadata['journey'] = $journey;

        if ($responseSource !== null) { $responseSource->persist($incident, ['metadata' => $metadata]); }
        else { $incident->forceFill(['metadata' => $metadata])->saveQuietly(); }
    }

    /**
     * @param  array<string, mixed>  $alertContext
     */
    private function linkJourney(
        ClientIncident $incident,
        ?ControlRoomAlert $alert,
        HsEvent $hsEvent,
        ?User $actor,
        array $alertContext,
        ?IncidentResponseJourneyReadContext $responseSource = null,
    ): void {
        $mayAdoptIncidentWorksafe = $incident->hs_event_id === null
            || ! $this->hsTupleIsCanonical($incident, $hsEvent);
        $incidentLinks = ['hs_event_id' => $hsEvent->id];
        if ($incident->site_id === null && $hsEvent->site_id !== null) {
            // Historic source-linked events already contain the incident-time
            // site snapshot. Freeze that value on the new direct incident link
            // before synchronisation can consult the client's current site.
            $incidentLinks['site_id'] = $hsEvent->site_id;
        }
        if ($alert !== null) {
            $incidentLinks['control_room_alert_id'] = $alert->id;
        }
        if ($responseSource !== null) { $responseSource->persistLinks($incident, $hsEvent, $alert); }
        else { $incident->forceFill($incidentLinks)->saveQuietly(); }

        $this->synchroniseHsEvent(
            $hsEvent,
            $incident,
            $alert,
            $actor,
            $mayAdoptIncidentWorksafe,
            $responseSource,
        );

        if ($alert !== null) {
            $context = array_replace_recursive($alertContext, (array) $alert->context, [
                'incident_id' => $incident->id,
            ]);
            $alert->forceFill(['context' => $context])->saveQuietly();
        }
    }

    private function synchroniseHsEvent(
        HsEvent $hsEvent,
        ClientIncident $incident,
        ?ControlRoomAlert $alert,
        ?User $actor,
        bool $mayAdoptIncidentWorksafe,
        ?IncidentResponseJourneyReadContext $responseSource = null,
    ): void {
        $severity = $this->higherSeverity($hsEvent->severity, $this->hsSeverity($incident));
        $worksafe = $this->canonicalWorksafeValues(
            $hsEvent,
            $incident,
            $mayAdoptIncidentWorksafe,
        );
        $handoverStatus = $hsEvent->handover_status === HsEvent::HANDOVER_ACCEPTED
            ? HsEvent::HANDOVER_ACCEPTED
            : HsEvent::HANDOVER_AWAITING_ACCEPTANCE;
        $hsTuple = $this->canonicalHsTuple($incident);

        $attributes = [
            ...$hsTuple,
            'severity' => $severity,
            'site_id' => $this->incidentSiteId($incident),
            'client_id' => $incident->client_id,
            'staff_id' => $incident->reported_by,
            'shift_id' => $incident->shift_id,
            ...$worksafe,
            'investigation_required' => (bool) $hsEvent->investigation_required
                || $worksafe['worksafe_notifiable']
                || in_array($severity, [HsEvent::SEVERITY_HIGH, HsEvent::SEVERITY_CRITICAL], true),
            'control_room_alert_id' => $alert?->id ?? $hsEvent->control_room_alert_id,
            'handover_status' => $handoverStatus,
            'owner_user_id' => $handoverStatus === HsEvent::HANDOVER_ACCEPTED
                ? $hsEvent->owner_user_id
                : null,
        ];
        if ($responseSource !== null) { $responseSource->persistEvent($hsEvent, $attributes); }
        else { $hsEvent->forceFill($attributes)->saveQuietly(); }
    }

    /**
     * H&S becomes authoritative once the direct incident link is canonical.
     * Before that first link/adoption, legacy incident values may only fill gaps
     * or promote progress; they never demote or overwrite H&S state.
     *
     * @return array<string, mixed>
     */
    private function canonicalWorksafeValues(
        HsEvent $hsEvent,
        ClientIncident $incident,
        bool $mayAdoptIncidentWorksafe,
    ): array {
        if (! $mayAdoptIncidentWorksafe) {
            return [
                'worksafe_notifiable' => $hsEvent->worksafe_notifiable,
                'worksafe_status' => $hsEvent->worksafe_status,
                'worksafe_reference' => $hsEvent->worksafe_reference,
                'worksafe_notified_at' => $hsEvent->worksafe_notified_at,
                'worksafe_method' => $hsEvent->worksafe_method,
                'worksafe_acknowledged_at' => $hsEvent->worksafe_acknowledged_at,
                'worksafe_site_preserved' => (bool) $hsEvent->worksafe_site_preserved,
            ];
        }

        $notifiable = $hsEvent->worksafe_notifiable;
        if ($incident->is_notifiable === true) {
            // A positive legacy source classification must never be lost while
            // the direct H&S link is first adopted. The legacy false default is
            // not an explicit decision and must leave a new event undecided.
            $notifiable = true;
        }
        $incidentStatus = $incident->is_notifiable
            ? ($incident->worksafe_notification_status ?: HsEvent::WORKSAFE_PENDING)
            : null;

        return [
            'worksafe_notifiable' => $notifiable,
            'worksafe_status' => $notifiable
                ? $this->higherWorksafeStatus($hsEvent->worksafe_status, $incidentStatus)
                : $hsEvent->worksafe_status,
            'worksafe_reference' => $hsEvent->worksafe_reference ?: $incident->worksafe_reference,
            'worksafe_notified_at' => $hsEvent->worksafe_notified_at ?? $incident->worksafe_notified_at,
            'worksafe_method' => $hsEvent->worksafe_method,
            'worksafe_acknowledged_at' => $hsEvent->worksafe_acknowledged_at,
            'worksafe_site_preserved' => (bool) $hsEvent->worksafe_site_preserved
                || (bool) $incident->site_preserved,
        ];
    }

    private function higherWorksafeStatus(?string $current, ?string $candidate): ?string
    {
        $rank = [
            null => 0,
            HsEvent::WORKSAFE_PENDING => 1,
            HsEvent::WORKSAFE_NOTIFIED => 2,
            HsEvent::WORKSAFE_ACKNOWLEDGED => 3,
        ];

        return ($rank[$current] ?? 0) >= ($rank[$candidate] ?? 0)
            ? $current
            : $candidate;
    }

    /** @return array<string, mixed> */
    private function incidentAlertContext(ClientIncident $incident, ?string $reason): array
    {
        return [
            'incident_id' => $incident->id,
            'type' => $incident->type,
            'incident_type' => $incident->type,
            'shift_id' => $incident->shift_id,
            'site_id' => $this->incidentSiteId($incident),
            'occurred_at' => $incident->occurred_at?->toIso8601String(),
            'description' => $incident->description,
            'reported_by' => $incident->reported_by,
            'reason' => $reason,
            'provenance' => [
                'source' => 'incident_journey',
                'service' => self::class,
            ],
        ];
    }

    private function adoptExplicitIncidentReason(ControlRoomAlert $alert, ?string $reason): void
    {
        if ($reason === null
            || trim($reason) === ''
            || data_get($alert->context, 'provenance.source') !== 'incident_journey'
        ) {
            return;
        }

        $context = (array) $alert->context;
        $context['reason'] = $reason;
        $alert->forceFill(['context' => $context])->saveQuietly();
    }

    private function requiresAutomaticAlert(ClientIncident $incident): bool
    {
        return in_array(
            $this->alertSeverity($incident),
            [HsEvent::SEVERITY_HIGH, HsEvent::SEVERITY_CRITICAL],
            true,
        );
    }

    private function assertHsTupleCanBeCanonicalised(ClientIncident $incident, HsEvent $hsEvent, ?IncidentResponseJourneyReadContext $responseSource = null): void
    {
        if ($this->hsTupleIsCanonical($incident, $hsEvent)) {
            return;
        }

        $tuple = $this->canonicalHsTuple($incident);
        if ($hsEvent->source_type !== $tuple['source_type']
            || (int) $hsEvent->source_id !== $tuple['source_id']
        ) {
            throw new \DomainException(
                'Incident journey conflict: the direct H&S event belongs to a different source.',
            );
        }

        $conflictQuery = HsEvent::query()
            ->where('id', '!=', $hsEvent->id)
            ->where(function ($query) use ($tuple): void {
                $query->where('idempotency_key', $tuple['idempotency_key'])
                    ->orWhere(function ($sourceQuery) use ($tuple): void {
                        $sourceQuery
                            ->where('source_type', $tuple['source_type'])
                            ->where('source_id', $tuple['source_id']);
                    });
            })
            ->lockForUpdate();
        $conflict = $responseSource !== null ? $responseSource->payloadQuery($conflictQuery)->first(['id']) : $conflictQuery->first();

        if ($conflict !== null) {
            throw new \DomainException(
                'Incident journey conflict: another H&S event already owns the canonical incident tuple.',
            );
        }
    }

    /** @return array{source_type: class-string<ClientIncident>, source_id: int, event_category: string, idempotency_key: string} */
    private function canonicalHsTuple(ClientIncident $incident): array
    {
        return [
            'source_type' => ClientIncident::class,
            'source_id' => (int) $incident->id,
            'event_category' => $this->hsCategory($incident),
            'idempotency_key' => $this->hsIdempotencyKey($incident),
        ];
    }

    private function hsTupleIsCanonical(ClientIncident $incident, HsEvent $hsEvent): bool
    {
        $tuple = $this->canonicalHsTuple($incident);

        return $hsEvent->source_type === $tuple['source_type']
            && (int) $hsEvent->source_id === $tuple['source_id']
            && $hsEvent->event_category === $tuple['event_category']
            && $hsEvent->idempotency_key === $tuple['idempotency_key'];
    }

    private function hsCategory(ClientIncident $incident): string
    {
        return $incident->type === 'near_miss'
            ? HsEvent::CATEGORY_NEAR_MISS
            : HsEvent::CATEGORY_INCIDENT;
    }

    private function hsIdempotencyKey(ClientIncident $incident): string
    {
        return HsEvent::buildIdempotencyKey(
            ClientIncident::class,
            $incident->id,
            $this->hsCategory($incident),
        );
    }

    private function hsSeverity(ClientIncident $incident): string
    {
        $originalAlertSeverity = data_get($incident->metadata, 'journey.original_alert_severity');
        $incidentSeverity = HsEventService::normaliseSeverity((string) $incident->severity);

        if (! is_string($originalAlertSeverity) || trim($originalAlertSeverity) === '') {
            return $incidentSeverity;
        }

        return $this->higherSeverity(
            $incidentSeverity,
            HsEventService::normaliseSeverity($originalAlertSeverity),
        );
    }

    private function alertSeverity(ClientIncident $incident): string
    {
        return $this->hsSeverity($incident);
    }

    private function higherSeverity(?string $current, string $target): string
    {
        $rank = [
            HsEvent::SEVERITY_LOW => 0,
            HsEvent::SEVERITY_MEDIUM => 1,
            HsEvent::SEVERITY_HIGH => 2,
            HsEvent::SEVERITY_CRITICAL => 3,
        ];

        $current = HsEventService::normaliseSeverity((string) $current);

        return ($rank[$current] ?? 0) >= ($rank[$target] ?? 0) ? $current : $target;
    }

    private function incidentSiteId(ClientIncident $incident): int
    {
        if ($incident->site_id === null) {
            throw new \DomainException(
                'A canonical Site is required before an incident can enter the H&S journey.',
            );
        }

        return (int) $incident->site_id;
    }

    private function readHsEventForIncident(ClientIncident $incident): ?HsEvent
    {
        if ($incident->hs_event_id !== null) {
            $direct = HsEvent::query()->find($incident->hs_event_id);
            if ($direct !== null) {
                $this->assertHsEventMatchesIncidentForRead(
                    $incident,
                    $direct,
                );

                return $direct;
            }
        }

        $fallback = HsEvent::query()
            ->where('idempotency_key', $this->hsIdempotencyKey($incident))
            ->first();
        if ($fallback !== null) {
            // A fallback has no explicit incident FK to establish ownership,
            // so its canonical source tuple remains mandatory for every read.
            $this->assertHsEventMatchesIncidentForRead(
                $incident,
                $fallback,
            );
        }

        return $fallback;
    }

    private function assertHsEventMatchesIncidentForRead(
        ClientIncident $incident,
        HsEvent $event,
    ): void {
        $tuple = $this->canonicalHsTuple($incident);
        $sourceType = ltrim((string) $event->source_type, '\\');
        if ($sourceType !== $tuple['source_type']
            || (int) $event->source_id !== $tuple['source_id']
            || $event->event_category !== $tuple['event_category']
            || $event->idempotency_key !== $tuple['idempotency_key']
        ) {
            throw new \DomainException(
                'Incident journey conflict: the direct H&S event does not match the canonical incident tuple.',
            );
        }

        if ($incident->control_room_alert_id !== null
            && $event->control_room_alert_id !== null
            && (int) $incident->control_room_alert_id !== (int) $event->control_room_alert_id
        ) {
            throw new \DomainException(
                'Incident journey conflict: the H&S event has a different direct alert.',
            );
        }

        $incidentClientId = $incident->client_id === null ? null : (int) $incident->client_id;
        $eventClientId = $event->client_id === null ? null : (int) $event->client_id;
        if ($incidentClientId !== $eventClientId) {
            throw new \DomainException(
                'Incident journey conflict: the direct H&S event client_id does not match the incident.',
            );
        }

        if ($incident->site_id !== null
            && ($event->site_id === null
                || (int) $incident->site_id !== (int) $event->site_id)
        ) {
            throw new \DomainException(
                'Incident journey conflict: the direct H&S event site_id does not match the incident.',
            );
        }
    }

    private function readAlertForIncident(ClientIncident $incident, ?HsEvent $hsEvent): ?ControlRoomAlert
    {
        if ($incident->control_room_alert_id !== null) {
            $direct = ControlRoomAlert::query()->find($incident->control_room_alert_id);
            if ($direct !== null) {
                return $direct;
            }
        }

        if ($hsEvent?->control_room_alert_id !== null) {
            $direct = ControlRoomAlert::query()->find($hsEvent->control_room_alert_id);
            if ($direct !== null) {
                return $direct;
            }
        }

        return ControlRoomAlert::query()
            ->where('context->incident_id', $incident->id)
            ->orderBy('id')
            ->first();
    }

    private function freshJourney(
        ClientIncident $incident,
        ?ControlRoomAlert $alert,
        HsEvent $hsEvent,
        ?IncidentResponseJourneyReadContext $responseSource = null,
    ): IncidentJourney {
        if ($responseSource !== null) {
            $row = $responseSource->incidentQuery(ClientIncident::query()->whereKey($incident->id))->first();
            abort_unless($row, 403, 'The original incident source changed before the final body read.');
            $event = $responseSource->existingEvent();
            abort_unless($event, 403);
            $currentAlert = $alert === null ? null : $responseSource->payloadQuery(ControlRoomAlert::query()->whereKey($alert->id))->first();
            $responseSource->assertCurrent();
            return new IncidentJourney($row, $currentAlert, $event);
        }
        return new IncidentJourney(
            $incident->fresh(),
            $alert?->fresh(),
            $hsEvent->fresh(),
        );
    }
}
