<?php

namespace App\Services\Fleet;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\FleetMedicationTransitLog;
use App\Models\FleetResidentTransport;
use App\Models\FleetResidentTransportEvent;
use App\Models\FleetVehicleBooking;
use App\Models\MedicationOrderVersion;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CoverageRoleService;
use App\Services\EnhancedMarService;
use App\Services\Medication\ControlledMedicationTransportWitnessService;
use App\Services\Medication\MedicationAdministratorCompetencyPolicy;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\MedicationIncidentIntegrationService;
use App\Services\MedicationRuleService;
use App\Services\MedicationScanVerificationService;
use App\Services\ShiftOperationalSnapshotService;
use App\Support\Medication\MedicationStockQuantity;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ResidentTransportJourneyService
{
    private const OFFLINE_CAPTURE_FUTURE_SKEW_MINUTES = 5;

    public function __construct(
        private readonly ResidentTransportJourneyScope $scope,
        private readonly CoverageRoleService $coverageRoles,
        private readonly ShiftOperationalSnapshotService $snapshots,
        private readonly MedicationScanVerificationService $scanVerification,
        private readonly ControlledMedicationTransportWitnessService $transportWitnesses,
        private readonly MedicationGovernanceScopeService $medicationGovernance,
        private readonly MedicationAdministratorCompetencyPolicy $administratorCompetency,
        private readonly EnhancedMarService $emar,
        private readonly MedicationRuleService $medicationRules,
        private readonly MedicationIncidentIntegrationService $incidents,
    ) {}

    private function assertCanManageMedicationTransit(User $actor): void
    {
        abort_unless($actor->canDo('fleet.medication.manage'), 403);
    }

    private function assertCanAdministerMedication(User $actor): void
    {
        abort_unless($actor->canDo('medications.administer.record'), 403);
    }

    /** @return Collection<int, User> */
    private function lockCurrentFleetMedicationUsers(
        User $actor,
        int $siteId,
        string $actorPermission,
        int|array|null $authorizationUserIds = null,
        ?CarbonInterface $presenceEffectiveAt = null,
        array $additionalPresenceShiftIds = [],
        ?Collection $lockedPresenceShifts = null,
    ): Collection {
        $userIds = [(int) $actor->id];
        foreach ((array) $authorizationUserIds as $authorizationUserId) {
            if (is_numeric($authorizationUserId) && (int) $authorizationUserId > 0) {
                $userIds[] = (int) $authorizationUserId;
            }
        }
        $userIds = array_values(array_unique($userIds));
        sort($userIds, SORT_NUMERIC);
        $presenceEffectiveAt ??= now();
        $lockedPresenceShifts ??= $this->transportWitnesses->lockPresenceShiftsAtSite(
            $userIds,
            $siteId,
            $presenceEffectiveAt,
            $additionalPresenceShiftIds,
        );
        $lockedUsers = $this->medicationGovernance->lockControlledWitnessUsers($userIds);
        $profiles = $this->medicationGovernance->lockCurrentStaffProfiles(
            $lockedUsers,
            $userIds,
        );
        $lockedUsers->each(function (User $user) use ($profiles): void {
            $user->setRelation('hrEmployeeProfile', $profiles->get((int) $user->id));
        });
        $this->medicationGovernance->lockCurrentMedicationSite($siteId);
        $lockedUsers->each(function (User $user) use ($lockedPresenceShifts, $presenceEffectiveAt): void {
            $user->setRelation('controlledMedicationPresenceShifts', $lockedPresenceShifts);
            $user->setRelation('controlledMedicationPresenceEffectiveAt', $presenceEffectiveAt);
        });

        /** @var User|null $lockedActor */
        $lockedActor = $lockedUsers->get((int) $actor->id);
        abort_unless($lockedActor?->canDo($actorPermission), 403);
        $actorHasGlobalFleetScope = $lockedActor->canDo('fleet.manage');
        abort_unless(collect($userIds)->every(function (int $userId) use (
            $actor,
            $actorHasGlobalFleetScope,
            $profiles,
            $siteId,
        ): bool {
            if ($userId === (int) $actor->id && $actorHasGlobalFleetScope) {
                return true;
            }

            $profile = $profiles->get($userId);

            return (int) $profile?->primary_site_id === $siteId
                || collect($profile?->secondary_site_ids ?? [])->contains(
                    fn (mixed $candidate): bool => (int) $candidate === $siteId,
                );
        }), 404);

        return $lockedUsers;
    }

    /** @param array<string, mixed> $data */
    private function validateFleetWitnessInput(array $data): void
    {
        if ($data['queued_offline'] ?? false) {
            throw ValidationException::withMessages([
                'witness_credential' => 'Authenticated second-checker acceptance must be completed online.',
            ]);
        }
        if (empty($data['witnessed_by_user_id'])) {
            throw ValidationException::withMessages([
                'witnessed_by_user_id' => 'A second authorised checker is required.',
            ]);
        }
        if (blank($data['witness_credential'] ?? null)) {
            throw ValidationException::withMessages([
                'witness_credential' => 'The witness must enter their password or PIN.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function packingAttestation(
        User $actor,
        Client $resident,
        ClientMedication $medication,
        array $payload,
        string $attestationState,
        Collection $lockedUsers,
    ): ?array {
        if (! $medication->requiresWitness()) {
            return null;
        }

        if ($attestationState === 'unavailable') {
            if (! empty($payload['witnessed_by_user_id']) || filled($payload['witness_credential'] ?? null)) {
                throw ValidationException::withMessages([
                    'witnessed_by_user_id' => 'Do not name a second checker when recording that no checker was available.',
                ]);
            }

            return null;
        }

        if ($payload['queued_offline'] ?? false) {
            throw ValidationException::withMessages([
                'witness_credential' => 'Authenticated second-checker '.($attestationState === 'refused' ? 'refusal' : 'acceptance').' must be completed online.',
            ]);
        }
        if (empty($payload['witnessed_by_user_id'])) {
            throw ValidationException::withMessages([
                'witnessed_by_user_id' => $attestationState === 'refused'
                    ? 'Select the second checker who declined to attest.'
                    : 'Select the second checker who is present for packing.',
            ]);
        }

        return $this->transportWitnesses->authenticate(
            $actor,
            (int) $resident->site_id,
            (int) $payload['witnessed_by_user_id'],
            (string) ($payload['witness_credential'] ?? ''),
            $this->lockedPresenceEffectiveAt($actor),
            witnessErrorKey: 'witnessed_by_user_id',
            lockedUsers: $lockedUsers,
            lockedPresenceShifts: $this->lockedPresenceShifts($actor),
        );
    }

    private function lockedPresenceEffectiveAt(User $actor): CarbonInterface
    {
        $effectiveAt = $actor->relationLoaded('controlledMedicationPresenceEffectiveAt')
            ? $actor->getRelation('controlledMedicationPresenceEffectiveAt')
            : null;
        if (! $effectiveAt instanceof CarbonInterface) {
            throw new \LogicException('Fleet medication presence time was not frozen before authorization Users.');
        }

        return $effectiveAt;
    }

    /** @return Collection<int, Shift> */
    private function lockedPresenceShifts(User $actor): Collection
    {
        $shifts = $actor->relationLoaded('controlledMedicationPresenceShifts')
            ? $actor->getRelation('controlledMedicationPresenceShifts')
            : null;
        if (! $shifts instanceof Collection) {
            throw new \LogicException('Fleet medication presence Shifts were not frozen before authorization Users.');
        }

        return $shifts;
    }

    /**
     * @return array{transport: FleetResidentTransport, replayed: bool}
     */
    public function create(User $actor, array $data): array
    {
        if (! empty($data['medications'])) {
            $this->assertCanManageMedicationTransit($actor);
        }

        $requestUuid = $this->requestUuid($data);
        $requestHash = $this->requestHash('created', [
            'asset_id' => $data['asset_id'] ?? null,
            'shift_id' => $data['shift_id'] ?? null,
            'booking_id' => $data['booking_id'] ?? null,
            'client_id' => $data['client_id'] ?? null,
            'resident_name' => trim((string) ($data['resident_name'] ?? '')),
            'transport_type' => $data['transport_type'] ?? null,
            'pickup_location' => $data['pickup_location'] ?? null,
            'dropoff_location' => $data['dropoff_location'] ?? null,
            'departed_at' => $data['departed_at'] ?? null,
            'passengers_count' => $data['passengers_count'] ?? 1,
            'supervisor_name' => trim((string) ($data['supervisor_name'] ?? '')),
            'notes_hash' => hash('sha256', (string) ($data['notes'] ?? '')),
            'medications' => collect($data['medications'] ?? [])->map(fn (array $medication): array => [
                'medication_id' => $medication['medication_id'] ?? null,
                'medication_order_version_id' => $medication['medication_order_version_id'] ?? null,
                'attestation_state' => $medication['attestation_state'] ?? null,
                'witnessed_by_user_id' => $medication['witnessed_by_user_id'] ?? null,
                'attestation_reason_hash' => hash('sha256', (string) ($medication['attestation_reason'] ?? '')),
                'scan_code_hash' => hash('sha256', (string) ($medication['scan_code'] ?? '')),
            ])->values()->all(),
        ]);

        try {
            return DB::transaction(function () use ($actor, $data, $requestUuid, $requestHash): array {
                [$presenceEffectiveAt, $lockedPresenceShifts, $lockedPresenceResident] = $this->prelockCreateMedicationPresenceShifts(
                    $actor,
                    $data,
                );
                [$resident, $shift] = $this->resolveCreateResidentAndShift(
                    $actor,
                    $data,
                    $lockedPresenceShifts,
                    $lockedPresenceResident,
                );
                $site = Site::query()
                    ->active()
                    ->notArchived()
                    ->whereKey($resident->site_id)
                    ->when(
                        empty($data['medications']),
                        fn (Builder $query): Builder => $query->lockForUpdate(),
                    )
                    ->firstOrFail();
                $asset = $this->scope->vehicleForSite(
                    (int) $data['asset_id'],
                    (int) $site->id,
                    (int) $resident->id,
                    true,
                );
                abort_unless($asset->status === 'active', 404);

                $booking = $this->resolveBooking($actor, $data, $asset->id, $site->id);
                $this->assertShiftWindowAndDriver($actor, $shift, $data);

                $medications = $this->resolveMedicationPayloads(
                    $resident,
                    $data['medications'] ?? [],
                    true,
                );
                $medicationPayloads = array_values($data['medications'] ?? []);
                $lockedUsers = null;
                if ($medications !== []) {
                    $witnessIds = collect($medicationPayloads)
                        ->pluck('witnessed_by_user_id')
                        ->filter(fn ($userId): bool => is_numeric($userId) && (int) $userId > 0)
                        ->map(fn ($userId): int => (int) $userId)
                        ->all();
                    $lockedUsers = $this->lockCurrentFleetMedicationUsers(
                        $actor,
                        (int) $resident->site_id,
                        'fleet.medication.manage',
                        $witnessIds,
                        $presenceEffectiveAt,
                        $shift ? [(int) $shift->id] : [],
                        $lockedPresenceShifts,
                    );
                    /** @var User $actor */
                    $actor = $lockedUsers->get((int) $actor->id);
                }

                if ($event = $this->replayedEvent($actor, $requestUuid, 'created', $requestHash)) {
                    foreach ($medications as $index => $prepared) {
                        $this->packingAttestation(
                            $actor,
                            $resident,
                            $prepared['medication'],
                            $medicationPayloads[$index],
                            (string) ($medicationPayloads[$index]['attestation_state'] ?? 'accepted'),
                            $lockedUsers,
                        );
                    }

                    abort_unless((int) $event->client_id === (int) $resident->id, 409);

                    return [
                        'transport' => $this->scope->transportFor($actor, (int) $event->transport_id),
                        'replayed' => true,
                    ];
                }

                $snapshot = $shift
                    ? $this->snapshots->snapshotForShift($shift, $actor)
                    : $this->snapshots->snapshotForClient(
                        $resident,
                        $actor,
                        $data['pickup_location'] ?? null,
                    );

                $transport = FleetResidentTransport::query()->create([
                    'journey_uuid' => (string) Str::uuid(),
                    'asset_id' => $asset->id,
                    'booking_id' => $booking?->id,
                    'shift_id' => $shift?->id,
                    'site_id' => $site->id,
                    'service_context_id' => $shift?->service_context_id ?: $resident->service_context_id,
                    'driver_user_id' => $actor->id,
                    'resident_id' => $resident->id,
                    'resident_name' => $this->residentName($resident),
                    'transport_type' => $data['transport_type'],
                    'pickup_location' => ($data['pickup_location'] ?? null) ?: $shift?->location,
                    'dropoff_location' => $data['dropoff_location'] ?? null,
                    'departed_at' => $data['departed_at'],
                    'passengers_count' => $data['passengers_count'] ?? 1,
                    'supervisor_name' => $data['supervisor_name'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'status' => 'in_progress',
                    'version' => 1,
                    'site_name_snapshot' => $snapshot['site_name'] ?? null,
                    'shift_location_snapshot' => $snapshot['location'] ?? null,
                    'service_context_name_snapshot' => $snapshot['service_context_name'] ?? null,
                    'driver_name_snapshot' => $actor->name,
                ]);

                $this->recordEvent(
                    $transport,
                    'created',
                    $actor,
                    $requestUuid,
                    ['request_hash' => $requestHash],
                );

                foreach ($medications as $index => $prepared) {
                    $this->createMedicationCustody(
                        $transport,
                        $resident,
                        $actor,
                        $prepared,
                        $medicationPayloads[$index],
                        (string) Str::uuid(),
                        $requestUuid,
                        lockedUsers: $lockedUsers,
                    );
                }

                AuditLogger::logOrFail('fleet.transport.create', $transport, [
                    'actor_id' => $actor->id,
                    'site_id' => $site->id,
                    'client_id' => $resident->id,
                    'shift_id' => $shift?->id,
                    'asset_id' => $asset->id,
                    'booking_id' => $booking?->id,
                    'journey_uuid' => $transport->journey_uuid,
                    'request_uuid' => $requestUuid,
                    'medications_packed' => count($medications),
                ]);

                return ['transport' => $transport, 'replayed' => false];
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            return DB::transaction(function () use ($actor, $data, $requestUuid, $requestHash, $exception): array {
                [$presenceEffectiveAt, $lockedPresenceShifts, $lockedPresenceResident] = $this->prelockCreateMedicationPresenceShifts(
                    $actor,
                    $data,
                );
                [$resident, $shift] = $this->resolveCreateResidentAndShift(
                    $actor,
                    $data,
                    $lockedPresenceShifts,
                    $lockedPresenceResident,
                );
                $medications = $this->resolveMedicationPayloads(
                    $resident,
                    $data['medications'] ?? [],
                    true,
                );
                $medicationPayloads = array_values($data['medications'] ?? []);
                $lockedUsers = null;
                if ($medications !== []) {
                    $witnessIds = collect($medicationPayloads)
                        ->pluck('witnessed_by_user_id')
                        ->filter(fn ($userId): bool => is_numeric($userId) && (int) $userId > 0)
                        ->map(fn ($userId): int => (int) $userId)
                        ->all();
                    $lockedUsers = $this->lockCurrentFleetMedicationUsers(
                        $actor,
                        (int) $resident->site_id,
                        'fleet.medication.manage',
                        $witnessIds,
                        $presenceEffectiveAt,
                        $shift ? [(int) $shift->id] : [],
                        $lockedPresenceShifts,
                    );
                    /** @var User $actor */
                    $actor = $lockedUsers->get((int) $actor->id);
                    foreach ($medications as $index => $prepared) {
                        $this->packingAttestation(
                            $actor,
                            $resident,
                            $prepared['medication'],
                            $medicationPayloads[$index],
                            (string) ($medicationPayloads[$index]['attestation_state'] ?? 'accepted'),
                            $lockedUsers,
                        );
                    }
                }

                $event = $this->replayedEvent($actor, $requestUuid, 'created', $requestHash);
                if (! $event) {
                    throw $exception;
                }

                abort_unless((int) $event->client_id === (int) $resident->id, 409);

                return [
                    'transport' => $this->scope->transportFor($actor, (int) $event->transport_id),
                    'replayed' => true,
                ];
            });
        }
    }

    /** @return array{transport: FleetResidentTransport, replayed: bool} */
    public function complete(User $actor, int $transportId, array $data): array
    {
        $requestUuid = $this->requestUuid($data);
        $requestHash = $this->requestHash('completed', [
            'transport_id' => $transportId,
            'arrived_at' => $data['arrived_at'] ?? null,
            'notes_hash' => hash('sha256', (string) ($data['notes'] ?? '')),
        ]);

        return DB::transaction(function () use ($actor, $transportId, $data, $requestUuid, $requestHash): array {
            $transport = $this->scope->mutableTransportFor($actor, $transportId, true);

            if ($event = $this->replayedEvent($actor, $requestUuid, 'completed', $requestHash)) {
                abort_unless((int) $event->transport_id === $transport->id, 409);

                return ['transport' => $transport->fresh(), 'replayed' => true];
            }

            if ($transport->status !== 'in_progress') {
                throw new ConflictHttpException('This transport has already been completed or is no longer active.');
            }

            $allLogs = FleetMedicationTransitLog::query()->where('transport_id', $transport->id)->count();
            $scopedLogsQuery = FleetMedicationTransitLog::query()->where('transport_id', $transport->id);
            $this->scope->applyMedicationTransitScope($scopedLogsQuery, $actor);
            abort_unless($allLogs === (clone $scopedLogsQuery)->count(), 409, 'Medication custody records do not match this journey.');

            $unresolved = (clone $scopedLogsQuery)
                ->whereNull('administered_at')
                ->whereNull('returned_to_house_at')
                ->lockForUpdate()
                ->count();
            if ($unresolved > 0) {
                throw ValidationException::withMessages([
                    'transport' => "Cannot complete transport: {$unresolved} medication(s) still unresolved. All medications must be administered or returned to house first.",
                ]);
            }

            $ungovernedPackingAttestations = $this->governedPackingAttestationGaps(clone $scopedLogsQuery)
                ->lockForUpdate()
                ->count();
            if ($ungovernedPackingAttestations > 0) {
                throw ValidationException::withMessages([
                    'transport' => "Cannot complete transport: {$ungovernedPackingAttestations} medication packing attestation(s) need an authenticated witness or correction.",
                ]);
            }

            $transport->forceFill([
                'status' => 'completed',
                'arrived_at' => $data['arrived_at'] ?? now(),
                'notes' => $data['notes'] ?? $transport->notes,
                'version' => ((int) $transport->version) + 1,
            ])->save();

            $this->recordEvent(
                $transport,
                'completed',
                $actor,
                $requestUuid,
                ['request_hash' => $requestHash],
            );
            AuditLogger::logOrFail('fleet.transport.complete', $transport, [
                'actor_id' => $actor->id,
                'site_id' => $transport->site_id,
                'client_id' => $transport->resident_id,
                'journey_uuid' => $transport->journey_uuid,
                'request_uuid' => $requestUuid,
            ]);

            return ['transport' => $transport, 'replayed' => false];
        }, 3);
    }

    /** @return array{transport: FleetResidentTransport, replayed: bool} */
    public function savePreCheck(User $actor, int $transportId, array $data): array
    {
        $requestUuid = $this->requestUuid($data);
        $requestHash = $this->requestHash('pre_check_completed', [
            'transport_id' => $transportId,
            'checks' => $data['checks'],
        ]);

        return DB::transaction(function () use ($actor, $transportId, $data, $requestUuid, $requestHash): array {
            $transport = $this->scope->mutableTransportFor($actor, $transportId, true);
            if ($event = $this->replayedEvent($actor, $requestUuid, 'pre_check_completed', $requestHash)) {
                abort_unless((int) $event->transport_id === $transport->id, 409);

                return ['transport' => $transport, 'replayed' => true];
            }

            abort_unless($transport->status === 'in_progress', 409, 'This journey is no longer active.');
            abort_unless(Schema::hasTable('fleet_transport_pre_checks'), 409, 'Pre-transport checks are unavailable.');
            abort_if(
                DB::table('fleet_transport_pre_checks')->where('transport_id', $transport->id)->lockForUpdate()->exists(),
                409,
                'The pre-transport safety check has already been completed.',
            );

            DB::table('fleet_transport_pre_checks')->insert([
                'transport_id' => $transport->id,
                'checks' => json_encode($data['checks'], JSON_THROW_ON_ERROR),
                'completed_by_user_id' => $actor->id,
                'completed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $transport->forceFill(['version' => ((int) $transport->version) + 1])->save();

            $this->recordEvent(
                $transport,
                'pre_check_completed',
                $actor,
                $requestUuid,
                ['request_hash' => $requestHash, 'checks_hash' => hash('sha256', json_encode($data['checks'], JSON_THROW_ON_ERROR))],
            );
            AuditLogger::logOrFail('fleet.transport.pre_check', $transport, [
                'actor_id' => $actor->id,
                'site_id' => $transport->site_id,
                'client_id' => $transport->resident_id,
                'journey_uuid' => $transport->journey_uuid,
                'request_uuid' => $requestUuid,
                'checks_hash' => hash('sha256', json_encode($data['checks'], JSON_THROW_ON_ERROR)),
            ]);

            return ['transport' => $transport, 'replayed' => false];
        }, 3);
    }

    /** @return array{log: ?FleetMedicationTransitLog, replayed: bool, attestation_state: string} */
    public function packMedication(User $actor, int $transportId, array $data): array
    {
        $this->assertCanManageMedicationTransit($actor);

        $attestationState = (string) ($data['attestation_state'] ?? 'accepted');
        if (! in_array($attestationState, ['accepted', 'refused', 'unavailable'], true)) {
            throw ValidationException::withMessages([
                'attestation_state' => 'Select whether the second checker accepted, declined, or was unavailable.',
            ]);
        }
        $action = match ($attestationState) {
            'accepted' => 'medication_packed',
            'refused' => 'medication_packing_refused',
            'unavailable' => 'medication_packing_unavailable',
        };
        $requestUuid = $this->requestUuid($data);
        $requestHash = $this->requestHash($action, [
            'transport_id' => $transportId,
            'client_id' => $data['client_id'] ?? null,
            'medication_id' => $data['medication_id'] ?? null,
            'medication_order_version_id' => $data['medication_order_version_id'] ?? null,
            'attestation_state' => $attestationState,
            'witnessed_by_user_id' => $data['witnessed_by_user_id'] ?? null,
            'attestation_reason_hash' => hash('sha256', (string) ($data['attestation_reason'] ?? '')),
            'notes_hash' => hash('sha256', (string) ($data['notes'] ?? '')),
            'scan_code_hash' => hash('sha256', (string) ($data['scan_code'] ?? '')),
            ...$this->normalizedOfflineProvenanceForFingerprint($data),
        ]);

        return DB::transaction(function () use ($actor, $transportId, $data, $attestationState, $action, $requestUuid, $requestHash): array {
            $transport = $this->scope->transportFor($actor, $transportId, true);
            abort_unless((int) ($data['client_id'] ?? 0) === (int) $transport->resident_id, 404);
            $resident = $this->scope->clientFor($actor, (int) $transport->resident_id, true);
            $prepared = $this->resolveMedicationPayload(
                $resident,
                $data,
                true,
                $attestationState === 'accepted',
            );
            /** @var ClientMedication $medication */
            $medication = $prepared['medication'];
            $lockedUsers = $this->lockCurrentFleetMedicationUsers(
                $actor,
                (int) $resident->site_id,
                'fleet.medication.manage',
                is_numeric($data['witnessed_by_user_id'] ?? null)
                    ? (int) $data['witnessed_by_user_id']
                    : null,
            );
            /** @var User $actor */
            $actor = $lockedUsers->get((int) $actor->id);

            if ($event = $this->replayedEvent($actor, $requestUuid, $action, $requestHash)) {
                abort_unless((int) $event->transport_id === $transport->id, 409);
                if ($attestationState !== 'accepted') {
                    abort_unless($medication->requiresWitness(), 409, 'This medication does not require a packing attestation.');
                }
                $this->packingAttestation(
                    $actor,
                    $resident,
                    $medication,
                    $data,
                    $attestationState,
                    $lockedUsers,
                );

                return [
                    'log' => $event->medication_transit_log_id
                        ? $this->scope->medicationTransitLogFor($actor, (int) $event->medication_transit_log_id, true)
                        : null,
                    'replayed' => true,
                    'attestation_state' => $attestationState,
                ];
            }

            abort_unless($transport->status === 'in_progress', 409, 'Medication can only be packed for an active journey.');

            if ($attestationState !== 'accepted') {
                $this->recordPackingNonAcceptance(
                    $transport,
                    $resident,
                    $actor,
                    $prepared,
                    $data,
                    $attestationState,
                    $action,
                    $requestUuid,
                    $requestHash,
                    $lockedUsers,
                );

                return [
                    'log' => null,
                    'replayed' => false,
                    'attestation_state' => $attestationState,
                ];
            }

            $duplicate = FleetMedicationTransitLog::query()
                ->where('transport_id', $transport->id)
                ->where('medication_id', $prepared['medication']->id)
                ->exists();
            abort_if($duplicate, 409, 'This medication is already packed for the journey.');

            $log = $this->createMedicationCustody(
                $transport,
                $resident,
                $actor,
                $prepared,
                $data,
                $requestUuid,
                null,
                $requestHash,
                $lockedUsers,
            );

            return ['log' => $log, 'replayed' => false, 'attestation_state' => 'accepted'];
        }, 3);
    }

    /** @return array{log: FleetMedicationTransitLog, replayed: bool} */
    public function administerMedication(User $actor, int $logId, array $data): array
    {
        $this->assertCanAdministerMedication($actor);

        return $this->resolveMedicationCustody(
            $actor,
            $logId,
            $data,
            'medication_administered',
        );
    }

    /** @return array{log: FleetMedicationTransitLog, replayed: bool} */
    public function returnMedication(User $actor, int $logId, array $data): array
    {
        $this->assertCanManageMedicationTransit($actor);

        return $this->resolveMedicationCustody(
            $actor,
            $logId,
            $data,
            'medication_returned',
        );
    }

    /** @return array{log: FleetMedicationTransitLog, replayed: bool} */
    public function correctPackingAttestation(User $actor, int $logId, array $data): array
    {
        $this->assertCanManageMedicationTransit($actor);

        $requestUuid = $this->requestUuid($data);
        $requestHash = $this->requestHash('medication_packing_attestation_corrected', [
            'log_id' => $logId,
            'witnessed_by_user_id' => $data['witnessed_by_user_id'] ?? null,
            'correction_reason_hash' => hash('sha256', (string) ($data['correction_reason'] ?? '')),
        ]);

        return DB::transaction(function () use ($actor, $logId, $data, $requestUuid, $requestHash): array {
            $transportId = FleetMedicationTransitLog::query()->whereKey($logId)->value('transport_id');
            abort_unless($transportId, 404);
            $transport = $this->scope->transportFor($actor, (int) $transportId, true);
            $log = $this->scope->medicationTransitLogFor($actor, $logId, true);

            $resident = $this->scope->clientFor($actor, (int) $transport->resident_id, true);
            $medication = ClientMedication::query()
                ->whereKey($log->medication_id)
                ->where('client_id', $resident->id)
                ->lockForUpdate()
                ->firstOrFail();
            $medication->setRelation('client', $resident);
            abort_unless(
                (int) $log->client_id === (int) $resident->id
                    && (int) $log->site_id === (int) $resident->site_id
                    && (int) $transport->site_id === (int) $resident->site_id,
                404,
            );
            abort_unless(
                $log->witness_required || $log->is_controlled_drug,
                409,
                'This packing record does not require a second checker.',
            );
            $this->validateFleetWitnessInput($data);

            $lockedUsers = $this->lockCurrentFleetMedicationUsers(
                $actor,
                (int) $resident->site_id,
                'fleet.medication.manage',
                is_numeric($data['witnessed_by_user_id'] ?? null)
                    ? (int) $data['witnessed_by_user_id']
                    : null,
            );
            /** @var User $actor */
            $actor = $lockedUsers->get((int) $actor->id);

            $attestation = $this->transportWitnesses->authenticate(
                $actor,
                (int) $resident->site_id,
                (int) ($data['witnessed_by_user_id'] ?? 0),
                (string) ($data['witness_credential'] ?? ''),
                $this->lockedPresenceEffectiveAt($actor),
                witnessErrorKey: 'witnessed_by_user_id',
                lockedUsers: $lockedUsers,
                lockedPresenceShifts: $this->lockedPresenceShifts($actor),
            );
            if ($event = $this->replayedEvent(
                $actor,
                $requestUuid,
                'medication_packing_attestation_corrected',
                $requestHash,
            )) {
                abort_unless(
                    (int) $event->transport_id === $transport->id
                        && (int) $event->medication_transit_log_id === $log->id,
                    409,
                );

                return ['log' => $log->fresh(), 'replayed' => true];
            }

            if (blank($data['correction_reason'] ?? null)) {
                throw ValidationException::withMessages([
                    'correction_reason' => 'Explain why the packing attestation is being corrected.',
                ]);
            }

            /** @var User $witness */
            $witness = $attestation['witness'];
            if ((int) $log->packed_witnessed_by_user_id === (int) $witness->id) {
                throw ValidationException::withMessages([
                    'witnessed_by_user_id' => 'Select the correct second checker rather than the checker already recorded.',
                ]);
            }

            $subjectDigest = $this->medicationCustodyDigest($transport, $log, $medication);
            $supersededEvent = $log->packing_attestation_event_id
                ? FleetResidentTransportEvent::query()
                    ->whereKey($log->packing_attestation_event_id)
                    ->where('transport_id', $transport->id)
                    ->where('medication_transit_log_id', $log->id)
                    ->lockForUpdate()
                    ->firstOrFail()
                : null;
            $previousDigest = data_get($supersededEvent?->context, 'attestation.subject_digest');
            abort_unless(
                $previousDigest === null || hash_equals((string) $previousDigest, $subjectDigest),
                409,
                'The packing attestation no longer matches this medication custody record.',
            );

            $transport->forceFill(['version' => ((int) $transport->version) + 1])->save();
            $event = $this->recordEvent(
                $transport,
                'medication_packing_attestation_corrected',
                $actor,
                $requestUuid,
                [
                    'request_hash' => $requestHash,
                    'attestation' => $this->attestationContext($attestation, $subjectDigest, 'corrected'),
                    'supersedes_event_id' => $supersededEvent?->id,
                    'correction_reason' => trim((string) $data['correction_reason']),
                ],
                $log,
                $medication,
                $log->medicationOrderVersion,
                null,
                $witness->id,
            );
            $log->forceFill([
                'packed_witness_name' => $witness->name,
                'packed_witnessed_by_user_id' => $witness->id,
                'packed_witnessed_at' => $attestation['witnessed_at'],
                'packing_witness_method' => $attestation['method'],
                'packing_attestation_event_id' => $event->id,
            ])->save();

            AuditLogger::logOrFail('fleet.medication.packing_attestation.correct', $log, [
                'actor_id' => $actor->id,
                'site_id' => $transport->site_id,
                'client_id' => $resident->id,
                'transport_id' => $transport->id,
                'journey_uuid' => $transport->journey_uuid,
                'medication_id' => $medication->id,
                'request_uuid' => $requestUuid,
                'packing_attestation_event_id' => $event->id,
                'supersedes_event_id' => $supersededEvent?->id,
                'witness_user_id' => $witness->id,
                'correction_reason' => trim((string) $data['correction_reason']),
            ]);

            return ['log' => $log, 'replayed' => false];
        }, 3);
    }

    /**
     * @return array{log: FleetMedicationTransitLog, replayed: bool}
     */
    private function resolveMedicationCustody(User $actor, int $logId, array $data, string $action): array
    {
        if ($action === 'medication_administered') {
            $data['quantity_administered'] = $this->requiredAdministrationQuantity($data);
        }

        $requestUuid = $this->requestUuid($data);
        $requestHash = $this->requestHash($action, [
            'log_id' => $logId,
            'witnessed_by_user_id' => $data['witnessed_by_user_id'] ?? null,
            'quantity_administered' => $data['quantity_administered'] ?? null,
            'notes_hash' => hash('sha256', (string) ($data['notes'] ?? '')),
            'scan_code_hash' => hash('sha256', (string) ($data['scan_code'] ?? '')),
            ...$this->normalizedOfflineProvenanceForFingerprint($data),
        ]);

        return DB::transaction(function () use ($actor, $logId, $data, $action, $requestUuid, $requestHash): array {
            $transportId = FleetMedicationTransitLog::query()->whereKey($logId)->value('transport_id');
            abort_unless($transportId, 404);
            $transport = $this->scope->transportFor($actor, (int) $transportId, true);
            $log = $this->scope->medicationTransitLogFor($actor, $logId, true);

            $resident = $this->scope->clientFor($actor, (int) $transport->resident_id, true);
            $medication = ClientMedication::query()
                ->whereKey($log->medication_id)
                ->where('client_id', $resident->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless((int) $log->site_id === (int) $resident->site_id, 404);
            $actionAt = $this->medicationCustodyActionAt($transport, $data);
            $lockedPresenceShifts = null;
            $presenceShift = null;
            if ($action === 'medication_administered') {
                $presenceUserIds = [(int) $actor->id];
                if (is_numeric($data['witnessed_by_user_id'] ?? null) && (int) $data['witnessed_by_user_id'] > 0) {
                    $presenceUserIds[] = (int) $data['witnessed_by_user_id'];
                }
                $lockedPresenceShifts = $this->transportWitnesses->lockPresenceShiftsAtSite(
                    $presenceUserIds,
                    (int) $resident->site_id,
                    $actionAt,
                    $transport->shift_id ? [(int) $transport->shift_id] : [],
                );
                $presenceShift = $this->historicalMedicationAdministrationShiftFromLockedSet(
                    $transport,
                    $resident,
                    $actor,
                    $lockedPresenceShifts,
                );
            }
            $requiresWitness = $action === 'medication_administered'
                && (
                    $log->witness_required
                    || $medication->requiresWitness()
                    || ($this->medicationRules->requirementsFor($medication, true)['requires_countersign'] ?? false)
                );
            $lockedUsers = $this->lockCurrentFleetMedicationUsers(
                $actor,
                (int) $resident->site_id,
                $action === 'medication_administered'
                    ? 'medications.administer.record'
                    : 'fleet.medication.manage',
                $requiresWitness && is_numeric($data['witnessed_by_user_id'] ?? null)
                    ? (int) $data['witnessed_by_user_id']
                    : null,
                $actionAt,
                $transport->shift_id ? [(int) $transport->shift_id] : [],
                $lockedPresenceShifts,
            );
            /** @var User $actor */
            $actor = $lockedUsers->get((int) $actor->id);
            abort_if(
                $action === 'medication_administered'
                && ($log->is_controlled_drug || $medication->controlled_drug)
                && ! $actor->canDo('medications.controlled.record'),
                403,
            );
            if ($action === 'medication_administered') {
                $this->assertHistoricalMedicationAdministrationPresence(
                    $transport,
                    $resident,
                    $actor,
                    $actionAt,
                    $presenceShift,
                );
                $competency = $this->administratorCompetency->evaluate(
                    $actor,
                    (int) $resident->site_id,
                    $actionAt,
                    true,
                );
                if (! $competency['allowed']) {
                    throw ValidationException::withMessages([
                        'medication' => 'You cannot administer this medication — '.$competency['message'],
                    ]);
                }
            }

            $replayedEvent = $this->replayedEvent($actor, $requestUuid, $action, $requestHash);
            if ($replayedEvent) {
                abort_unless(
                    (int) $replayedEvent->transport_id === $transport->id
                    && (int) $replayedEvent->medication_transit_log_id === $log->id,
                    409,
                );
            }

            if (! $replayedEvent) {
                abort_unless($transport->status === 'in_progress', 409, 'Medication custody can only be resolved during an active journey.');
            }
            if (! $replayedEvent && ($log->administered_at || $log->returned_to_house_at)) {
                throw new ConflictHttpException('This medication custody record has already been resolved.');
            }

            if ($replayedEvent) {
                if ($requiresWitness) {
                    $this->validateFleetWitnessInput($data);
                    $this->transportWitnesses->authenticate(
                        $actor,
                        (int) $resident->site_id,
                        (int) $data['witnessed_by_user_id'],
                        (string) $data['witness_credential'],
                        $actionAt,
                        witnessErrorKey: 'witnessed_by_user_id',
                        lockedUsers: $lockedUsers,
                        lockedPresenceShifts: $this->lockedPresenceShifts($actor),
                    );
                }

                return ['log' => $log->fresh(), 'replayed' => true];
            }

            if (
                $action === 'medication_administered'
                && $this->governedPackingAttestationGaps(
                    FleetMedicationTransitLog::query()->whereKey($log->id),
                )->exists()
            ) {
                throw ValidationException::withMessages([
                    'packing_attestation' => 'Correct the packing second-checker evidence before administering this medication.',
                ]);
            }
            $scanAudit = $this->verifyMedicationScan($resident, $medication, $data);
            $witness = null;
            $witnessAttestation = null;
            $administration = null;

            if ($action === 'medication_administered') {
                abort_unless((int) $log->medication_order_version === (int) $medication->version, 409, 'The medication order changed after packing. Return this medication to the house for reconciliation.');
                abort_unless($log->medication_order_version_id === null || $this->currentOrderVersion($medication)?->id === $log->medication_order_version_id, 409, 'The medication order changed after packing. Return this medication to the house for reconciliation.');
                abort_unless($medication->isAdministrable(), 409, 'This medication order is no longer authorised for administration.');
                if ($requiresWitness) {
                    $this->validateFleetWitnessInput($data);
                    $witnessAttestation = $this->transportWitnesses->authenticate(
                        $actor,
                        (int) $resident->site_id,
                        (int) ($data['witnessed_by_user_id'] ?? 0),
                        (string) ($data['witness_credential'] ?? ''),
                        $actionAt,
                        witnessErrorKey: 'witnessed_by_user_id',
                        lockedUsers: $lockedUsers,
                        lockedPresenceShifts: $this->lockedPresenceShifts($actor),
                    );
                    $witness = $witnessAttestation['witness'];
                }

                $emarResult = $this->emar->recordAdministration(
                    $resident,
                    $medication,
                    [
                        'status' => 'given',
                        'administered_at' => $actionAt->toIso8601String(),
                        'dose_given' => $medication->dosage ?: $medication->name,
                        'notes' => $data['notes'] ?? null,
                        'client_request_uuid' => $requestUuid,
                        'witnessed_by' => $witness?->id,
                        'witness_credential' => $data['witness_credential'] ?? null,
                        'quantity_administered' => $data['quantity_administered'],
                        'captured_offline_at' => $data['captured_offline_at'] ?? null,
                        'origin_device_id' => $data['origin_device_id'] ?? null,
                        'queued_offline' => (bool) ($data['queued_offline'] ?? false),
                    ],
                    $actor->id,
                    $transport->shift_id ? (int) $transport->shift_id : null,
                    $actor->canDo('medications.controlled.view'),
                    prelockedPresenceShifts: $this->lockedPresenceShifts($actor),
                    prelockedPresenceEffectiveAt: $this->lockedPresenceEffectiveAt($actor),
                );
                if (! ($emarResult['success'] ?? false)) {
                    if (($emarResult['status'] ?? null) === 403) {
                        abort(403, (string) ($emarResult['error'] ?? 'Medication administration is not authorised.'));
                    }

                    throw ValidationException::withMessages([
                        (string) ($emarResult['error_field'] ?? 'medication') => (string) ($emarResult['error'] ?? 'Medication administration could not be recorded.'),
                    ]);
                }

                $administration = $emarResult['administration'];
                abort_unless(
                    (int) $administration->client_id === $resident->id
                        && (int) $administration->client_medication_id === $medication->id
                        && (int) $administration->administered_by === $actor->id,
                    409,
                    'The eMAR reconciliation did not match this journey.',
                );
                $log->forceFill([
                    'administered_at' => $administration->administered_at,
                    'administered_by_user_id' => $actor->id,
                    'witnessed_by_user_id' => $witness?->id,
                    'medication_administration_id' => $administration->id,
                    'notes' => $data['notes'] ?? $log->notes,
                ])->save();
            } else {
                $log->forceFill([
                    'returned_to_house_at' => $actionAt,
                    'returned_by_user_id' => $actor->id,
                    'notes' => $data['notes'] ?? $log->notes,
                ])->save();
            }

            $transport->forceFill(['version' => ((int) $transport->version) + 1])->save();
            $this->recordEvent(
                $transport,
                $action,
                $actor,
                $requestUuid,
                [
                    'request_hash' => $requestHash,
                    ...($action === 'medication_administered' ? [
                        'quantity_administered' => $data['quantity_administered'],
                    ] : []),
                    'scan_source' => $scanAudit['scan_source'],
                    'scan_match_source' => $scanAudit['scan_match_source'],
                    'entered_code_suffix' => $scanAudit['scan_code_suffix'],
                    ...($witnessAttestation ? [
                        'attestation' => $this->attestationContext(
                            $witnessAttestation,
                            $this->medicationCustodyDigest($transport, $log, $medication),
                            'accepted',
                        ),
                    ] : []),
                    ...$this->offlineProvenance($data),
                ],
                $log,
                $medication,
                $log->medicationOrderVersion,
                $administration?->id,
                $witness?->id,
            );
            AuditLogger::logOrFail('fleet.medication.'.($action === 'medication_administered' ? 'administer' : 'return'), $log, [
                'actor_id' => $actor->id,
                'site_id' => $transport->site_id,
                'client_id' => $resident->id,
                'transport_id' => $transport->id,
                'journey_uuid' => $transport->journey_uuid,
                'medication_id' => $medication->id,
                'medication_order_version' => $log->medication_order_version,
                'medication_order_version_id' => $log->medication_order_version_id,
                'medication_administration_id' => $administration?->id,
                'request_uuid' => $requestUuid,
                ...($action === 'medication_administered' ? [
                    'quantity_administered' => $data['quantity_administered'],
                ] : []),
                'scan_source' => $scanAudit['scan_source'],
                'scan_match_source' => $scanAudit['scan_match_source'],
                'entered_code_suffix' => $scanAudit['scan_code_suffix'],
                ...$this->offlineProvenance($data),
            ]);
            $this->incidents->resolveTransitException(
                $log,
                $action === 'medication_administered'
                    ? 'Medication administered during transit.'
                    : 'Medication returned from transit.',
                $actor->id,
            );

            return ['log' => $log, 'replayed' => false];
        }, 3);
    }

    /**
     * Prove the actor was the canonical journey worker at the clinical capture
     * time. Current RBAC/employment/Site authority is checked separately at
     * server-now before this historical assignment check.
     */
    private function assertHistoricalMedicationAdministrationPresence(
        FleetResidentTransport $transport,
        Client $resident,
        User $actor,
        CarbonInterface $actionAt,
        ?Shift $shift,
    ): void {
        abort_unless(
            (int) $transport->driver_user_id === (int) $actor->id
                && (int) $transport->resident_id === (int) $resident->id
                && (int) $transport->site_id === (int) $resident->site_id,
            403,
            'The medication capture is not assigned to the current journey worker.',
        );

        if ($transport->shift_id) {
            abort_unless($shift, 403, 'The medication capture is not assigned to the journey shift.');

            // Once a Shift has actually started, its actual interval is the
            // authority. Scheduled bounds are the explicit fallback for legacy
            // transports whose Shift did not record actual timestamps.
            $actualStartsAt = $this->storageInstant($shift, 'actual_starts_at');
            $startsAt = $actualStartsAt ?? $this->storageInstant($shift, 'starts_at');
            $endsAt = $actualStartsAt !== null
                ? $this->storageInstant($shift, 'actual_ends_at')
                : $this->storageInstant($shift, 'ends_at');
            abort_unless(
                $startsAt
                    && $actionAt->greaterThanOrEqualTo($startsAt)
                    && ($endsAt === null || $actionAt->lessThanOrEqualTo($endsAt)),
                403,
                'The medication capture falls outside the assigned journey shift.',
            );

            return;
        }

        // A shiftless journey has no separate rostering record. Its immutable
        // driver assignment and departure/arrival interval are the only native
        // historical presence evidence; missing bounds fail closed.
        $departedAt = $this->storageInstant($transport, 'departed_at');
        $arrivedAt = $this->storageInstant($transport, 'arrived_at');
        abort_unless(
            $departedAt
                && $actionAt->greaterThanOrEqualTo($departedAt)
                && ($arrivedAt === null || $actionAt->lessThanOrEqualTo($arrivedAt)),
            403,
            'The medication capture falls outside the journey interval.',
        );
    }

    /** Resolve the canonical journey Shift from the complete pre-User lock set. */
    private function historicalMedicationAdministrationShiftFromLockedSet(
        FleetResidentTransport $transport,
        Client $resident,
        User $actor,
        Collection $lockedPresenceShifts,
    ): ?Shift {
        if (! $transport->shift_id) {
            return null;
        }

        /** @var Shift|null $shift */
        $shift = $lockedPresenceShifts->get((int) $transport->shift_id);
        abort_unless(
            $shift instanceof Shift
                && (int) $shift->user_id === (int) $actor->id
                && (int) $shift->client_id === (int) $resident->id
                && (int) ($shift->site_id ?: $resident->site_id) === (int) $resident->site_id
                && $shift->status !== 'cancelled',
            404,
        );

        return $shift;
    }

    /**
     * @return array{0: Client, 1: ?Shift}
     */
    private function resolveCreateResidentAndShift(
        User $actor,
        array $data,
        ?Collection $lockedPresenceShifts = null,
        ?Client $lockedPresenceResident = null,
    ): array {
        $shift = null;
        if (! empty($data['shift_id'])) {
            $shift = $lockedPresenceShifts?->get((int) $data['shift_id']);
            if (! $shift instanceof Shift) {
                $shift = $this->scope->shiftFor($actor, (int) $data['shift_id'], true);
            }
            abort_unless(in_array(
                (int) ($shift->site_id ?: $shift->client?->site_id),
                $this->scope->accessibleSiteIds($actor),
                true,
            ), 404);
            $shift->load(['client:id,first_name,last_name,site_id,service_context_id', 'staff:id,name', 'serviceContext:id,name']);
            abort_unless($shift->client_id && $shift->client, 404);
            if (! empty($data['client_id'])) {
                abort_unless((int) $data['client_id'] === (int) $shift->client_id, 404);
            }

            $resident = $lockedPresenceResident instanceof Client
                ? $lockedPresenceResident
                : $this->scope->clientFor($actor, (int) $shift->client_id, true);
            abort_unless((int) $resident->id === (int) $shift->client_id, 404);
            abort_unless((int) ($shift->site_id ?: $resident->site_id) === (int) $resident->site_id, 404);
            abort_unless((int) $shift->user_id === (int) $actor->id, 404);

            return [$resident, $shift];
        }

        if (! empty($data['client_id'])) {
            return [$this->scope->clientFor($actor, (int) $data['client_id'], true), null];
        }

        $name = trim((string) ($data['resident_name'] ?? ''));
        $query = Client::query()
            ->whereRaw("TRIM(CONCAT_WS(' ', first_name, last_name)) = ?", [$name])
            ->limit(2);
        $this->scope->applyClientScope($query, $actor);
        $matches = $query->lockForUpdate()->get();
        if ($matches->count() !== 1) {
            throw ValidationException::withMessages([
                'client_id' => 'Select the resident record for this journey.',
            ]);
        }

        return [$matches->first(), null];
    }

    /**
     * @return array{0: CarbonInterface, 1: Collection<int, Shift>|null, 2: Client|null}
     */
    private function prelockCreateMedicationPresenceShifts(User $actor, array $data): array
    {
        $effectiveAt = now();
        if (empty($data['medications']) || empty($data['shift_id'])) {
            return [$effectiveAt, null, null];
        }

        // This is an identity/scope snapshot only. The returned Shift is never
        // trusted for mutation; the complete main+witness candidate union is
        // the first locking Shift query below and is revalidated afterwards.
        $shiftSnapshot = $this->scope->shiftFor($actor, (int) $data['shift_id']);
        abort_unless($shiftSnapshot->client_id, 404);
        $resident = $this->scope->clientFor($actor, (int) $shiftSnapshot->client_id, true);
        $siteId = (int) ($shiftSnapshot->site_id ?: $resident->site_id);
        abort_unless($siteId > 0, 404);

        $medicationIds = collect((array) ($data['medications'] ?? []))
            ->pluck('medication_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->sort()
            ->values();
        $lockedMedicationIds = ClientMedication::query()
            ->where('client_id', $resident->id)
            ->whereIn('id', $medicationIds->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id);
        abort_unless(
            $medicationIds->isNotEmpty()
                && $lockedMedicationIds->count() === $medicationIds->count(),
            404,
        );

        $userIds = [(int) $actor->id];
        foreach ((array) ($data['medications'] ?? []) as $payload) {
            if (is_numeric($payload['witnessed_by_user_id'] ?? null)
                && (int) $payload['witnessed_by_user_id'] > 0) {
                $userIds[] = (int) $payload['witnessed_by_user_id'];
            }
        }

        return [
            $effectiveAt,
            $this->transportWitnesses->lockPresenceShiftsAtSite(
                array_values(array_unique($userIds)),
                $siteId,
                $effectiveAt,
                [(int) $shiftSnapshot->id],
            ),
            $resident,
        ];
    }

    private function resolveBooking(User $actor, array $data, int $assetId, int $siteId): ?FleetVehicleBooking
    {
        if (empty($data['booking_id'])) {
            return null;
        }

        return FleetVehicleBooking::query()
            ->whereKey((int) $data['booking_id'])
            ->where('asset_id', $assetId)
            ->where('user_id', $actor->id)
            ->where(function (Builder $pickup) use ($siteId): void {
                $pickup->whereNull('pickup_site_id')->orWhere('pickup_site_id', $siteId);
            })
            ->where(function (Builder $return) use ($siteId): void {
                $return->whereNull('return_site_id')->orWhere('return_site_id', $siteId);
            })
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertShiftWindowAndDriver(User $actor, ?Shift $shift, array $data): void
    {
        if (! $shift) {
            return;
        }

        $departedAt = Carbon::parse($data['departed_at']);
        $allowedStart = $shift->starts_at?->copy()->subHours(2);
        $allowedEnd = $shift->ends_at?->copy()->addHours(2);
        if (($allowedStart && $departedAt->lt($allowedStart)) || ($allowedEnd && $departedAt->gt($allowedEnd))) {
            throw ValidationException::withMessages([
                'departed_at' => 'Transport must align with the linked shift window. Use a time within two hours of the shift boundary or unlink the transport from the shift.',
            ]);
        }

        if (! $this->coverageRoles->userHasRole($actor, 'driver')) {
            throw ValidationException::withMessages([
                'asset_id' => 'Only staff with current driver eligibility can log a resident transport against a shift.',
            ]);
        }
    }

    /** @return array<int, array{medication: ClientMedication, order_version: ?MedicationOrderVersion, scan_audit: array}> */
    private function resolveMedicationPayloads(Client $resident, array $payloads, bool $lockForUpdate): array
    {
        $seen = [];
        $resolved = [];
        foreach ($payloads as $payload) {
            $medicationId = (int) ($payload['medication_id'] ?? 0);
            abort_unless($medicationId > 0 && ! isset($seen[$medicationId]), 404);
            $seen[$medicationId] = true;
            $resolved[] = $this->resolveMedicationPayload($resident, $payload, $lockForUpdate);
        }

        return $resolved;
    }

    /** @return array{medication: ClientMedication, order_version: ?MedicationOrderVersion, scan_audit: array} */
    private function resolveMedicationPayload(
        Client $resident,
        array $payload,
        bool $lockForUpdate,
        bool $verifyScan = true,
    ): array {
        $medication = ClientMedication::query()
            ->active()
            ->whereKey((int) ($payload['medication_id'] ?? 0))
            ->where('client_id', $resident->id)
            ->when($lockForUpdate, fn (Builder $query): Builder => $query->lockForUpdate())
            ->firstOrFail();
        $orderVersion = $this->currentOrderVersion($medication);
        if (! empty($payload['medication_order_version_id'])) {
            abort_unless($orderVersion && (int) $payload['medication_order_version_id'] === (int) $orderVersion->id, 404);
        }

        return [
            'medication' => $medication,
            'order_version' => $orderVersion,
            'scan_audit' => $verifyScan
                ? $this->verifyMedicationScan($resident, $medication, $payload)
                : null,
        ];
    }

    private function currentOrderVersion(ClientMedication $medication): ?MedicationOrderVersion
    {
        return MedicationOrderVersion::query()
            ->where('client_medication_id', $medication->id)
            ->where('client_id', $medication->client_id)
            ->where('version_number', $medication->version)
            ->latest('id')
            ->first();
    }

    private function recordPackingNonAcceptance(
        FleetResidentTransport $transport,
        Client $resident,
        User $actor,
        array $prepared,
        array $payload,
        string $attestationState,
        string $action,
        string $requestUuid,
        string $requestHash,
        ?Collection $lockedUsers = null,
    ): void {
        /** @var ClientMedication $medication */
        $medication = $prepared['medication'];
        /** @var MedicationOrderVersion|null $orderVersion */
        $orderVersion = $prepared['order_version'];
        abort_unless($medication->requiresWitness(), 409, 'This medication does not require a packing attestation.');

        $lockedUsers ??= $this->lockCurrentFleetMedicationUsers(
            $actor,
            (int) $resident->site_id,
            'fleet.medication.manage',
            is_numeric($payload['witnessed_by_user_id'] ?? null)
                ? (int) $payload['witnessed_by_user_id']
                : null,
        );
        /** @var User $actor */
        $actor = $lockedUsers->get((int) $actor->id);

        $reason = trim((string) ($payload['attestation_reason'] ?? ''));
        if ($reason === '') {
            throw ValidationException::withMessages([
                'attestation_reason' => 'Record why the second checker declined or was unavailable.',
            ]);
        }

        $attestation = $this->packingAttestation(
            $actor,
            $resident,
            $medication,
            $payload,
            $attestationState,
            $lockedUsers,
        );
        /** @var User|null $witness */
        $witness = $attestation['witness'] ?? null;

        $subjectDigest = $this->medicationCustodyDigest($transport, null, $medication, $actor->id);
        $transport->forceFill(['version' => ((int) $transport->version) + 1])->save();
        $event = $this->recordEvent(
            $transport,
            $action,
            $actor,
            $requestUuid,
            [
                'request_hash' => $requestHash,
                'attestation' => $attestation
                    ? $this->attestationContext($attestation, $subjectDigest, $attestationState)
                    : [
                        'state' => $attestationState,
                        'subject_digest' => $subjectDigest,
                        'subject_digest_algorithm' => 'sha256',
                        'subject_payload_version' => 'fleet-medication-custody-v1',
                    ],
                'attestation_reason' => $reason,
                ...$this->offlineProvenance($payload),
            ],
            null,
            $medication,
            $orderVersion,
            null,
            $witness?->id,
        );
        AuditLogger::logOrFail('fleet.medication.pack.'.$attestationState, $transport, [
            'actor_id' => $actor->id,
            'site_id' => $transport->site_id,
            'client_id' => $resident->id,
            'transport_id' => $transport->id,
            'journey_uuid' => $transport->journey_uuid,
            'medication_id' => $medication->id,
            'medication_order_version' => $medication->version,
            'medication_order_version_id' => $orderVersion?->id,
            'request_uuid' => $requestUuid,
            'attestation_event_id' => $event->id,
            'attestation_state' => $attestationState,
            'witness_user_id' => $witness?->id,
            'attestation_reason' => $reason,
            ...$this->offlineProvenance($payload),
        ]);
    }

    private function createMedicationCustody(
        FleetResidentTransport $transport,
        Client $resident,
        User $actor,
        array $prepared,
        array $payload,
        string $requestUuid,
        ?string $parentRequestUuid = null,
        ?string $requestHashOverride = null,
        ?Collection $lockedUsers = null,
    ): FleetMedicationTransitLog {
        /** @var ClientMedication $medication */
        $medication = $prepared['medication'];
        /** @var MedicationOrderVersion|null $orderVersion */
        $orderVersion = $prepared['order_version'];
        $requestHash = $requestHashOverride ?? $this->requestHash('medication_packed', [
            'transport_id' => $transport->id,
            'client_id' => $resident->id,
            'medication_id' => $medication->id,
            'medication_order_version_id' => $orderVersion?->id,
            'parent_request_uuid' => $parentRequestUuid,
            'attestation_state' => $payload['attestation_state'] ?? null,
            'witnessed_by_user_id' => $payload['witnessed_by_user_id'] ?? null,
            'attestation_reason_hash' => hash('sha256', (string) ($payload['attestation_reason'] ?? '')),
            'notes_hash' => hash('sha256', (string) ($payload['notes'] ?? '')),
            'scan_code_hash' => hash('sha256', (string) ($payload['scan_code'] ?? '')),
        ]);

        $lockedUsers ??= $this->lockCurrentFleetMedicationUsers(
            $actor,
            (int) $resident->site_id,
            'fleet.medication.manage',
            is_numeric($payload['witnessed_by_user_id'] ?? null)
                ? (int) $payload['witnessed_by_user_id']
                : null,
        );
        /** @var User $actor */
        $actor = $lockedUsers->get((int) $actor->id);
        if (($payload['attestation_state'] ?? 'accepted') !== 'accepted') {
            throw ValidationException::withMessages([
                'attestation_state' => 'An authenticated second checker must accept before this medication is packed.',
            ]);
        }
        $attestation = $this->packingAttestation(
            $actor,
            $resident,
            $medication,
            $payload,
            'accepted',
            $lockedUsers,
        );
        /** @var User|null $witness */
        $witness = $attestation['witness'] ?? null;

        $log = FleetMedicationTransitLog::query()->create([
            'transport_id' => $transport->id,
            'client_id' => $resident->id,
            'site_id' => $transport->site_id,
            'shift_id' => $transport->shift_id,
            'medication_id' => $medication->id,
            'medication_order_version' => $medication->version,
            'medication_order_version_id' => $orderVersion?->id,
            'medication_name' => trim($medication->name.' '.($medication->dosage ?? '')),
            'is_controlled_drug' => (bool) $medication->controlled_drug,
            'witness_required' => $medication->requiresWitness(),
            'packed_witness_name' => $witness?->name,
            'packed_witnessed_by_user_id' => $witness?->id,
            'packed_witnessed_at' => $attestation['witnessed_at'] ?? null,
            'packing_witness_method' => $attestation['method'] ?? null,
            'packed_by_user_id' => $actor->id,
            'packed_at' => $this->medicationCustodyActionAt($transport, $payload),
            'notes' => $payload['notes'] ?? null,
        ]);
        $transport->forceFill(['version' => ((int) $transport->version) + 1])->save();

        $scanAudit = $prepared['scan_audit'];
        $subjectDigest = $this->medicationCustodyDigest($transport, $log, $medication);
        $event = $this->recordEvent(
            $transport,
            'medication_packed',
            $actor,
            $requestUuid,
            [
                'request_hash' => $requestHash,
                'parent_request_uuid' => $parentRequestUuid,
                'scan_source' => $scanAudit['scan_source'],
                'scan_match_source' => $scanAudit['scan_match_source'],
                'entered_code_suffix' => $scanAudit['scan_code_suffix'],
                ...($attestation ? [
                    'attestation' => $this->attestationContext($attestation, $subjectDigest, 'accepted'),
                ] : []),
                ...$this->offlineProvenance($payload),
            ],
            $log,
            $medication,
            $orderVersion,
            null,
            $witness?->id,
        );
        if ($attestation) {
            $log->forceFill(['packing_attestation_event_id' => $event->id])->save();
        }
        AuditLogger::logOrFail('fleet.medication.pack', $log, [
            'actor_id' => $actor->id,
            'site_id' => $transport->site_id,
            'client_id' => $resident->id,
            'transport_id' => $transport->id,
            'journey_uuid' => $transport->journey_uuid,
            'medication_id' => $medication->id,
            'medication_order_version' => $medication->version,
            'medication_order_version_id' => $orderVersion?->id,
            'request_uuid' => $requestUuid,
            'packing_attestation_event_id' => $attestation ? $event->id : null,
            'witness_user_id' => $witness?->id,
            'scan_source' => $scanAudit['scan_source'],
            'scan_match_source' => $scanAudit['scan_match_source'],
            'entered_code_suffix' => $scanAudit['scan_code_suffix'],
            ...$this->offlineProvenance($payload),
        ]);

        if ($medication->controlled_drug) {
            $this->incidents->handleTransitException($log);
        }

        return $log;
    }

    /** @return array{scan_source: string, scan_match_source: ?string, scan_match_label: ?string, scan_code_suffix: string} */
    private function verifyMedicationScan(Client $resident, ClientMedication $medication, array $payload): array
    {
        if (! ($payload['scan_verified'] ?? false) || blank($payload['scan_code'] ?? null)) {
            throw ValidationException::withMessages([
                'scan_code' => 'Verify the medication code before continuing.',
            ]);
        }

        $result = $this->scanVerification->verify($resident, $medication, (string) $payload['scan_code']);
        if (! $result['matched']) {
            throw ValidationException::withMessages(['scan_code' => $result['message']]);
        }
        if (filled($payload['scan_match_source'] ?? null) && $payload['scan_match_source'] !== $result['match_source']) {
            throw ValidationException::withMessages([
                'scan_code' => 'The medication verification needs to be repeated.',
            ]);
        }

        return [
            'scan_source' => (string) ($payload['scan_source'] ?? 'manual'),
            'scan_match_source' => $result['match_source'],
            'scan_match_label' => $result['match_label'],
            'scan_code_suffix' => substr($this->scanVerification->normalize((string) $payload['scan_code']), -6),
        ];
    }

    /**
     * @param array{witness: User, witnessed_at: CarbonInterface, method: string, authority_permission: string, employment_profile_id: int, competency_state: string,
     * competency_assessment_id: int, presence_source: string, presence_record_id: int,
     * presence_started_at: string, presence_ends_at: ?string} $attestation
     */
    private function attestationContext(array $attestation, string $subjectDigest, string $state): array
    {
        return [
            'state' => $state,
            'method' => $attestation['method'],
            'witnessed_at' => $attestation['witnessed_at']->toIso8601String(),
            'subject_digest' => $subjectDigest,
            'subject_digest_algorithm' => 'sha256',
            'subject_payload_version' => 'fleet-medication-custody-v1',
            'authority_permission' => $attestation['authority_permission'],
            'employment_profile_id' => $attestation['employment_profile_id'],
            'competency_state' => $attestation['competency_state'],
            'competency_assessment_id' => $attestation['competency_assessment_id'],
            'presence_source' => $attestation['presence_source'],
            'presence_record_id' => $attestation['presence_record_id'],
            'presence_started_at' => $attestation['presence_started_at'],
            'presence_ends_at' => $attestation['presence_ends_at'],
        ];
    }

    private function medicationCustodyDigest(
        FleetResidentTransport $transport,
        ?FleetMedicationTransitLog $log,
        ClientMedication $medication,
        ?int $packingActorId = null,
    ): string {
        return hash('sha256', json_encode([
            'payload_version' => 'fleet-medication-custody-v1',
            'journey_uuid' => $transport->journey_uuid,
            'transport_id' => $transport->id,
            'medication_transit_log_id' => $log?->id,
            'client_id' => $transport->resident_id,
            'site_id' => $transport->site_id,
            'shift_id' => $transport->shift_id,
            'asset_id' => $transport->asset_id,
            'medication_id' => $medication->id,
            'medication_order_version' => $log?->medication_order_version ?? $medication->version,
            'medication_order_version_id' => $log?->medication_order_version_id ?? $this->currentOrderVersion($medication)?->id,
            'packed_by_user_id' => $log?->packed_by_user_id ?? $packingActorId,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    public function governedPackingAttestationGaps(Builder $query): Builder
    {
        return $query
            ->where(function (Builder $required): void {
                $required->where('witness_required', true)
                    ->orWhere('is_controlled_drug', true);
            })
            ->where(function (Builder $gap): void {
                $gap->whereNull('packing_attestation_event_id')
                    ->orWhereNull('packed_witnessed_by_user_id')
                    ->orWhereNull('packed_witnessed_at')
                    ->orWhereNull('packing_witness_method')
                    ->orWhere('packing_witness_method', '!=', 'password')
                    ->orWhereDoesntHave('packingAttestationEvent', function (Builder $event): void {
                        $event->whereIn('action', [
                            'medication_packed',
                            'medication_packing_attestation_corrected',
                        ])
                            ->whereIn('context->attestation->state', ['accepted', 'corrected'])
                            ->where('context->attestation->method', 'password')
                            ->whereNotNull('context->attestation->subject_digest')
                            ->whereNotNull('witness_user_id')
                            ->whereColumn(
                                'fleet_resident_transport_events.transport_id',
                                'fleet_medication_transit_logs.transport_id',
                            )
                            ->whereColumn(
                                'fleet_resident_transport_events.medication_transit_log_id',
                                'fleet_medication_transit_logs.id',
                            )
                            ->whereColumn(
                                'fleet_resident_transport_events.client_id',
                                'fleet_medication_transit_logs.client_id',
                            )
                            ->whereColumn(
                                'fleet_resident_transport_events.site_id',
                                'fleet_medication_transit_logs.site_id',
                            )
                            ->whereColumn(
                                'fleet_resident_transport_events.medication_id',
                                'fleet_medication_transit_logs.medication_id',
                            )
                            ->whereColumn(
                                'fleet_resident_transport_events.witness_user_id',
                                'fleet_medication_transit_logs.packed_witnessed_by_user_id',
                            );
                    });
            });
    }

    private function replayedEvent(
        User $actor,
        string $requestUuid,
        string $action,
        string $requestHash,
    ): ?FleetResidentTransportEvent {
        $event = FleetResidentTransportEvent::query()
            ->where('request_uuid', $requestUuid)
            ->lockForUpdate()
            ->first();
        if (! $event) {
            return null;
        }

        if (
            $event->action !== $action
            || (int) $event->actor_user_id !== (int) $actor->id
            || ! hash_equals((string) data_get($event->context, 'request_hash'), $requestHash)
        ) {
            throw ValidationException::withMessages([
                'client_request_uuid' => 'This submission identifier has already been used for another journey action.',
            ]);
        }

        return $event;
    }

    private function recordEvent(
        FleetResidentTransport $transport,
        string $action,
        User $actor,
        string $requestUuid,
        array $context,
        ?FleetMedicationTransitLog $log = null,
        ?ClientMedication $medication = null,
        ?MedicationOrderVersion $orderVersion = null,
        ?int $administrationId = null,
        ?int $witnessId = null,
    ): FleetResidentTransportEvent {
        $previousHash = FleetResidentTransportEvent::query()
            ->where('transport_id', $transport->id)
            ->lockForUpdate()
            ->latest('id')
            ->value('event_hash');
        $occurredAt = now();
        $eventAttributes = [
            'transport_id' => $transport->id,
            'medication_transit_log_id' => $log?->id,
            'client_id' => $transport->resident_id,
            'site_id' => $transport->site_id,
            'shift_id' => $transport->shift_id,
            'asset_id' => $transport->asset_id,
            'medication_id' => $medication?->id,
            'medication_order_version_id' => $orderVersion?->id,
            'medication_administration_id' => $administrationId,
            'action' => $action,
            'actor_user_id' => $actor->id,
            'witness_user_id' => $witnessId,
            'request_uuid' => $requestUuid,
            'occurred_at' => $occurredAt->toJSON(),
            'previous_event_hash' => $previousHash,
            'context' => $context,
        ];
        $eventHash = hash('sha256', json_encode([
            ...$eventAttributes,
            'journey_uuid' => $transport->journey_uuid,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return FleetResidentTransportEvent::query()->create([
            ...$eventAttributes,
            'event_hash' => $eventHash,
        ]);
    }

    private function requestUuid(array $data): string
    {
        return filled($data['client_request_uuid'] ?? null)
            ? (string) $data['client_request_uuid']
            : (string) Str::uuid();
    }

    private function requestHash(string $action, array $payload): string
    {
        return hash('sha256', json_encode([
            'action' => $action,
            'payload' => $payload,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @return array{captured_offline_at: ?string, origin_device_id: ?string, queued_offline: true}|array{} */
    private function normalizedOfflineProvenanceForFingerprint(array $payload): array
    {
        if (! ($payload['queued_offline'] ?? false)) {
            return [];
        }

        $capturedAt = null;
        if (filled($payload['captured_offline_at'] ?? null)) {
            try {
                $capturedAt = Carbon::parse((string) $payload['captured_offline_at'])
                    ->utc()
                    ->toISOString();
            } catch (\Throwable) {
                throw ValidationException::withMessages([
                    'captured_offline_at' => 'The offline capture time is invalid.',
                ]);
            }
        }

        return [
            'captured_offline_at' => $capturedAt,
            'origin_device_id' => filled($payload['origin_device_id'] ?? null)
                ? trim((string) $payload['origin_device_id'])
                : null,
            'queued_offline' => true,
        ];
    }

    private function requiredAdministrationQuantity(array $payload): string
    {
        if (
            ! array_key_exists('quantity_administered', $payload)
            || (
                ! is_int($payload['quantity_administered'])
                && ! is_float($payload['quantity_administered'])
                && ! is_string($payload['quantity_administered'])
            )
        ) {
            throw ValidationException::withMessages([
                'quantity_administered' => 'Record how many units were given.',
            ]);
        }

        try {
            $quantity = MedicationStockQuantity::normalizeMovement($payload['quantity_administered']);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages([
                'quantity_administered' => 'Quantity administered must use no more than two decimal places and must not exceed '
                    .MedicationStockQuantity::DECIMAL_10_2_MAX.'.',
            ]);
        }

        if (! MedicationStockQuantity::greaterThan($quantity, 0)) {
            throw ValidationException::withMessages([
                'quantity_administered' => 'Quantity administered must be greater than zero.',
            ]);
        }

        return $quantity;
    }

    private function medicationCustodyActionAt(
        FleetResidentTransport $transport,
        array $payload,
    ): CarbonInterface {
        // Shift/administration timestamps are persisted at whole-second
        // precision. Normalize before the first presence lock so the nested
        // EnhancedMar call reparses the exact same boundary and cannot discover
        // a new Shift after the authorization User set is already held.
        $receivedAt = now()->utc()->setMicrosecond(0);
        if (! ($payload['queued_offline'] ?? false)) {
            return $receivedAt;
        }

        if (! filled($payload['captured_offline_at'] ?? null)) {
            throw ValidationException::withMessages([
                'captured_offline_at' => 'The offline capture time is required.',
            ]);
        }

        try {
            $capturedAt = Carbon::parse((string) $payload['captured_offline_at'])
                ->utc()
                ->setMicrosecond(0);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'captured_offline_at' => 'The offline capture time is invalid.',
            ]);
        }

        $journeyStart = $this->storageInstant($transport, 'departed_at')
            ?? $this->storageInstant($transport, 'created_at');
        if ($journeyStart && $capturedAt->lt($journeyStart)) {
            throw ValidationException::withMessages([
                'captured_offline_at' => 'The offline medication action predates this active journey.',
            ]);
        }
        if ($capturedAt->gt($receivedAt->copy()->addMinutes(self::OFFLINE_CAPTURE_FUTURE_SKEW_MINUTES))) {
            throw ValidationException::withMessages([
                'captured_offline_at' => 'The offline medication action time is too far ahead of the server clock. Correct the device clock and retry.',
            ]);
        }

        return $capturedAt;
    }

    private function storageInstant(Model $model, string $attribute): ?Carbon
    {
        $raw = $model->getRawOriginal($attribute);
        if (! filled($raw)) {
            return null;
        }

        return $raw instanceof \DateTimeInterface
            ? Carbon::instance($raw)->copy()->utc()
            : Carbon::parse((string) $raw, config('app.timezone', 'UTC'));
    }

    /** @return array{captured_offline_at?: mixed, origin_device_id?: mixed, queued_offline?: bool} */
    private function offlineProvenance(array $payload): array
    {
        return array_filter([
            'captured_offline_at' => $payload['captured_offline_at'] ?? null,
            'origin_device_id' => $payload['origin_device_id'] ?? null,
            'queued_offline' => isset($payload['queued_offline']) ? (bool) $payload['queued_offline'] : null,
        ], fn (mixed $value): bool => $value !== null);
    }

    private function residentName(Client $resident): string
    {
        return trim(($resident->first_name ?? '').' '.($resident->last_name ?? ''));
    }
}
