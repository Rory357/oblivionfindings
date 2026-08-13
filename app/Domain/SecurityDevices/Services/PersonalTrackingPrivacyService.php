<?php

namespace App\Domain\SecurityDevices\Services;

use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\FleetVehicleStateSnapshot;
use App\Services\AuditLogger;
use App\Services\ConsentValidationService;
use Illuminate\Support\Facades\Schema;

class PersonalTrackingPrivacyService
{
    private const LOCATION_KEYS = [
        'accuracy',
        'accuracy_m',
        'address',
        'altitude',
        'altitude_m',
        'coordinates',
        'course',
        'heading',
        'heading_deg',
        'last_location_at',
        'lat',
        'latitude',
        'lng',
        'location',
        'location_description',
        'lon',
        'longitude',
        'position',
        'speed',
        'speed_kph',
    ];

    public function activeClientAssignment(Client $client): ?DeviceAssignment
    {
        return DeviceAssignment::query()
            ->with(['device', 'consent.consentType'])
            ->where('assignable_type', DeviceAssignment::TARGET_CLIENT)
            ->where('assignable_id', $client->id)
            ->current()
            ->whereHas('device', fn ($query) => $query->where('domain', 'tracking'))
            ->latest('assigned_at')
            ->latest('id')
            ->first();
    }

    public function authorisedClientAssignment(Client $client): ?DeviceAssignment
    {
        $assignment = $this->activeClientAssignment($client);

        return $assignment && $this->assignmentAuthorisesResidentLocation($assignment, $client)
            ? $assignment
            : null;
    }

    public function activeConsentForClientAssignment(Client $client): ?ClientConsent
    {
        return $this->authorisedClientAssignment($client)?->consent;
    }

    public function assignmentAuthorisesClient(
        DeviceAssignment $assignment,
        Client|int $client,
    ): bool {
        return $this->assignmentAuthorisesClientForPurpose(
            $assignment,
            $client,
            fn (ClientConsent $consent): bool => ConsentValidationService::isValidTrackingConsent($consent),
        );
    }

    public function assignmentAuthorisesResidentLocation(
        DeviceAssignment $assignment,
        Client|int $client,
    ): bool {
        return $this->assignmentAuthorisesClientForPurpose(
            $assignment,
            $client,
            fn (ClientConsent $consent): bool => ConsentValidationService::isValidResidentLocationConsent($consent),
        );
    }

    private function assignmentAuthorisesClientForPurpose(
        DeviceAssignment $assignment,
        Client|int $client,
        callable $consentAuthorisesPurpose,
    ): bool {
        $clientId = $client instanceof Client ? $client->id : $client;
        $assignment->loadMissing(['device', 'consent.consentType']);
        $consent = $assignment->consent;
        $device = $assignment->device;
        $clientModel = $client instanceof Client
            ? $client
            : Client::query()->find($clientId, ['id', 'site_id']);

        return $assignment->assignable_type === DeviceAssignment::TARGET_CLIENT
            && (int) $assignment->assignable_id === (int) $clientId
            && $assignment->assigned_at?->lessThanOrEqualTo(now())
            && $assignment->isCollectionActive()
            && $clientModel instanceof Client
            && is_numeric($clientModel->site_id)
            && (int) $assignment->custody_site_id === (int) $clientModel->site_id
            && app(DeviceCustodySiteResolver::class)->assignmentMatchesCurrentTarget($assignment)
            && $device instanceof Device
            && (int) $device->id === (int) $assignment->device_id
            && $device->domain === 'tracking'
            && ! in_array($device->status, [
                DeviceStatus::Decommissioned,
                DeviceStatus::Quarantined,
                DeviceStatus::Lost,
            ], true)
            && $consent !== null
            && (int) $consent->client_id === (int) $clientId
            && $assignment->authority_basis === 'assignment_linked_client_consent'
            && is_string($assignment->tracking_purpose)
            && trim($assignment->tracking_purpose) !== ''
            && is_array($assignment->access_audience)
            && in_array('authorised_client_care', $assignment->access_audience, true)
            && is_numeric($assignment->retention_days)
            && (int) $assignment->retention_days > 0
            && $assignment->collection_started_at?->lessThanOrEqualTo(now())
            && $consentAuthorisesPurpose($consent);
    }

