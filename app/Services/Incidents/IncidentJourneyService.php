<?php

namespace App\Services\Incidents;

use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ControlRoomAlert;
use App\Models\HsEvent;
use App\Models\User;
use App\Services\ControlRoom\IncidentAlertOperationalInitializer;
use App\Services\HealthSafety\HsEventService;
use App\Services\References\ReferenceNumberGenerator;
use Illuminate\Support\Arr;
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

            if ($incident === null) {
                $attributes = $this->incidentAttributes($lockedAlert, null, $input, $actor);
                $incident = ClientIncident::withoutEvents(
                    fn () => ClientIncident::query()->create($attributes),
                );
            } elseif ($incident->status === 'draft') {
                $attributes = $this->incidentAttributes($lockedAlert, $incident, $input, $actor);
                $incident->forceFill($attributes)->saveQuietly();
                $incident->refresh();
            }

            $hsEvent = $this->lockedOrCreatedHsEvent($incident, $actor);
            $this->assertHsTupleCanBeCanonicalised($incident, $hsEvent);

            $this->assertJourneyLinksDoNotConflict($incident, $lockedAlert, $hsEvent);
            $this->linkJourney($incident, $lockedAlert, $hsEvent, $actor, [
                'incident_id' => $incident->id,
            ]);

            return $this->freshJourney($incident, $lockedAlert, $hsEvent);
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * Ensure a submitted incident has its canonical H&S event and, when high or
     * critical-equivalent, its one incident-backed alert.
     */
    public function ensureForSubmittedIncident(ClientIncident $incident, ?User $actor = null): IncidentJourney
    {
        return DB::transaction(function () use ($incident, $actor): IncidentJourney {
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
    ): IncidentJourney {
        return DB::transaction(function () use ($incident, $actor, $reason, $requestedSeverity): IncidentJourney {
            $lockedIncident = $this->lockIncident($incident);
            $this->assertSubmitted($lockedIncident);
            $this->applyRequestedSeverityFloor($lockedIncident, $requestedSeverity);

            $hsEvent = $this->lockedOrCreatedHsEvent($lockedIncident, $actor);
            $this->assertHsTupleCanBeCanonicalised($lockedIncident, $hsEvent);
            $alert = $this->lockedAlertForIncident($lockedIncident, $hsEvent);
            $alertWasCreated = $alert === null;
            $alert ??= $this->createIncidentAlert($lockedIncident, $actor, $reason);

            $alertWasPromoted = $this->promoteAlertToIncidentFloor($lockedIncident, $alert);
            $this->adoptExplicitIncidentReason($alert, $reason);
            $this->assertJourneyLinksDoNotConflict($lockedIncident, $alert, $hsEvent);
            $this->linkJourney(
                $lockedIncident,
                $alert,
                $hsEvent,
                $actor,
                $this->incidentAlertContext($lockedIncident, $reason),
            );

            if ($alertWasCreated || $alertWasPromoted) {
                $this->alertOperations->initialiseNewAlert($alert);
            }

            return $this->freshJourney($lockedIncident, $alert, $hsEvent);
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
    ): IncidentJourney {
        return DB::transaction(function () use ($incident, $alert, $actor): IncidentJourney {
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

    private function lockIncident(ClientIncident $incident): ClientIncident
    {
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
        $type = trim((string) ($safe['type'] ?? $incident?->type ?? $this->typeFromAlert($alert)));
        $type = $type === '' ? 'other' : $type;
        $requestedSeverity = $alert->severity === HsEvent::SEVERITY_CRITICAL
            ? HsEvent::SEVERITY_CRITICAL
            : ($safe['severity'] ?? $incident?->severity ?? $alert->severity ?? HsEvent::SEVERITY_LOW);
        $normalisedSeverity = HsEventService::normaliseSeverity((string) $requestedSeverity);
        $incidentSource = $this->incidentSourceForAlert($alert);

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
            'site_id' => is_numeric($siteId) ? (int) $siteId : null,
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

    private function lockedOrCreatedHsEvent(ClientIncident $incident, ?User $actor): HsEvent
    {
        $hsEvent = null;

        if ($incident->hs_event_id !== null) {
            $hsEvent = HsEvent::query()
                ->whereKey($incident->hs_event_id)
                ->lockForUpdate()
                ->first();

            if ($hsEvent === null) {
                throw new \DomainException('Incident journey conflict: the direct H&S event no longer exists.');
            }
        }

        if ($hsEvent === null) {
            $hsEvent = HsEvent::query()
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
                'worksafe_notifiable' => (bool) $incident->is_notifiable,
                'created_by' => $actor?->id ?? $incident->reported_by,
                'handover_status' => HsEvent::HANDOVER_AWAITING_ACCEPTANCE,
                // Ownership starts only when an authorised H&S user accepts the handover.
                'owner_user_id' => null,
            ]);

            if ($hsEvent === null) {
                throw new \RuntimeException('The H&S event could not be recorded; the incident journey was rolled back.');
            }

            $hsEvent = HsEvent::query()
                ->whereKey($hsEvent->id)
                ->lockForUpdate()
                ->firstOrFail();
        }

        $otherIncident = ClientIncident::query()
            ->where('hs_event_id', $hsEvent->id)
            ->where('id', '!=', $incident->id)
            ->lockForUpdate()
            ->first();

        if ($otherIncident !== null) {
            throw new \DomainException('Incident journey conflict: the H&S event is directly linked to a different incident.');
        }

        return $hsEvent;
    }

    private function lockedAlertForIncident(ClientIncident $incident, HsEvent $hsEvent): ?ControlRoomAlert
    {
        if ($incident->control_room_alert_id !== null) {
            return ControlRoomAlert::query()
                ->whereKey($incident->control_room_alert_id)
                ->lockForUpdate()
                ->first();
        }

        if ($hsEvent->control_room_alert_id !== null) {
            return ControlRoomAlert::query()
                ->whereKey($hsEvent->control_room_alert_id)
                ->lockForUpdate()
                ->first();
        }

        $legacyAlerts = ControlRoomAlert::query()
            ->where('context->incident_id', $incident->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

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
            $otherIncident = ClientIncident::query()
                ->where('control_room_alert_id', $alert->id)
                ->where('id', '!=', $incident->id)
                ->lockForUpdate()
                ->first();

            if ($otherIncident !== null) {
                throw new \DomainException('Incident journey conflict: the alert is directly linked to a different incident.');
            }

            $otherHsEvent = HsEvent::query()
                ->where('control_room_alert_id', $alert->id)
                ->where('id', '!=', $hsEvent->id)
                ->lockForUpdate()
                ->first();

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

        $incident->forceFill(['metadata' => $metadata])->saveQuietly();
    }

    private function promoteAlertToIncidentFloor(
        ClientIncident $incident,
        ControlRoomAlert $alert,
    ): bool {
        $severity = $this->higherSeverity($alert->severity, $this->alertSeverity($incident));
        $wasPromoted = $severity !== $alert->severity;
        if ($wasPromoted) {
            $alert->forceFill(['severity' => $severity])->saveQuietly();
        }

        $this->stampAlertProvenance($incident, $alert);

        return $wasPromoted;
    }

    private function stampAlertProvenance(ClientIncident $incident, ControlRoomAlert $alert): void
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

        $incident->forceFill(['metadata' => $metadata])->saveQuietly();
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
        $incident->forceFill($incidentLinks)->saveQuietly();

        $this->synchroniseHsEvent(
            $hsEvent,
            $incident,
            $alert,
            $actor,
            $mayAdoptIncidentWorksafe,
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

        $hsEvent->forceFill([
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
        ])->saveQuietly();
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
                'worksafe_notifiable' => (bool) $hsEvent->worksafe_notifiable,
                'worksafe_status' => $hsEvent->worksafe_status,
                'worksafe_reference' => $hsEvent->worksafe_reference,
                'worksafe_notified_at' => $hsEvent->worksafe_notified_at,
                'worksafe_method' => $hsEvent->worksafe_method,
                'worksafe_acknowledged_at' => $hsEvent->worksafe_acknowledged_at,
                'worksafe_site_preserved' => (bool) $hsEvent->worksafe_site_preserved,
            ];
        }

        $notifiable = (bool) $hsEvent->worksafe_notifiable || (bool) $incident->is_notifiable;
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

    private function assertHsTupleCanBeCanonicalised(ClientIncident $incident, HsEvent $hsEvent): void
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

        $conflict = HsEvent::query()
            ->where('id', '!=', $hsEvent->id)
            ->where(function ($query) use ($tuple): void {
                $query->where('idempotency_key', $tuple['idempotency_key'])
                    ->orWhere(function ($sourceQuery) use ($tuple): void {
                        $sourceQuery
                            ->where('source_type', $tuple['source_type'])
                            ->where('source_id', $tuple['source_id']);
                    });
            })
            ->lockForUpdate()
            ->first();

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

    private function incidentSiteId(ClientIncident $incident): ?int
    {
        if ($incident->site_id !== null) {
            return (int) $incident->site_id;
        }

        $clientSiteId = Client::query()->whereKey($incident->client_id)->value('site_id');

        return $clientSiteId === null ? null : (int) $clientSiteId;
    }

    private function readHsEventForIncident(ClientIncident $incident): ?HsEvent
    {
        if ($incident->hs_event_id !== null) {
            $direct = HsEvent::query()->find($incident->hs_event_id);
            if ($direct !== null) {
                return $direct;
            }
        }

        return HsEvent::query()
            ->where('idempotency_key', $this->hsIdempotencyKey($incident))
            ->first();
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
    ): IncidentJourney {
        return new IncidentJourney(
            $incident->fresh(),
            $alert?->fresh(),
            $hsEvent->fresh(),
        );
    }
}
