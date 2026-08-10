<?php

namespace App\Services\Fleet;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Events\FleetVehiclePositionUpdated;
use App\Jobs\ReverseGeocodeFleetTelemetryEvent;
use App\Models\Asset;
use App\Models\AssetTelemetrySnapshot;
use App\Models\AssetTracker;
use App\Models\Client;
use App\Models\ControlRoom\Signal as ControlRoomSignal;
use App\Models\ControlRoomAlert;
use App\Models\FleetSignal;
use App\Models\FleetTelemetryEvent;
use App\Models\FleetVehicleStateSnapshot;
use App\Models\LoneWorkerSession;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\Fleet\Telemetry\AdapterRegistry;
use App\Services\HealthSafety\LoneWorkerSignalService;
use App\Services\UserSiteAccessService;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class FleetTelemetryIngestService
{
    private const PRIVACY_PAYLOAD_DENYLIST = [
        'client_id',
        'resident_id',
        'staff_id',
        'worker_id',
        'worker_user_id',
        'lone_worker_session_id',
        'session_id',
        'user_id',
        'assigned_user_id',
        'primary_driver_user_id',
        'booked_by_user_id',
        'device_id',
        'person_location',
        'person',
        'resident',
        'worker',
        'session',
        'user',
        'client',
        'location',
        'position',
        'coordinates',
        'gps',
        'lat',
        'latitude',
        'lng',
        'lon',
        'longitude',
        'speed',
        'speed_kph',
        'speed_kn',
        'heading',
        'heading_deg',
        'course',
        'accuracy',
        'accuracy_m',
        'hdop',
        'altitude',
        'altitude_m',
        'last_location_at',
    ];

    /**
     * Privacy-blocked vendor frames are untrusted. Persist only the small
     * operational envelope required to identify the hardware/alarm and support
     * diagnostics; arbitrary context and metadata are deliberately discarded.
     */
    private const PRIVACY_SAFE_VENDOR_PAYLOAD_KEYS = [
        'imei',
        'device_uid',
        'serial_number',
        'message_id',
        'msg_id',
        'gps_time',
        'time',
        'timestamp',
        'received_at',
        'occurred_at',
        'alarm',
        'event',
        'event_type',
        'sos_flag',
        'tamper_flag',
        'command_word',
        'battery',
        'battery_pct',
        'battery_level',
        'battery_voltage_mv',
        'battery_low_threshold',
        'charging_status',
        'power_event',
        'external_power',
        'ignition',
        'motion',
        'movement',
        'heartbeat',
        'odometer',
        'odometer_km',
        'protocol',
        'sequence',
        'sequence_number',
        'vendor',
        'event_id',
    ];

    public function __construct(
        protected AdapterRegistry $adapters,
        protected FleetGeofenceService $geofences,
        protected FleetSignalService $signals,
        protected FleetTripService $trips,
        protected FleetDrivingMetricsService $metrics,
        protected FleetDeviceRuntimeService $deviceRuntime,
        protected UserSiteAccessService $siteAccess,
    ) {}

    public function ingest(string $vendor, array $payload, ?int $expectedCanonicalDeviceId = null): array
    {
        $adapter = $this->adapters->adapterFor($vendor);
        $normalized = $adapter->normalize($payload);

        if (! $normalized['device_uid']) {
            return ['ok' => false, 'error' => 'device_uid missing', 'status' => 422];
        }

        $device = $this->deviceRuntime->resolveCanonicalDevice($vendor, $normalized);
        if (! $device) {
            return ['ok' => false, 'error' => 'canonical device not found', 'status' => 404];
        }
        if ($expectedCanonicalDeviceId !== null
            && (int) $device->id !== $expectedCanonicalDeviceId) {
            return ['ok' => false, 'error' => 'provider device binding does not match canonical identity', 'status' => 409];
        }

        $assetLinks = $device->activeAssetLinks()
            ->with('asset')
            ->orderBy('id')
            ->limit(2)
            ->get();
        if ($assetLinks->count() !== 1 || ! $assetLinks->first()->asset) {
            return ['ok' => false, 'error' => 'canonical device asset link unavailable or ambiguous', 'status' => 409];
        }

        $assetLink = $assetLinks->first();
        $asset = $assetLink->asset;
        $tracker = $this->compatibleHistoricalTracker($device, $asset, $vendor, $normalized);

        $idempotencyKey = $this->buildIdempotencyKey($vendor, $normalized, $payload);
        $expectedAssetId = (int) $asset->id;
        $expectedAssetLinkId = (int) $assetLink->id;
        $expectedTrackerId = $tracker ? (int) $tracker->id : null;
        $expectedDeviceId = (int) $device->id;

        return $this->withinIngestTransaction(function () use ($expectedAssetId, $expectedAssetLinkId, $expectedTrackerId, $expectedDeviceId, $vendor, $normalized, $payload, $idempotencyKey) {
            $existing = FleetTelemetryEvent::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return ['ok' => true, 'id' => $existing->id, 'duplicate' => true];
            }

            // Shared worker order: idempotency event -> DeviceAssignment history ->
            // Device -> DeviceAssetLink -> Asset -> optional historical AssetTracker -> LoneWorkerSession -> User -> session
            // Client -> Shift -> distinct shift Client -> resolved Site. Resident-only
            // routing deliberately keeps its separate Asset -> Site -> Client path.
            $staffAssignment = null;
            $staffAttributionAttempted = false;
            $clientAssignment = null;
            $clientAttributionAttempted = false;
            if (! empty($normalized['sos_flag'])) {
                $assignments = DeviceAssignment::query()
                    ->where('device_id', $expectedDeviceId)
                    ->whereIn('assignable_type', [
                        DeviceAssignment::TARGET_STAFF,
                        DeviceAssignment::TARGET_CLIENT,
                    ])
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $staffAssignments = $assignments
                    ->where('assignable_type', DeviceAssignment::TARGET_STAFF);
                $staffAttributionAttempted = $staffAssignments->isNotEmpty();
                $staffAssignment = $staffAssignments
                    ->whereNull('released_at')
                    ->last();

                $clientAssignments = $assignments
                    ->where('assignable_type', DeviceAssignment::TARGET_CLIENT);
                $clientAttributionAttempted = $clientAssignments->isNotEmpty();
                $clientAssignment = $clientAssignments
                    ->whereNull('released_at')
                    ->last();
            }

            $device = Device::query()
                ->whereKey($expectedDeviceId)
                ->lockForUpdate()
                ->first();
            $assetLink = DeviceAssetLink::query()
                ->whereKey($expectedAssetLinkId)
                ->lockForUpdate()
                ->first();
            $asset = Asset::query()
                ->whereKey($expectedAssetId)
                ->lockForUpdate()
                ->first();

            if (! $this->lockedCanonicalLineageMatches($device, $assetLink, $asset, $vendor, $normalized)) {
                return [
                    'ok' => false,
                    'error' => 'Canonical telemetry binding changed; retry with the current pairing.',
                    'status' => 409,
                ];
            }

            $tracker = $expectedTrackerId === null
                ? null
                : AssetTracker::query()->whereKey($expectedTrackerId)->lockForUpdate()->first();
            if ($tracker && ! $this->historicalTrackerMatches($tracker, $asset, $vendor, $normalized)) {
                $tracker = null;
            }

            $loneWorkerRoute = null;
            $personAttributionAttempted = false;
            $residentClient = null;
            $assetSite = null;
            if (! empty($normalized['sos_flag'])) {
                [$candidateWorkerId, $staffIntent] = $this->resolveLoneWorkerCandidateId(
                    $staffAssignment,
                    $staffAttributionAttempted,
                    $asset,
                );

                if ($staffIntent) {
                    $personAttributionAttempted = true;

                    if ($candidateWorkerId !== null && $device !== null) {
                        $workerResolution = $this->resolveLockedLoneWorkerRoute(
                            $candidateWorkerId,
                            $device,
                            $assetLink,
                            $asset,
                        );
                        $assetSite = $workerResolution['site'];
                        $loneWorkerRoute = $workerResolution['route'];

                    }
                } elseif ($this->isResidentSafetyTracker($asset)) {
                    $personAttributionAttempted = true;
                    $assetSite = $this->lockAuthoritativeAssetSite($asset);

                    $residentClient = $this->resolveLockedResidentClient(
                        $clientAssignment,
                        $clientAttributionAttempted,
                        $asset,
                        $device,
                        $assetSite,
                    );

                    if ($residentClient === null) {
                        // An apparent resident alarm with incomplete or contradictory
                        // provenance remains fleet-visible, but carries no canonical
                        // device or person context into persisted downstream records.
                        $device = null;
                    }
                } else {
                    $assetSite = $this->lockAuthoritativeAssetSite($asset);
                }
            } else {
                $assetSite = $this->lockAuthoritativeAssetSite($asset);
            }

            $personAttributionSucceeded = $loneWorkerRoute !== null || $residentClient !== null;
            $failedPersonAttribution = $personAttributionAttempted && ! $personAttributionSucceeded;
            $consentBlocked = $this->consentBlockedForLockedLineage($device, $asset);
            $privacyBlocked = $consentBlocked || $failedPersonAttribution;
            $persistedRawPayload = $normalized['raw_payload'] ?? $payload;
            if ($privacyBlocked) {
                $persistedRawPayload = $this->sanitizePrivacyBlockedVendorPayload($persistedRawPayload);
            }

            $occurredAt = $normalized['occurred_at'] ?? now();

            $event = FleetTelemetryEvent::create([
                'asset_id' => $asset->id,
                'asset_tracker_id' => $tracker?->id,
                'device_id' => $device?->id,
                'vendor' => $vendor,
                'vendor_message_id' => $normalized['vendor_message_id'] ?? null,
                'occurred_at' => $occurredAt,
                'received_at' => now(),
                'latitude' => $privacyBlocked ? null : $normalized['latitude'],
                'longitude' => $privacyBlocked ? null : $normalized['longitude'],
                'accuracy_m' => $privacyBlocked ? null : $normalized['accuracy_m'],
                'speed_kph' => $privacyBlocked ? null : $normalized['speed_kph'],
                'heading_deg' => $privacyBlocked ? null : $normalized['heading_deg'],
                'altitude_m' => $privacyBlocked ? null : $normalized['altitude_m'],
                'ignition' => $normalized['ignition'],
                'motion_status' => $normalized['motion_status'],
                'battery_pct' => $normalized['battery_pct'],
                'external_power' => $normalized['external_power'],
                'odometer_km' => $normalized['odometer_km'],
                'event_type' => $normalized['event_type'],
                'idempotency_key' => $idempotencyKey,
                'raw_payload' => $persistedRawPayload,
                'consent_blocked' => $privacyBlocked,
            ]);

            $telemetrySnapshot = AssetTelemetrySnapshot::updateOrCreate(
                ['vendor_payload_hash' => $idempotencyKey],
                [
                    'asset_id' => $asset->id,
                    'asset_tracker_id' => $tracker?->id,
                    'device_id' => $device?->id,
                    'occurred_at' => $occurredAt,
                    'received_at' => now(),
                    'latitude' => $privacyBlocked ? null : $normalized['latitude'],
                    'longitude' => $privacyBlocked ? null : $normalized['longitude'],
                    'accuracy_m' => $privacyBlocked ? null : $normalized['accuracy_m'],
                    'speed_kph' => $privacyBlocked ? null : $normalized['speed_kph'],
                    'movement_status' => $normalized['motion_status'],
                    'battery_pct' => $normalized['battery_pct'],
                    'power_source' => $normalized['external_power'] ? 'external' : ($normalized['battery_pct'] !== null ? 'battery' : null),
                    'tamper_flag' => (bool) ($normalized['tamper_flag'] ?? false),
                    'sos_flag' => (bool) ($normalized['sos_flag'] ?? false),
                    'vendor_payload_hash' => $idempotencyKey,
                    'vendor_metadata' => $persistedRawPayload,
                    'consent_blocked' => $privacyBlocked,
                ]
            );

            if ($device) {
                $deviceUpdates = [
                    'last_seen_at' => now(),
                ];

                $meta = $device->meta ?? [];
                $raw = $persistedRawPayload;

                // Populate model/name from the frame's device_name slot if
                // the canonical device doesn't have one yet. Fallback to an
                // IMEI-prefix hint so GL30-series pendants render correctly
                // before the operator sets the name on the device.
                $frameName = trim((string) ($raw['device_name'] ?? ''));
                if ($frameName !== '') {
                    if (empty($device->model)) {
                        $deviceUpdates['model'] = $frameName;
                    }
                    if (empty($device->name)) {
                        $deviceUpdates['name'] = $frameName;
                    }
                } elseif (empty($device->model) && ! empty($device->imei)) {
                    $hint = $this->modelHintFromImei((string) $device->imei);
                    if ($hint !== null) {
                        $deviceUpdates['model'] = $hint;
                    }
                }

                if ($normalized['battery_pct'] !== null) {
                    $deviceUpdates['battery_level'] = $normalized['battery_pct'];
                    $deviceUpdates['battery_updated_at'] = now();

                    $meta['battery'] = $normalized['battery_pct'];
                    $meta['battery_level'] = $normalized['battery_pct'];
                }

                foreach (['charging_status', 'battery_voltage_mv', 'power_event', 'external_power'] as $key) {
                    if (array_key_exists($key, $raw) && $raw[$key] !== null) {
                        $meta[$key] = $raw[$key];
                    }
                }

                $threshold = (int) ($meta['battery_low_threshold'] ?? data_get($raw, 'battery_low_threshold', 20));
                $meta['battery_status'] = $normalized['battery_pct'] === null
                    ? ($meta['battery_status'] ?? 'unknown')
                    : ((int) $normalized['battery_pct'] <= $threshold ? 'low' : 'normal');

                $safetyEvent = $this->normalisedSafetyEvent($normalized);
                if ($safetyEvent !== null) {
                    $meta['last_safety_event'] = $safetyEvent;
                    $meta['last_safety_event_at'] = $occurredAt instanceof Carbon
                        ? $occurredAt->toISOString()
                        : now()->toISOString();
                    $meta['panic_active'] = true;
                } elseif (! array_key_exists('panic_active', $meta)) {
                    $meta['panic_active'] = false;
                }

                if (($meta['charging_status'] ?? null) === 'charging' || ($meta['external_power'] ?? false)) {
                    $meta['battery_status_label'] = 'Charging';
                } elseif ($normalized['battery_pct'] !== null) {
                    unset($meta['battery_status_label']);
                }

                if (! $privacyBlocked && $normalized['latitude'] !== null && $normalized['longitude'] !== null) {
                    $deviceUpdates['latitude'] = $normalized['latitude'];
                    $deviceUpdates['longitude'] = $normalized['longitude'];
                    $deviceUpdates['last_signal_at'] = $occurredAt ?? now();

                    $meta['lat'] = $normalized['latitude'];
                    $meta['latitude'] = $normalized['latitude'];
                    $meta['lng'] = $normalized['longitude'];
                    $meta['longitude'] = $normalized['longitude'];
                    $meta['speed'] = $normalized['speed_kph'];
                    $meta['heading'] = $normalized['heading_deg'];
                    $meta['accuracy'] = $normalized['accuracy_m'];
                    $meta['altitude'] = $normalized['altitude_m'];
                    $meta['motion'] = $normalized['motion_status'];
                    $meta['last_location_at'] = $occurredAt instanceof Carbon
                        ? $occurredAt->toISOString()
                        : now()->toISOString();
                } elseif ($privacyBlocked) {
                    // Consent blocked: never retain or surface coordinates on the
                    // canonical device — null the columns and strip location meta
                    // so a stale position can't leak through device surfaces.
                    $deviceUpdates['latitude'] = null;
                    $deviceUpdates['longitude'] = null;

                    foreach (['lat', 'latitude', 'lng', 'longitude', 'speed', 'heading', 'accuracy', 'altitude', 'last_location_at'] as $locationKey) {
                        unset($meta[$locationKey]);
                    }
                }

                $deviceUpdates['meta'] = $meta;

                $device->forceFill($deviceUpdates)->save();
            }

            $state = FleetVehicleStateSnapshot::query()
                ->firstOrNew(['asset_id' => $asset->id]);

            $previousEvent = $state->last_event_id
                ? FleetTelemetryEvent::query()->find($state->last_event_id)
                : null;

            $state->fill([
                'last_event_id' => $event->id,
                'last_seen_at' => now(),
                'latitude' => $privacyBlocked ? null : $normalized['latitude'],
                'longitude' => $privacyBlocked ? null : $normalized['longitude'],
                'speed_kph' => $privacyBlocked ? null : $normalized['speed_kph'],
                'heading_deg' => $privacyBlocked ? null : $normalized['heading_deg'],
                'ignition' => $normalized['ignition'],
                'motion_status' => $normalized['motion_status'],
                'battery_pct' => $normalized['battery_pct'],
                'status' => 'online',
                'consent_blocked' => $privacyBlocked,
            ]);

            $state->save();

            $vehicleSignal = null;
            if (! empty($normalized['sos_flag'])) {
                $vehicleSignal = $this->signals->emit([
                    'asset_id' => $asset->id,
                    'asset_tracker_id' => $tracker?->id,
                    'device_id' => $device?->id,
                    'signal_type' => 'vehicle.sos',
                    'severity_hint' => 'critical',
                    'occurred_at' => $occurredAt,
                    'idempotency_key' => "fleet-telemetry:{$event->id}:vehicle.sos",
                    'payload' => [
                        'event_id' => $event->id,
                        'vendor' => $vendor,
                        'command_word' => data_get($normalized, 'raw_payload.command_word'),
                        'privacy_blocked' => $privacyBlocked,
                    ],
                ]);

                $finalRoute = $this->revalidateSosRouteState(
                    $loneWorkerRoute,
                    $residentClient,
                    $device,
                    $assetLink,
                    $asset,
                    $vendor,
                    $normalized,
                );
                $finalDevice = $finalRoute['device'];
                $lateIdentityFailure = $finalRoute['identity_failed'];
                $privacyBlocked = $privacyBlocked
                    || $finalRoute['consent_blocked']
                    || $lateIdentityFailure;

                if ($privacyBlocked) {
                    $persistedRawPayload = $this->sanitizePrivacyBlockedVendorPayload($persistedRawPayload);
                    $this->failClosePersistedPersonAttribution(
                        $event,
                        $telemetrySnapshot,
                        $state,
                        $device,
                        $persistedRawPayload,
                        $vehicleSignal,
                        clearDeviceIdentity: $device !== null && $finalDevice === null,
                    );
                }

                $device = $finalDevice;

                // A staff-paired (lone worker) tracker routes a panic / man-down into the
                // Lone Worker Safety emergency pipeline INSTEAD of the resident path —
                // isResidentSafetyTracker() also matches a staff personal_tracker, so this
                // branch must take precedence to avoid mislabelling a worker SOS as resident.
                if ($loneWorkerRoute !== null) {
                    $freshSession = $finalRoute['lone_worker_session'];

                    // Consent-blocked frames must not write coordinates anywhere,
                    // including the lone-worker session location snapshot.
                    $panicFrame = $privacyBlocked
                        ? array_merge($normalized, ['latitude' => null, 'longitude' => null])
                        : $normalized;

                    if ($freshSession !== null) {
                        $this->routeLoneWorkerPanic($freshSession, $panicFrame, $privacyBlocked);
                    }
                } elseif ($residentClient !== null && $finalRoute['resident_client'] !== null) {
                    $residentSignal = $this->signals->emit([
                        'asset_id' => $asset->id,
                        'asset_tracker_id' => $tracker?->id,
                        'device_id' => $device?->id,
                        'signal_type' => 'resident.sos',
                        'severity_hint' => 'critical',
                        'occurred_at' => $occurredAt,
                        'idempotency_key' => "fleet-telemetry:{$event->id}:resident.sos",
                        'payload' => [
                            'event_id' => $event->id,
                            'vendor' => $vendor,
                            'command_word' => data_get($normalized, 'raw_payload.command_word'),
                            'event_type' => $normalized['event_type'] ?? null,
                            'privacy_blocked' => $privacyBlocked,
                        ],
                    ]);

                    if ($privacyBlocked) {
                        $residentSignal->forceFill([
                            'payload' => $this->sanitizeDerivedPrivacyPayload($residentSignal->payload ?? []),
                        ])->save();
                        $this->failCloseControlRoomSignalContext($residentSignal, false);
                    }
                }
            }

            // Trips and driver metrics are location-derived records. They must run
            // only after the final post-signal assignment/device/consent decision.
            if (! $privacyBlocked) {
                $this->trips->handleTelemetry($event, $state, $previousEvent);
                $this->metrics->handleTelemetry($event, $previousEvent, $state);
                $state->save();
            }

            // Broadcast only after the final person-route freshness check so a
            // rejected route cannot leak a position through the realtime channel.
            if (! $privacyBlocked && $normalized['latitude'] !== null) {
                broadcast(new FleetVehiclePositionUpdated(
                    assetId: $asset->id,
                    latitude: (float) $normalized['latitude'],
                    longitude: (float) $normalized['longitude'],
                    speed_kph: $normalized['speed_kph'] ? (float) $normalized['speed_kph'] : null,
                    heading_deg: $normalized['heading_deg'] ? (int) $normalized['heading_deg'] : null,
                    status: 'online',
                    motion_status: $normalized['motion_status'],
                ))->toOthers();
            }

            if (! empty($normalized['tamper_flag'])) {
                $this->signals->emit([
                    'asset_id' => $asset->id,
                    'asset_tracker_id' => $tracker?->id,
                    'device_id' => $device?->id,
                    'signal_type' => 'device.tamper',
                    'severity_hint' => 'high',
                    'occurred_at' => $occurredAt,
                    'payload' => [
                        'event_id' => $event->id,
                        'vendor' => $vendor,
                        'privacy_blocked' => $privacyBlocked,
                    ],
                ]);
            }

            if (($normalized['event_type'] ?? null) === 'battery_low') {
                $this->signals->emit([
                    'asset_id' => $asset->id,
                    'asset_tracker_id' => $tracker?->id,
                    'device_id' => $device?->id,
                    'signal_type' => 'device.low_battery',
                    'severity_hint' => 'warning',
                    'occurred_at' => $occurredAt,
                    'idempotency_key' => "fleet-telemetry:{$event->id}:device.low_battery",
                    'payload' => [
                        'event_id' => $event->id,
                        'vendor' => $vendor,
                        'battery_pct' => $normalized['battery_pct'],
                        'command_word' => data_get($normalized, 'raw_payload.command_word'),
                        'privacy_blocked' => $privacyBlocked,
                    ],
                ]);
            }

            if (! $privacyBlocked && $normalized['latitude'] !== null && $normalized['longitude'] !== null) {
                $this->geofences->evaluate(
                    $asset,
                    (float) $normalized['latitude'],
                    (float) $normalized['longitude'],
                    $occurredAt
                );
            }

            if (
                config('fleet.maps.reverse_geocode_enabled')
                && ! $privacyBlocked
                && $normalized['latitude'] !== null
                && $normalized['longitude'] !== null
            ) {
                ReverseGeocodeFleetTelemetryEvent::dispatch($event->id)->afterCommit();
            }

            return ['ok' => true, 'id' => $event->id];
        });
    }

    protected function withinIngestTransaction(Closure $callback): mixed
    {
        return DB::transaction($callback, 3);
    }

    // Vehicle trackers on fleet-owned (non-client) assets have legitimate basis
    // under the employment relationship and don't require per-person consent.
    // Client-linked or non-vehicle assets remain default-deny (Privacy Act 2020).
    protected function isFleetOwnedVehicle(Asset $asset): bool
    {
        if ($asset->client_id) {
            return false;
        }

        return $this->assetMatchesCategory($asset, 'vehicle');
    }

    protected function isResidentSafetyTracker(Asset $asset): bool
    {
        if ($asset->client_id) {
            return true;
        }

        return $this->assetMatchesCategory($asset, 'personal_tracker');
    }

    /**
     * Resolve only the candidate identifier at this stage. Any TARGET_STAFF
     * history is durable person intent: a released, reassigned, or otherwise
     * invalid staff assignment must suppress both primary-driver and resident
     * fallback rather than silently changing who receives the emergency.
     *
     * @return array{0: ?int, 1: bool}
     */
    protected function resolveLoneWorkerCandidateId(
        ?DeviceAssignment $assignment,
        bool $hasStaffAttributionHistory,
        Asset $asset,
    ): array {
        if ($assignment !== null) {
            return [$this->positiveId($assignment->assignable_id), true];
        }

        if ($hasStaffAttributionHistory) {
            return [null, true];
        }

        if (
            ! $asset->client_id
            && $this->positiveId($asset->primary_driver_user_id) !== null
            && $this->assetMatchesCategory($asset, 'personal_tracker')
        ) {
            return [$this->positiveId($asset->primary_driver_user_id), true];
        }

        return [null, false];
    }

    /**
     * Worker routing follows the H&S lock order exactly: candidate Session first,
     * then post-session User re-fetch, session Client, Shift, distinct Shift Client,
     * and finally the resolved Site. Nothing person-attributed is mutated or emitted
     * until the complete locked device/link/asset/site/user/session tuple agrees.
     *
     * @return array{route: ?array{worker: User, session: LoneWorkerSession, site: Site}, site: ?Site}
     */
    protected function resolveLockedLoneWorkerRoute(
        int $candidateUserId,
        Device $device,
        DeviceAssetLink $assetLink,
        Asset $asset,
    ): array {
        $session = LoneWorkerSession::query()
            ->where('user_id', $candidateUserId)
            ->whereIn('status', ['active', 'overdue', 'emergency'])
            ->latest('started_at')
            ->lockForUpdate()
            ->first();

        if (! $session) {
            return ['route' => null, 'site' => null];
        }

        $worker = User::query()
            ->staff()
            ->whereNotNull('approved_at')
            ->whereKey($candidateUserId)
            ->lockForUpdate()
            ->first();
        $provenance = $this->lockSessionProvenance($session, $asset);
        $site = $provenance['site'];

        if (! $worker
            || ! $site
            || ! $this->sessionMatchesRoutingTuple(
                $session,
                $worker,
                $site,
                $provenance,
                $device,
                $assetLink,
                $asset,
            )) {
            return ['route' => null, 'site' => $site];
        }

        return [
            'route' => compact('worker', 'session', 'site'),
            'site' => $site,
        ];
    }

    /**
     * Resolve resident attribution only from a complete locked device/link/
     * asset/site/client tuple. Assignment history without a current exact client
     * binding is intentionally fail-closed.
     */
    protected function resolveLockedResidentClient(
        ?DeviceAssignment $assignment,
        bool $clientAttributionAttempted,
        Asset $asset,
        ?Device $device,
        ?Site $site,
    ): ?Client {
        $assetClientId = $this->positiveId($asset->client_id);
        $assignedClientId = $this->positiveId($assignment?->assignable_id);

        if (! $clientAttributionAttempted
            || ! $assignment
            || ! $device
            || ! $site
            || $assetClientId === null
            || $assignedClientId !== $assetClientId) {
            return null;
        }

        $client = Client::query()
            ->whereKey($assetClientId)
            ->lockForUpdate()
            ->first();
        if (! $client
            || $this->positiveId($client->site_id) !== (int) $site->id) {
            return null;
        }

        return $client;
    }

    /**
     * Route a tracker panic / man-down to the Lone Worker Safety emergency pipeline.
     * The base vehicle.sos has already been persisted. A person-attributed emergency
     * is added only when the locked session's worker and complete site provenance
     * match the same locked Device/Site tuple.
     */
    protected function routeLoneWorkerPanic(
        LoneWorkerSession $session,
        array $normalized,
        bool $privacyBlocked = false,
    ): void {
        $eventType = $normalized['event_type'] ?? 'sos';
        $notes = 'Tracker '.str_replace('_', ' ', (string) $eventType).' alarm';

        if ($privacyBlocked
            && ($session->location_lat !== null || $session->location_lng !== null)) {
            $session->forceFill([
                'location_lat' => null,
                'location_lng' => null,
            ])->save();
        }

        // Idempotency: emitEmergency dedups the alert in a 15-min window; only the
        // status write would otherwise repeat per inbound frame.
        if ($session->status !== 'emergency') {
            $session->update([
                'status' => 'emergency',
                'emergency_triggered_at' => now(),
                'emergency_notes' => $notes,
                'location_lat' => $privacyBlocked
                    ? null
                    : ($normalized['latitude'] ?? $session->location_lat),
                'location_lng' => $privacyBlocked
                    ? null
                    : ($normalized['longitude'] ?? $session->location_lng),
            ]);
        }

        app(LoneWorkerSignalService::class)->emitEmergency($session, $notes);
    }

    /**
     * A sync fleet-signal listener runs before the person alarm is emitted and
     * may change rows on this same transaction connection. Re-read the complete
     * device/link/asset/assignment/site/consent tuple without adding locks,
     * and never silently reroute an accepted person alarm to another person.
     *
     * @param  null|array{worker: User, session: LoneWorkerSession, site: Site}  $loneWorkerRoute
     * @return array{
     *     device: ?Device,
     *     lone_worker_session: ?LoneWorkerSession,
     *     resident_client: ?Client,
     *     consent_blocked: bool,
     *     identity_failed: bool
     * }
     */
    protected function revalidateSosRouteState(
        ?array $loneWorkerRoute,
        ?Client $residentClient,
        ?Device $device,
        DeviceAssetLink $assetLink,
        Asset $asset,
        string $vendor,
        array $normalized,
    ): array {
        $personRouteInitiallyAccepted = $loneWorkerRoute !== null || $residentClient !== null;
        $freshAssetLink = DeviceAssetLink::query()->find($assetLink->id);
        $freshAsset = Asset::query()->find($asset->id);
        $freshDevice = $device === null
            ? null
            : Device::query()->find($device->id);
        $lineageValid = $this->lockedCanonicalLineageMatches(
            $freshDevice,
            $freshAssetLink,
            $freshAsset,
            $vendor,
            $normalized,
        );
        $freshSite = $lineageValid
            ? $this->readAuthoritativeAssetSite($freshAsset)
            : null;

        $canonicalDevice = $lineageValid ? $freshDevice : null;
        $assignments = $freshDevice === null
            ? collect()
            : DeviceAssignment::query()
                ->where('device_id', $freshDevice->id)
                ->whereIn('assignable_type', [
                    DeviceAssignment::TARGET_STAFF,
                    DeviceAssignment::TARGET_CLIENT,
                ])
                ->orderBy('id')
                ->get();
        $staffAssignments = $assignments
            ->where('assignable_type', DeviceAssignment::TARGET_STAFF);
        $clientAssignments = $assignments
            ->where('assignable_type', DeviceAssignment::TARGET_CLIENT);
        $activeStaffAssignment = $staffAssignments
            ->whereNull('released_at')
            ->last();
        $activeClientAssignment = $clientAssignments
            ->whereNull('released_at')
            ->last();

        $freshSession = null;
        $freshResidentClient = null;

        if ($loneWorkerRoute !== null && $canonicalDevice && $freshAssetLink && $freshAsset) {
            [$candidateWorkerId, $staffIntent] = $this->resolveLoneWorkerCandidateId(
                $activeStaffAssignment,
                $staffAssignments->isNotEmpty(),
                $freshAsset,
            );

            if ($staffIntent
                && $candidateWorkerId === (int) $loneWorkerRoute['worker']->id) {
                $freshSession = $this->revalidateLockedLoneWorkerRoute(
                    $loneWorkerRoute,
                    $canonicalDevice,
                    $freshAssetLink,
                    $freshAsset,
                );
            }
        } elseif ($residentClient !== null
            && $canonicalDevice
            && $freshAssetLink
            && $freshAsset
            && $staffAssignments->isEmpty()) {
            $candidateResident = $this->readResidentClientForRoute(
                $activeClientAssignment,
                $clientAssignments->isNotEmpty(),
                $freshAsset,
                $canonicalDevice,
                $freshSite,
            );

            if ($candidateResident !== null
                && (int) $candidateResident->id === (int) $residentClient->id) {
                $freshResidentClient = $candidateResident;
            }
        }

        $consentBlocked = false;
        if ($lineageValid && $freshAsset) {
            $consentBlocked = $this->consentBlockedForLockedLineage(
                $canonicalDevice,
                $freshAsset,
            );
        } elseif ($personRouteInitiallyAccepted) {
            $consentBlocked = true;
        }

        $personRouteFinallyAccepted = $freshSession !== null || $freshResidentClient !== null;

        return [
            'device' => $canonicalDevice,
            'lone_worker_session' => $freshSession,
            'resident_client' => $freshResidentClient,
            'consent_blocked' => $consentBlocked,
            'identity_failed' => $personRouteInitiallyAccepted && ! $personRouteFinallyAccepted,
        ];
    }

    protected function readResidentClientForRoute(
        ?DeviceAssignment $assignment,
        bool $clientAttributionAttempted,
        Asset $asset,
        Device $device,
        ?Site $site,
    ): ?Client {
        $assetClientId = $this->positiveId($asset->client_id);
        $assignedClientId = $this->positiveId($assignment?->assignable_id);

        if (! $clientAttributionAttempted
            || ! $assignment
            || ! $site
            || $assetClientId === null
            || $assignedClientId !== $assetClientId) {
            return null;
        }

        $client = Client::query()->find($assetClientId);

        return $client
            && $this->positiveId($client->site_id) === (int) $site->id
                ? $client
                : null;
    }

    protected function readAuthoritativeAssetSite(Asset $asset): ?Site
    {
        $siteId = $this->authoritativeAssetSiteId($asset);

        return $siteId === null ? null : Site::query()->find($siteId);
    }

    /**
     * A downstream fleet signal hook runs before the person emergency is emitted.
     * Locks prevent external writers, but an in-transaction hook can still update
     * the same rows on this connection. Re-read without taking any new locks and
     * reject the route if the complete tuple no longer matches.
     *
     * @param  array{worker: User, session: LoneWorkerSession, site: Site}  $route
     */
    protected function revalidateLockedLoneWorkerRoute(
        array $route,
        Device $device,
        DeviceAssetLink $assetLink,
        Asset $asset,
    ): ?LoneWorkerSession {
        $session = LoneWorkerSession::query()->find($route['session']->id);
        $worker = User::query()
            ->staff()
            ->whereNotNull('approved_at')
            ->find($route['worker']->id);
        if (! $session || ! $worker) {
            return null;
        }

        $provenance = $this->readSessionProvenance($session, $asset);
        $site = $provenance['site'];

        return $site
            && $this->sessionMatchesRoutingTuple(
                $session,
                $worker,
                $site,
                $provenance,
                $device,
                $assetLink,
                $asset,
            )
                ? $session
                : null;
    }

    protected function failClosePersistedPersonAttribution(
        FleetTelemetryEvent $event,
        AssetTelemetrySnapshot $snapshot,
        FleetVehicleStateSnapshot $state,
        ?Device $device,
        array $sanitizedPayload,
        ?FleetSignal $vehicleSignal = null,
        bool $clearDeviceIdentity = false,
    ): void {
        $eventUpdates = [
            'latitude' => null,
            'longitude' => null,
            'accuracy_m' => null,
            'speed_kph' => null,
            'heading_deg' => null,
            'altitude_m' => null,
            'raw_payload' => $sanitizedPayload,
            'consent_blocked' => true,
        ];
        if ($clearDeviceIdentity) {
            $eventUpdates['device_id'] = null;
        }
        $event->forceFill($eventUpdates)->save();

        $snapshotUpdates = [
            'latitude' => null,
            'longitude' => null,
            'accuracy_m' => null,
            'speed_kph' => null,
            'vendor_metadata' => $sanitizedPayload,
            'consent_blocked' => true,
        ];
        if ($clearDeviceIdentity) {
            $snapshotUpdates['device_id'] = null;
        }
        $snapshot->forceFill($snapshotUpdates)->save();

        $state->forceFill([
            'latitude' => null,
            'longitude' => null,
            'speed_kph' => null,
            'heading_deg' => null,
            'consent_blocked' => true,
        ])->save();

        if ($device !== null) {
            $meta = $device->meta ?? [];
            foreach (['lat', 'latitude', 'lng', 'longitude', 'speed', 'heading', 'accuracy', 'altitude', 'last_location_at'] as $locationKey) {
                unset($meta[$locationKey]);
            }

            $device->forceFill([
                'latitude' => null,
                'longitude' => null,
                'meta' => $meta,
            ])->save();
        }

        if ($vehicleSignal !== null) {
            $signalUpdates = [
                'payload' => array_merge(
                    $this->sanitizeDerivedPrivacyPayload($vehicleSignal->payload ?? []),
                    ['privacy_blocked' => true],
                ),
            ];
            if ($clearDeviceIdentity) {
                $signalUpdates['device_id'] = null;
            }
            $vehicleSignal->forceFill($signalUpdates)->save();

            $this->failCloseControlRoomSignalContext($vehicleSignal, $clearDeviceIdentity);
        }
    }

    protected function failCloseControlRoomSignalContext(
        FleetSignal $vehicleSignal,
        bool $clearDeviceIdentity,
    ): void {
        $controlSignals = ControlRoomSignal::query()
            ->where('external_ref', "fleet_signal_{$vehicleSignal->id}")
            ->get();

        foreach ($controlSignals as $controlSignal) {
            $sanitizedSignalPayload = $this->sanitizeDerivedPrivacyPayload($controlSignal->payload ?? []);
            $sanitizedSignalPayload['privacy_blocked'] = true;
            $sanitizedNormalizedData = $this->sanitizeDerivedPrivacyPayload($controlSignal->normalized_data ?? []);
            $sanitizedNormalizedData['privacy_blocked'] = true;
            $signalUpdates = [
                'client_id' => null,
                'payload' => $sanitizedSignalPayload,
                'normalized_data' => $sanitizedNormalizedData,
            ];
            if ($clearDeviceIdentity) {
                $signalUpdates['device_id'] = null;
            }
            $controlSignal->forceFill($signalUpdates)->save();

            $alertIds = collect([
                $controlSignal->alert_id,
                $controlSignal->correlated_alert_id,
            ])->filter()->map(fn ($id): int => (int) $id)->unique();

            ControlRoomAlert::query()
                ->where('fleet_signal_id', $vehicleSignal->id)
                ->pluck('id')
                ->each(fn ($id) => $alertIds->push((int) $id));

            ControlRoomAlert::query()
                ->whereIn('id', $alertIds->unique()->all())
                ->get()
                ->each(function (ControlRoomAlert $alert) use ($clearDeviceIdentity): void {
                    $context = $this->sanitizeDerivedPrivacyPayload($alert->context ?? []);
                    $context['privacy_blocked'] = true;
                    if (is_array($context['signal_payload'] ?? null)) {
                        $context['signal_payload']['privacy_blocked'] = true;
                    }
                    if (is_array($context['normalized_data'] ?? null)) {
                        $context['normalized_data']['privacy_blocked'] = true;
                    }
                    $alertUpdates = [
                        'client_id' => null,
                        'context' => $context,
                    ];
                    if ($clearDeviceIdentity) {
                        $alertUpdates['device_id'] = null;
                    }
                    $alert->forceFill($alertUpdates)->save();
                });
        }
    }

    protected function lockedCanonicalLineageMatches(
        ?Device $device,
        ?DeviceAssetLink $assetLink,
        ?Asset $asset,
        string $vendor,
        array $normalized,
    ): bool {
        if (! $device || ! $assetLink || ! $asset) {
            return false;
        }

        return (int) $assetLink->device_id === (int) $device->id
            && (int) $assetLink->asset_id === (int) $asset->id
            && $assetLink->unlinked_at === null
            && $this->lockedDeviceMatchesLineage($device, $vendor, $normalized);
    }

    /**
     * Carry an existing provider row only as an optional FK for historical
     * telemetry continuity. Device and Asset have already been resolved from
     * canonical identity and the active DeviceAssetLink; this lookup must never
     * select or veto ownership.
     */
    protected function compatibleHistoricalTracker(
        Device $device,
        Asset $asset,
        string $vendor,
        array $normalized,
    ): ?AssetTracker {
        $incomingIdentifier = trim((string) ($normalized['device_uid'] ?? ''));
        if ($incomingIdentifier === '') {
            return null;
        }

        $tracker = AssetTracker::query()
            ->where(function ($query) use ($device, $vendor, $incomingIdentifier): void {
                if ($device->legacy_asset_tracker_id !== null) {
                    $query->whereKey($device->legacy_asset_tracker_id)
                        ->orWhere(function ($providerIdentity) use ($vendor, $incomingIdentifier): void {
                            $providerIdentity->where('vendor', $vendor)
                                ->where('device_uid', $incomingIdentifier);
                        });

                    return;
                }

                $query->where('vendor', $vendor)
                    ->where('device_uid', $incomingIdentifier);
            })
            ->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [(int) ($device->legacy_asset_tracker_id ?? 0)])
            ->first();

        return $tracker && $this->historicalTrackerMatches($tracker, $asset, $vendor, $normalized)
            ? $tracker
            : null;
    }

    protected function historicalTrackerMatches(
        AssetTracker $tracker,
        Asset $asset,
        string $vendor,
        array $normalized,
    ): bool {
        return (int) $tracker->asset_id === (int) $asset->id
            && trim((string) $tracker->vendor) === trim($vendor)
            && trim((string) $tracker->device_uid) === trim((string) ($normalized['device_uid'] ?? ''));
    }

    protected function lockAuthoritativeAssetSite(Asset $asset): ?Site
    {
        $siteId = $this->authoritativeAssetSiteId($asset);

        return $siteId === null
            ? null
            : Site::query()->whereKey($siteId)->lockForUpdate()->first();
    }

    protected function lockedDeviceMatchesLineage(
        Device $device,
        string $vendor,
        array $normalized,
    ): bool {
        $incomingIdentifier = trim((string) ($normalized['device_uid'] ?? ''));
        $deviceIdentifiers = collect([
            $device->imei,
            $device->device_uid,
            $device->serial_number,
        ])->map(fn ($identifier): string => trim((string) $identifier))
            ->filter()
            ->contains(fn (string $identifier): bool => strcasecmp($identifier, $incomingIdentifier) === 0);

        return $device->domain === 'tracking'
            && trim((string) $device->provider) === trim($vendor)
            && $incomingIdentifier !== ''
            && $deviceIdentifiers;
    }

    /**
     * Re-evaluate consent only after the canonical device, link, and asset have
     * all been re-fetched under the ingest transaction. This deliberately avoids
     * capturing a pre-lock consent decision for person-linked telemetry.
     */
    protected function consentBlockedForLockedLineage(
        ?Device $device,
        Asset $asset,
    ): bool {
        $consentContext = $device
            ? $this->deviceRuntime->resolveConsentContext($device)
            : null;
        $consent = $consentContext['consent'] ?? null;

        return ! ($consent?->isValid() ?? false)
            && ! $this->isFleetOwnedVehicle($asset);
    }

    protected function sanitizePrivacyBlockedVendorPayload(array $payload): array
    {
        $sanitized = [];
        $allowedKeys = array_map(
            fn (string $key): string => $this->normalizePrivacyPayloadKey($key),
            self::PRIVACY_SAFE_VENDOR_PAYLOAD_KEYS,
        );

        foreach ($payload as $key => $value) {
            $normalizedKey = $this->normalizePrivacyPayloadKey((string) $key);
            if (! in_array($normalizedKey, $allowedKeys, true)) {
                continue;
            }

            if (is_array($value)) {
                $nested = $this->sanitizePrivacyBlockedVendorPayload($value);
                if ($nested !== []) {
                    $sanitized[$key] = $nested;
                }

                continue;
            }

            if (is_string($value)) {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    $nested = $this->sanitizePrivacyBlockedVendorPayload($decoded);
                    if ($nested !== []) {
                        $sanitized[$key] = json_encode($nested);
                    }

                    continue;
                }
            }

            $sanitized[$key] = $value;
        }

        return $sanitized;
    }

    /**
     * Application-generated signal/alert context is trusted structurally, so it
     * may retain fleet identifiers. Remove every person/location key using a
     * punctuation- and casing-insensitive comparison, including JSON strings.
     */
    protected function sanitizeDerivedPrivacyPayload(array $payload): array
    {
        $sanitized = [];
        $deniedKeys = array_map(
            fn (string $key): string => $this->normalizePrivacyPayloadKey($key),
            self::PRIVACY_PAYLOAD_DENYLIST,
        );

        foreach ($payload as $key => $value) {
            $normalizedKey = $this->normalizePrivacyPayloadKey((string) $key);
            if (in_array($normalizedKey, $deniedKeys, true)) {
                continue;
            }

            if ($normalizedKey === $this->normalizePrivacyPayloadKey('fleet_context')
                && is_array($value)) {
                $sanitized[$key] = $this->privacySafeFleetContext($value);

                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeDerivedPrivacyPayload($value);

                continue;
            }

            if (is_string($value)) {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    $sanitized[$key] = json_encode($this->sanitizeDerivedPrivacyPayload($decoded));

                    continue;
                }
            }

            $sanitized[$key] = $value;
        }

        return $sanitized;
    }

    protected function privacySafeFleetContext(array $context): array
    {
        $vehicle = $context['vehicle'] ?? null;
        if (! is_array($vehicle)) {
            return [];
        }

        return [
            'vehicle' => array_filter([
                'id' => $vehicle['id'] ?? null,
                'asset_tag' => $vehicle['asset_tag'] ?? null,
                'registration' => $vehicle['registration'] ?? null,
            ], fn ($value): bool => $value !== null && $value !== ''),
        ];
    }

    protected function normalizePrivacyPayloadKey(string $key): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $key));
    }

    /**
     * Lock session provenance in the same stable order used by H&S writes:
     * session (caller) -> Client -> Shift -> distinct Shift Client -> Site.
     *
     * @return array{client: ?Client, shift: ?Shift, shiftClient: ?Client, site: ?Site}
     */
    protected function lockSessionProvenance(LoneWorkerSession $session, Asset $asset): array
    {
        $clientId = $this->positiveId($session->client_id);
        $client = $clientId === null
            ? null
            : Client::query()->whereKey($clientId)->lockForUpdate()->first();

        $shiftId = $this->positiveId($session->shift_id);
        $shift = $shiftId === null
            ? null
            : Shift::query()->whereKey($shiftId)->lockForUpdate()->first();

        $shiftClientId = $this->positiveId($shift?->client_id);
        if ($shiftClientId === null) {
            $shiftClient = null;
        } elseif ($client !== null && (int) $client->id === $shiftClientId) {
            $shiftClient = $client;
        } else {
            $shiftClient = Client::query()
                ->whereKey($shiftClientId)
                ->lockForUpdate()
                ->first();
        }

        $siteId = $this->authoritativeAssetSiteId($asset)
            ?? $this->positiveId($session->site_id)
            ?? $this->positiveId($client?->site_id)
            ?? $this->positiveId($shift?->site_id)
            ?? $this->positiveId($shiftClient?->site_id);
        $site = $siteId === null
            ? null
            : Site::query()->whereKey($siteId)->lockForUpdate()->first();

        return compact('client', 'shift', 'shiftClient', 'site');
    }

    /**
     * @return array{client: ?Client, shift: ?Shift, shiftClient: ?Client, site: ?Site}
     */
    protected function readSessionProvenance(LoneWorkerSession $session, Asset $asset): array
    {
        $clientId = $this->positiveId($session->client_id);
        $client = $clientId === null ? null : Client::query()->find($clientId);

        $shiftId = $this->positiveId($session->shift_id);
        $shift = $shiftId === null ? null : Shift::query()->find($shiftId);

        $shiftClientId = $this->positiveId($shift?->client_id);
        if ($shiftClientId === null) {
            $shiftClient = null;
        } elseif ($client !== null && (int) $client->id === $shiftClientId) {
            $shiftClient = $client;
        } else {
            $shiftClient = Client::query()->find($shiftClientId);
        }

        $siteId = $this->authoritativeAssetSiteId($asset)
            ?? $this->positiveId($session->site_id)
            ?? $this->positiveId($client?->site_id)
            ?? $this->positiveId($shift?->site_id)
            ?? $this->positiveId($shiftClient?->site_id);
        $site = $siteId === null ? null : Site::query()->find($siteId);

        return compact('client', 'shift', 'shiftClient', 'site');
    }

    /**
     * @param  array{client: ?Client, shift: ?Shift, shiftClient: ?Client, site: ?Site}  $provenance
     */
    protected function sessionMatchesRoutingTuple(
        LoneWorkerSession $session,
        User $worker,
        Site $site,
        array $provenance,
        Device $device,
        DeviceAssetLink $assetLink,
        Asset $asset,
    ): bool {
        $assetSiteId = $this->authoritativeAssetSiteId($asset);
        if ($assetSiteId === null
            || $asset->client_id !== null
            || (int) $assetLink->device_id !== (int) $device->id
            || (int) $assetLink->asset_id !== (int) $asset->id
            || $assetLink->unlinked_at !== null
            || (int) $session->user_id !== (int) $worker->id
            || (int) $site->id !== $assetSiteId
            || ! in_array($assetSiteId, $this->siteAccess->accessibleSiteIds($worker), true)
            || ! in_array($session->status, ['active', 'overdue', 'emergency'], true)) {
            return false;
        }

        $sessionClient = $provenance['client'];
        $shift = $provenance['shift'];
        $shiftClient = $provenance['shiftClient'];
        $resolvedSite = $provenance['site'];

        $sessionSiteId = $this->positiveId($session->site_id);
        if ($sessionSiteId !== null
            && (! $resolvedSite
                || (int) $resolvedSite->id !== $sessionSiteId)) {
            return false;
        }

        $clientSiteId = null;
        if ($session->client_id !== null) {
            $clientSiteId = $this->positiveId($sessionClient?->site_id);
            if (! $sessionClient
                || (int) $sessionClient->id !== $this->positiveId($session->client_id)
                || $clientSiteId === null) {
                return false;
            }
        }

        $shiftSiteId = null;
        $shiftClientSiteId = null;
        if ($session->shift_id !== null) {
            if (! $shift
                || (int) $shift->id !== $this->positiveId($session->shift_id)
                || $this->positiveId($shift->user_id) !== (int) $worker->id
                || ! $this->nullableIdMatches($shift->client_id, $this->positiveId($session->client_id))) {
                return false;
            }

            if ($shift->client_id !== null) {
                $shiftClientSiteId = $this->positiveId($shiftClient?->site_id);
                if (! $shiftClient
                    || (int) $shiftClient->id !== $this->positiveId($shift->client_id)
                    || $shiftClientSiteId === null) {
                    return false;
                }
            }

            $directShiftSiteId = $this->positiveId($shift->site_id);
            if ($directShiftSiteId !== null
                && $shiftClientSiteId !== null
                && $directShiftSiteId !== $shiftClientSiteId) {
                return false;
            }

            $shiftSiteId = $directShiftSiteId ?? $shiftClientSiteId;
            if ($shiftSiteId === null) {
                return false;
            }
        }

        $siteIds = collect([
            $sessionSiteId,
            $clientSiteId,
            $shiftSiteId,
            $shiftClientSiteId,
        ])->filter()->unique()->values();

        return $resolvedSite !== null
            && $siteIds->count() === 1
            && (int) $siteIds->first() === (int) $resolvedSite->id
            && (int) $resolvedSite->id === (int) $site->id;
    }

    protected function assetMatchesCategory(Asset $asset, string $category): bool
    {
        if ($asset->category === $category) {
            return true;
        }

        return $asset->categoryRef()->where('slug', $category)->exists();
    }

    protected function authoritativeAssetSiteId(Asset $asset): ?int
    {
        return $this->positiveId($asset->site_id)
            ?? $this->positiveId($asset->home_site_id);
    }

    protected function positiveId(mixed $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);

        return $id !== false && $id > 0 ? $id : null;
    }

    protected function nullableIdMatches(mixed $left, ?int $right): bool
    {
        if ($left === null) {
            return $right === null;
        }

        return $this->positiveId($left) === $right;
    }

    protected function modelHintFromImei(string $imei): ?string
    {
        $hints = (array) config('queclink.imei_model_hints', [
            '86796306' => 'GL30MEU',
            '86110605' => 'GL30MEU',
            '86469606' => 'GV500CG',
        ]);

        $prefix = substr($imei, 0, 8);

        return $hints[$prefix] ?? null;
    }

    protected function normalisedSafetyEvent(array $normalized): ?string
    {
        if (empty($normalized['sos_flag'])) {
            return null;
        }

        $eventType = $normalized['event_type'] ?? null;

        return $eventType === 'man_down' ? 'man_down' : 'vehicle_sos';
    }

    protected function buildIdempotencyKey(string $vendor, array $normalized, array $payload): string
    {
        $occurredAt = $normalized['occurred_at'];
        if ($occurredAt instanceof Carbon) {
            $occurredAt = $occurredAt->toISOString();
        }

        $base = implode('|', [
            $vendor,
            $normalized['device_uid'] ?? '',
            $normalized['vendor_message_id'] ?? '',
            $normalized['event_type'] ?? '',
            $occurredAt ?? '',
            $normalized['latitude'] ?? '',
            $normalized['longitude'] ?? '',
            json_encode($payload),
        ]);

        return hash('sha256', $base);
    }

    // geofence debounce moved into FleetGeofenceService
}