    /**
     * Stop every active personal-tracker assignment tied to this exact consent.
     * The caller should update and lock the consent in the same transaction.
     *
     * @return array{assignments_stopped: int, devices_cleared: int, live_states_cleared: int}
     */
    public function stopForConsent(ClientConsent $consent, int $actorUserId): array
    {
        $assignmentQuery = fn () => DeviceAssignment::query()
            ->where('consent_id', $consent->id)
            ->where('assignable_type', DeviceAssignment::TARGET_CLIENT)
            ->whereNull('released_at')
            ->whereHas('device', fn ($query) => $query->where('domain', 'tracking'));

        // Every assignment mutation locks consent -> Device -> assignment.
        // Lock affected Devices in a deterministic order before assignment rows
        // so consent withdrawal cannot deadlock with release or transfer.
        $deviceIds = $assignmentQuery()
            ->distinct()
            ->orderBy('device_id')
            ->pluck('device_id');
        Device::query()
            ->whereKey($deviceIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);
        $assignments = $assignmentQuery()
            ->orderBy('device_id')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $result = [
            'assignments_stopped' => 0,
            'devices_cleared' => 0,
            'live_states_cleared' => 0,
        ];

        foreach ($assignments as $assignment) {
            $stopped = $this->stopAssignment(
                $assignment,
                $actorUserId,
                'consent_withdrawn',
                'collection_stopped_and_live_projection_revoked',
            );

            $result['assignments_stopped'] += $stopped['assignment_stopped'] ? 1 : 0;
            $result['devices_cleared'] += $stopped['device_cleared'] ? 1 : 0;
            $result['live_states_cleared'] += $stopped['live_states_cleared'];
        }

        AuditLogger::logOrFail('tracking.consent.withdrawal_enforced', $consent, [
            'actor_id' => $actorUserId,
            'consent_id' => $consent->id,
            'client_id' => $consent->client_id,
            ...$result,
            'history_outcome' => 'retained_until_assignment_retention_cutoff',
        ]);

        return $result;
    }

    public function resumeClientAssignment(
        DeviceAssignment $assignment,
        ClientConsent $consent,
        int $actorUserId,
    ): DeviceAssignment {
        $consent->loadMissing('consentType');
        if ($assignment->assignable_type !== DeviceAssignment::TARGET_CLIENT
            || (int) $assignment->assignable_id !== (int) $consent->client_id
            || ! ConsentValidationService::isValidTrackingConsent($consent)) {
            throw new \InvalidArgumentException(
                'Tracking collection can only resume with an active location consent linked to this client assignment.',
            );
        }

        $device = Device::query()->lockForUpdate()->findOrFail($assignment->device_id);
        $assignment = DeviceAssignment::query()
            ->lockForUpdate()
            ->findOrFail($assignment->id);

        if ($device->domain !== 'tracking' || $assignment->released_at !== null) {
            throw new \InvalidArgumentException('Only an active personal-tracker assignment can resume collection.');
        }

        $assignment->forceFill([
            'consent_id' => $consent->id,
            'tracking_purpose' => $consent->consentType?->purpose
                ?: $consent->consentType?->name
                ?: 'Client personal safety tracking',
            'authority_basis' => 'assignment_linked_client_consent',
            'access_audience' => ['authorised_client_care', 'control_room', 'health_and_safety'],
            'retention_days' => max(1, (int) config('fleet.retention.personal_location_days', 90)),
            'collection_started_at' => now(),
            'collection_stopped_at' => null,
            'collection_stop_reason' => null,
            'withdrawal_outcome' => null,
        ])->save();

        AuditLogger::logOrFail('tracking.collection.resumed', $assignment, [
            'actor_id' => $actorUserId,
            'assignment_id' => $assignment->id,
            'device_id' => $assignment->device_id,
            'client_id' => $assignment->assignable_id,
            'consent_id' => $consent->id,
            'retention_days' => $assignment->retention_days,
        ]);

        return $assignment->fresh();
    }

    /**
     * Stop collection for one assignment and revoke every mutable live-location
     * projection. Governed historical telemetry remains subject to retention.
     *
     * @return array{assignment_stopped: bool, device_cleared: bool, live_states_cleared: int}
     */
    public function stopAssignment(
        DeviceAssignment $assignment,
        ?int $actorUserId,
        string $stopReason,
        string $outcome = 'collection_stopped_and_live_projection_revoked',
    ): array {
        $device = Device::query()
            ->with([
                'activeAssetLinks:id,device_id,asset_id',
                'legacyAssetTracker:id,asset_id,vendor_metadata',
            ])
            ->lockForUpdate()
            ->findOrFail($assignment->device_id);
        $assignment = DeviceAssignment::query()
            ->lockForUpdate()
            ->findOrFail($assignment->id);

        if ((int) $assignment->device_id !== (int) $device->id) {
            throw new \RuntimeException('The tracking assignment device changed while collection was stopping.');
        }

        if ($device->domain !== 'tracking'
            || ! in_array($assignment->assignable_type, [
                DeviceAssignment::TARGET_CLIENT,
                DeviceAssignment::TARGET_STAFF,
            ], true)) {
            return [
                'assignment_stopped' => false,
                'device_cleared' => false,
                'live_states_cleared' => 0,
            ];
        }

        $stoppedAt = now();
        $assignmentStopped = $assignment->collection_stopped_at === null;
        if ($assignmentStopped) {
            $assignment->forceFill([
                'collection_stopped_at' => $stoppedAt,
                'collection_stop_reason' => $stopReason,
                'withdrawal_outcome' => $outcome,
            ])->save();
        }

        $device->forceFill([
            'latitude' => null,
            'longitude' => null,
            'location_description' => null,
            'meta' => $this->redactLocationPayload($device->meta ?? []),
        ])->save();

        if ($device->legacyAssetTracker) {
            $device->legacyAssetTracker->forceFill([
                'vendor_metadata' => $this->redactLocationPayload(
                    $device->legacyAssetTracker->vendor_metadata ?? [],
                ),
            ])->save();
        }

        $assetIds = $device->activeAssetLinks
            ->pluck('asset_id')
            ->when(
                $device->legacyAssetTracker?->asset_id,
                fn ($ids, $assetId) => $ids->push((int) $assetId),
            )
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
        $statesCleared = 0;

        if ($assetIds->isNotEmpty() && Schema::hasTable('fleet_vehicle_state_snapshots')) {
            $statesCleared = FleetVehicleStateSnapshot::query()
                ->whereIn('asset_id', $assetIds->all())
                ->update([
                    'last_event_id' => null,
                    'last_trip_id' => null,
                    'last_moving_at' => null,
                    'latitude' => null,
                    'longitude' => null,
                    'speed_kph' => null,
                    'heading_deg' => null,
                    'ignition' => null,
                    'motion_status' => null,
                    'consent_blocked' => true,
                    'updated_at' => $stoppedAt,
                ]);
        }

        AuditLogger::logOrFail('tracking.collection.stopped', $assignment, [
            'actor_id' => $actorUserId,
            'assignment_id' => $assignment->id,
            'device_id' => $device->id,
            'client_id' => $assignment->assignable_type === DeviceAssignment::TARGET_CLIENT
                ? $assignment->assignable_id
                : null,
            'consent_id' => $assignment->consent_id,
            'stop_reason' => $stopReason,
            'outcome' => $outcome,
            'live_states_cleared' => $statesCleared,
            'history_outcome' => 'retained_until_assignment_retention_cutoff',
        ]);

        return [
            'assignment_stopped' => $assignmentStopped,
            'device_cleared' => true,
            'live_states_cleared' => $statesCleared,
        ];
    }

    /**
     * Remove location and motion values from an arbitrary provider payload.
     * Generic device views use this when they retain non-location diagnostic
     * metadata for a personal tracker.
     *
     * @param  array<string|int, mixed>  $payload
     * @return array<string|int, mixed>
     */
    public function redactLocationPayload(array $payload): array
    {
        $blocked = array_map(
            fn (string $key): string => $this->normaliseKey($key),
            self::LOCATION_KEYS,
        );
        $sanitised = [];

        foreach ($payload as $key => $value) {
            if (in_array($this->normaliseKey((string) $key), $blocked, true)) {
                continue;
            }

            if (is_array($value)) {
                $redacted = $this->redactLocationPayload($value);
                if ($redacted !== []) {
                    $sanitised[$key] = $redacted;
                }

                continue;
            }

            $sanitised[$key] = $value;
        }

        return $sanitised;
    }

    private function normaliseKey(string $key): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $key));
    }
}
