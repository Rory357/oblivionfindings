<?php

namespace App\Domain\SecurityDevices\Services;

use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\SecurityDevices\Credentials\Services\CommandCredentialLeaseService;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Client;
use App\Models\FleetTelemetryEvent;
use App\Models\Queclink\QueclinkDevice;
use App\Models\Queclink\QueclinkPendingCommand;
use App\Models\Queclink\QueclinkRawFrame;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Queclink\CommandBuilder;
use App\Services\Queclink\ConfigurationSnapshotService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;
use UnexpectedValueException;

/**
 * Per-unit Queclink @Track console projection and command dispatch.
 *
 * Official vendor protocol specifications implemented:
 *   - Queclink GL30MEU @Track Air Interface Protocol (GL30MEUR01 Rev 2.04 / TRACGL300AN001)
 *   - Queclink GV500CG @Track Air Interface Protocol (TRACGV500CGAN005 Rev 5.01)
 */
final class QueclinkUnitConsoleService
{
    /** @var list<string> */
    private const ALLOWED_SECTIONS = [
        'ALL', 'SRI', 'CFG', 'DOG', 'NMD', 'PIN', 'TMA', 'PDS', 'WFI', 'GEO', 'BTS', 'BID', 'WLT', 'UPC', 'FVR',
    ];

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly PersonalTrackingPrivacyService $privacy,
        private readonly CanonicalDeviceSiteResolver $siteResolver,
        private readonly CommandCredentialLeaseService $credentials,
        private readonly CommandBuilder $commands,
        private readonly ConfigurationSnapshotService $snapshots,
    ) {}

    public function supportsDevice(Device $device): bool
    {
        if (strtolower(trim((string) $device->provider)) === 'queclink') {
            return true;
        }

        return QueclinkDevice::query()
            ->where('device_id', $device->id)
            ->exists();
    }

    public function canViewConsole(User $viewer): bool
    {
        return $viewer->canDo('securityDevices.integrations.view')
            || $viewer->canDo('securityDevices.integrations.manage');
    }

    public function canSendSafeCommands(User $viewer): bool
    {
        return $this->canViewConsole($viewer)
            && (
                $viewer->canDo('securityDevices.commands.operate')
                || $viewer->canDo('securityDevices.integrations.manage')
            );
    }

    public function canSendRiskyCommands(User $viewer): bool
    {
        return $this->canViewConsole($viewer)
            && (
                $viewer->canDo('securityDevices.commands.control')
                || $viewer->canDo('securityDevices.commands.admin')
            );
    }

    /** @return array<string, mixed> */
    public function present(User $viewer, Device $device): array
    {
        $tracker = $this->resolveTracker($device);
        $family = $this->resolveFamily($tracker, $device);
        $locationAccess = $this->resolveLocationAccess($viewer, $device);
        $canSafe = $this->canSendSafeCommands($viewer);
        $canRisky = $this->canSendRiskyCommands($viewer);
        $trackerReady = $tracker !== null && $tracker->isPaired();

        return [
            'fetch_url' => "/security-devices/devices/{$device->id}/queclink-console",
            'command_url' => "/security-devices/devices/{$device->id}/queclink-console/commands",
            'protocol_spec' => $family === CommandBuilder::FAMILY_GL30M
                ? 'Queclink GL30MEU @Track Air Interface Protocol (GL30MEUR01 Rev 2.04)'
                : 'Queclink GV500CG @Track Air Interface Protocol (TRACGV500CGAN005 Rev 5.01)',
            'family' => $family,
            'unit' => $tracker ? [
                'id' => (int) $tracker->id,
                'imei_masked' => $this->maskImei($tracker->imei),
                'status' => $tracker->status,
                'connection_state' => $tracker->connection_state,
                'model_hint' => $tracker->model_hint ?: $device->model,
                'protocol_version' => $tracker->protocol_version,
                'firmware_version' => $tracker->firmware_version ?: $device->firmware_version,
                'first_seen_at' => $tracker->first_seen_at?->toISOString(),
                'last_seen_at' => $tracker->last_seen_at?->toISOString(),
                'last_frame_at' => $tracker->last_frame_at?->toISOString(),
            ] : null,
            'last_position' => $this->presentLastPosition($device, $tracker, $locationAccess),
            'settings' => $this->presentSettings($tracker, $device),
            'raw_messages' => $this->presentRawMessages($tracker, $device, $locationAccess),
            'recent_commands' => $this->presentPendingCommands($tracker),
            'commands' => [
                'tracker_ready' => $trackerReady,
                'unavailable_reason' => $trackerReady
                    ? null
                    : ($tracker === null
                        ? 'This device is not paired with a live Queclink TCP unit yet.'
                        : 'Pair this tracker before sending outbound AT commands.'),
                'can_send_safe' => $canSafe && $trackerReady,
                'can_send_risky' => $canRisky && $trackerReady,
                'supported' => $this->supportedCommandDefinitions($family, $canSafe && $trackerReady, $canRisky && $trackerReady),
                'sections' => self::ALLOWED_SECTIONS,
            ],
        ];
    }

    /**
     * Privacy-governed position summary for any tracking-domain device profile.
     *
     * @return array<string, mixed>
     */
    public function presentTrackerPosition(User $viewer, Device $device): array
    {
        $tracker = $this->resolveTracker($device);
        $locationAccess = $this->resolveLocationAccess($viewer, $device);

        return $this->presentLastPosition($device, $tracker, $locationAccess);
    }

    /** @param array<string, mixed> $parameters */
    public function sendCommand(
        User $actor,
        Device $device,
        string $commandKey,
        array $parameters = [],
        ?string $reason = null,
        bool $confirmed = false,
    ): QueclinkPendingCommand {
        $this->access->assertCanViewDevice($actor, $device);
        abort_unless($this->canViewConsole($actor), 403);

        $isRisky = in_array($commandKey, ['reboot', 'update_reporting_interval', 'update_watchdog'], true);
        $isSafe = in_array($commandKey, ['request_location', 'read_config', 'request_status'], true);

        if (! $isRisky && ! $isSafe) {
            throw ValidationException::withMessages([
                'command' => 'Choose a supported Queclink @Track command.',
            ]);
        }

        if ($isRisky) {
            abort_unless($this->canSendRiskyCommands($actor), 403);
            if (! $confirmed) {
                throw ValidationException::withMessages([
                    'confirmed' => 'Confirm this state-changing command before sending it to the tracker.',
                ]);
            }
            $cleanReason = trim((string) $reason);
            if (mb_strlen($cleanReason) < 4) {
                throw ValidationException::withMessages([
                    'reason' => 'Enter a brief reason (at least 4 characters) for the audit log.',
                ]);
            }
        } else {
            abort_unless($this->canSendSafeCommands($actor), 403);
            $cleanReason = trim((string) $reason) !== '' ? trim((string) $reason) : null;
        }

        $tracker = $this->resolveTracker($device);
        if (! $tracker || ! $tracker->isPaired()) {
            throw ValidationException::withMessages([
                'command' => 'This tracker is not paired with an active Queclink unit record yet.',
            ]);
        }

        $family = $this->resolveFamily($tracker, $device);
        $leaseCapability = match ($commandKey) {
            'request_location', 'request_status' => 'tracking.location_refresh',
            'read_config' => 'configuration.refresh',
            'reboot' => 'device.reboot',
            default => 'configuration.apply',
        };

        $lease = null;
        $material = [];
        $built = null;

        try {
            $siteId = $this->resolveSiteId($device);
            if ($siteId !== null && $this->credentials->available($device, $siteId, $leaseCapability)) {
                $lease = $this->credentials->acquireFor($device, $siteId, $leaseCapability);
                $material = $lease->material();
            }

            $password = $this->extractPassword($material);

            try {
                $built = match ($commandKey) {
                    'request_location' => $this->commands->requestLocation($family, $password),
                    'read_config' => $this->commands->readConfiguration(
                        $family,
                        $this->validatedSection($parameters['section'] ?? 'ALL'),
                        $password,
                    ),
                    'request_status' => $this->commands->requestDeviceInfo($family, $password),
                    'reboot' => $this->commands->reboot($family, $password),
                    'update_reporting_interval' => $this->commands->setReportingInterval(
                        $family,
                        $this->validatedIntervalSeconds($parameters['interval_seconds'] ?? null),
                        $password,
                    ),
                    'update_watchdog' => $this->commands->watchdog(
                        $family,
                        [
                            'mode' => $parameters['mode'] ?? 1,
                            'reboot_interval' => $parameters['reboot_interval'] ?? 7,
                            'reboot_time' => $parameters['reboot_time'] ?? '0200',
                            'report_before_reboot' => 1,
                        ],
                        $password,
                    ),
                };
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages([
                    'parameters' => $exception->getMessage(),
                ]);
            }

            $pending = DB::transaction(function () use ($actor, $device, $tracker, $commandKey, $cleanReason, $isRisky, $built): QueclinkPendingCommand {
                $lockedTracker = QueclinkDevice::query()
                    ->whereKey($tracker->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $created = QueclinkPendingCommand::query()->create([
                    'queclink_device_id' => $lockedTracker->id,
                    'imei' => $lockedTracker->imei,
                    'command_word' => $built['command_word'],
                    'raw_command' => $built['raw'],
                    'serial_number' => $built['serial'],
                    'status' => QueclinkPendingCommand::STATUS_QUEUED,
                    'created_by_user_id' => $actor->id,
                    'expires_at' => now()->addHours(24),
                ]);

                AuditLogger::logOrFail('queclink.console.command.queued', $device, [
                    'actor_id' => (int) $actor->id,
                    'device_id' => (int) $device->id,
                    'queclink_device_id' => (int) $lockedTracker->id,
                    'pending_command_id' => (int) $created->id,
                    'command_key' => $commandKey,
                    'command_word' => $built['command_word'],
                    'serial_number' => $built['serial'],
                    'risky' => $isRisky,
                    'reason' => $cleanReason,
                ]);

                return $created->fresh(['createdBy:id,name']);
            }, 3);

            return $pending;
        } finally {
            foreach ($material as &$secret) {
                if (is_string($secret) && $secret !== '' && function_exists('sodium_memzero')) {
                    sodium_memzero($secret);
                }
            }
            unset($secret);

            if (is_array($built) && isset($built['raw']) && is_string($built['raw']) && $built['raw'] !== '' && function_exists('sodium_memzero')) {
                sodium_memzero($built['raw']);
            }

            if ($lease !== null) {
                $this->credentials->release($lease);
            }
        }
    }

    public function resolveTracker(Device $device): ?QueclinkDevice
    {
        $byDevice = QueclinkDevice::query()
            ->where('device_id', $device->id)
            ->orderByDesc('id')
            ->first();

        if ($byDevice) {
            return $byDevice;
        }

        $imei = trim((string) ($device->imei ?? ''));
        if ($imei === '') {
            return null;
        }

        return QueclinkDevice::query()
            ->where('imei', $imei)
            ->orderByDesc('id')
            ->first();
    }

    private function resolveFamily(?QueclinkDevice $tracker, Device $device): string
    {
        $haystack = strtolower(implode(' ', array_filter([
            $tracker?->model_hint,
            $tracker?->protocol_version,
            $device->model,
            $device->category,
            $device->subcategory,
        ])));

        if (str_contains($haystack, 'gv500') || str_contains($haystack, 'vehicle')) {
            return CommandBuilder::FAMILY_GV500CG;
        }

        return CommandBuilder::FAMILY_GL30M;
    }

    private function resolveSiteId(Device $device): ?int
    {
        try {
            return $this->siteResolver->resolve((int) $device->id);
        } catch (UnexpectedValueException) {
            return null;
        }
    }

    /** @return array{allowed: bool, state: string, message: string, assignment_id: int|null} */
    private function resolveLocationAccess(User $viewer, Device $device): array
    {
        if (! $viewer->canDo('assets.telemetry.view')) {
            return [
                'allowed' => false,
                'state' => 'permission_required',
                'message' => 'Location telemetry permission is required to view coordinates.',
                'assignment_id' => null,
            ];
        }

        $assignment = $device->assignments()
            ->active()
            ->latest('assigned_at')
            ->latest('id')
            ->first();

        if (! $assignment) {
            return [
                'allowed' => false,
                'state' => 'unassigned',
                'message' => 'Assign this tracker before viewing live coordinates.',
                'assignment_id' => null,
            ];
        }

        if (! $this->access->canAccessCurrentAssignment($viewer, $assignment)) {
            return [
                'allowed' => false,
                'state' => 'restricted',
                'message' => 'Location details are restricted for this assignment.',
                'assignment_id' => (int) $assignment->id,
            ];
        }

        if ($assignment->assignable_type === DeviceAssignment::TARGET_CLIENT) {
            $client = Client::query()->find($assignment->assignable_id);
            if (! $client || ! $this->privacy->assignmentAuthorisesResidentLocation($assignment, $client)) {
                return [
                    'allowed' => false,
                    'state' => 'consent_required',
                    'message' => 'Active resident location consent is required before coordinates can be shown.',
                    'assignment_id' => (int) $assignment->id,
                ];
            }

            return [
                'allowed' => true,
                'state' => 'authorised',
                'message' => 'Location shown under active resident location consent.',
                'assignment_id' => (int) $assignment->id,
            ];
        }

        if ($assignment->assignable_type === DeviceAssignment::TARGET_VEHICLE) {
            if (! in_array((int) $assignment->assignable_id, $this->access->authorizedAssetIds($viewer), true)) {
                return [
                    'allowed' => false,
                    'state' => 'restricted',
                    'message' => 'Vehicle telemetry access is required to view coordinates.',
                    'assignment_id' => (int) $assignment->id,
                ];
            }

            return [
                'allowed' => true,
                'state' => 'authorised',
                'message' => 'Location shown for authorised fleet vehicle.',
                'assignment_id' => (int) $assignment->id,
            ];
        }

        if ($assignment->assignable_type === DeviceAssignment::TARGET_STAFF) {
            if (! $assignment->isCollectionActive()) {
                return [
                    'allowed' => false,
                    'state' => 'collection_paused',
                    'message' => 'Staff safety location collection is currently paused.',
                    'assignment_id' => (int) $assignment->id,
                ];
            }

            return [
                'allowed' => true,
                'state' => 'authorised',
                'message' => 'Location shown for active staff safety tracker.',
                'assignment_id' => (int) $assignment->id,
            ];
        }

        return [
            'allowed' => true,
            'state' => 'authorised',
            'message' => 'Location shown for authorised Site equipment.',
            'assignment_id' => (int) $assignment->id,
        ];
    }

    /**
     * @param  array{allowed: bool, state: string, message: string, assignment_id: int|null}  $locationAccess
     * @return array<string, mixed>
     */
    private function presentLastPosition(Device $device, ?QueclinkDevice $tracker, array $locationAccess): array
    {
        if (! $locationAccess['allowed']) {
            return [
                'state' => $locationAccess['state'],
                'message' => $locationAccess['message'],
                'latitude' => null,
                'longitude' => null,
                'accuracy_m' => null,
                'speed_kph' => null,
                'heading_deg' => null,
                'altitude_m' => null,
                'battery_pct' => $device->battery_level,
                'occurred_at' => null,
                'address' => null,
                'map_url' => null,
            ];
        }

        $activeAssignmentId = $locationAccess['assignment_id'];

        $event = FleetTelemetryEvent::query()
            ->where('device_id', $device->id)
            ->where('consent_blocked', false)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();

        if ($event) {
            $lat = (float) $event->latitude;
            $lng = (float) $event->longitude;

            return [
                'state' => 'available',
                'message' => $locationAccess['message'],
                'latitude' => round($lat, 6),
                'longitude' => round($lng, 6),
                'accuracy_m' => $event->accuracy_m !== null ? (int) $event->accuracy_m : null,
                'speed_kph' => $event->speed_kph !== null ? round((float) $event->speed_kph, 1) : null,
                'heading_deg' => $event->heading_deg !== null ? (int) $event->heading_deg : null,
                'altitude_m' => $event->altitude_m !== null ? (int) $event->altitude_m : null,
                'battery_pct' => $event->battery_pct !== null ? (int) $event->battery_pct : $device->battery_level,
                'occurred_at' => ($event->occurred_at ?? $event->received_at)?->toISOString(),
                'address' => $event->address,
                'map_url' => $this->openStreetMapUrl($lat, $lng),
            ];
        }

        if ($tracker) {
            $recentPositionFrames = QueclinkRawFrame::query()
                ->where('queclink_device_id', $tracker->id)
                ->where('direction', QueclinkRawFrame::DIRECTION_INBOUND)
                ->where('parse_ok', true)
                ->where(function ($scope) use ($activeAssignmentId): void {
                    $scope->whereNull('device_assignment_id');
                    if ($activeAssignmentId !== null) {
                        $scope->orWhere('device_assignment_id', $activeAssignmentId);
                    }
                })
                ->orderByDesc('id')
                ->limit(25)
                ->get();

            foreach ($recentPositionFrames as $frame) {
                try {
                    $payload = $frame->protectedParsedPayload();
                } catch (Throwable) {
                    continue;
                }
                if (! is_array($payload)) {
                    continue;
                }
                $lat = $payload['latitude'] ?? $payload['lat'] ?? null;
                $lng = $payload['longitude'] ?? $payload['lng'] ?? $payload['lon'] ?? null;
                if (! is_numeric($lat) || ! is_numeric($lng)) {
                    continue;
                }
                $latFloat = (float) $lat;
                $lngFloat = (float) $lng;
                if (abs($latFloat) < 0.000001 && abs($lngFloat) < 0.000001) {
                    continue;
                }

                return [
                    'state' => 'available',
                    'message' => $locationAccess['message'],
                    'latitude' => round($latFloat, 6),
                    'longitude' => round($lngFloat, 6),
                    'accuracy_m' => isset($payload['accuracy_m']) && is_numeric($payload['accuracy_m']) ? (int) $payload['accuracy_m'] : null,
                    'speed_kph' => isset($payload['speed_kph']) && is_numeric($payload['speed_kph']) ? round((float) $payload['speed_kph'], 1) : null,
                    'heading_deg' => isset($payload['heading_deg']) && is_numeric($payload['heading_deg']) ? (int) $payload['heading_deg'] : null,
                    'altitude_m' => isset($payload['altitude_m']) && is_numeric($payload['altitude_m']) ? (int) $payload['altitude_m'] : null,
                    'battery_pct' => isset($payload['battery_pct']) && is_numeric($payload['battery_pct']) ? (int) $payload['battery_pct'] : $device->battery_level,
                    'occurred_at' => $frame->created_at?->toISOString(),
                    'address' => null,
                    'map_url' => $this->openStreetMapUrl($latFloat, $lngFloat),
                ];
            }
        }

        return [
            'state' => 'no_fix_yet',
            'message' => 'No position report has been received from this unit yet.',
            'latitude' => null,
            'longitude' => null,
            'accuracy_m' => null,
            'speed_kph' => null,
            'heading_deg' => null,
            'altitude_m' => null,
            'battery_pct' => $device->battery_level,
            'occurred_at' => null,
            'address' => null,
            'map_url' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function presentSettings(?QueclinkDevice $tracker, Device $device): array
    {
        if (! $tracker) {
            return [
                'available' => false,
                'received_at' => null,
                'sections' => [],
                'summary' => [
                    'server' => null,
                    'global' => null,
                    'watchdog' => null,
                    'non_movement' => null,
                    'firmware' => [
                        'model' => $device->model,
                        'firmware_version' => $device->firmware_version,
                        'protocol_version' => null,
                    ],
                ],
            ];
        }

        $snapshot = $this->snapshots->latestForDevice($tracker);
        $summary = is_array($snapshot['summary'] ?? null) ? $snapshot['summary'] : [];

        return [
            'available' => (bool) ($snapshot['available'] ?? false),
            'received_at' => $snapshot['received_at'] ?? null,
            'sections' => array_values(array_keys($snapshot['sections'] ?? [])),
            'summary' => [
                'server' => $this->sanitiseSettingsSection($summary['server'] ?? null),
                'global' => $this->sanitiseSettingsSection($summary['global'] ?? null),
                'watchdog' => $this->sanitiseSettingsSection($summary['dog'] ?? null),
                'non_movement' => $this->sanitiseSettingsSection($summary['non_movement'] ?? null),
                'firmware' => [
                    'model' => $tracker->model_hint ?: $device->model,
                    'firmware_version' => $tracker->firmware_version ?: $device->firmware_version,
                    'protocol_version' => $tracker->protocol_version,
                    'configuration_version' => data_get($summary, 'firmware_version.configuration_version'),
                ],
            ],
        ];
    }

    /**
     * @param  array{allowed: bool, state: string, message: string, assignment_id: int|null}  $locationAccess
     * @return list<array<string, mixed>>
     */
    private function presentRawMessages(?QueclinkDevice $tracker, Device $device, array $locationAccess): array
    {
        $query = QueclinkRawFrame::query()
            ->where(function ($match) use ($tracker, $device): void {
                if ($tracker) {
                    $match->where('queclink_device_id', $tracker->id)
                        ->orWhere('canonical_device_id', $device->id);
                } else {
                    $match->where('canonical_device_id', $device->id);
                }
            })
            ->orderByDesc('id')
            ->limit(30);

        $activeAssignmentId = $locationAccess['assignment_id'];

        return $query->get()->map(function (QueclinkRawFrame $frame) use ($locationAccess, $activeAssignmentId): array {
            try {
                $rawText = (string) ($frame->raw_frame ?? '');
            } catch (Throwable) {
                $rawText = '[encrypted frame unavailable]';
            }

            $frameAllowsLocation = $locationAccess['allowed']
                && (
                    $frame->device_assignment_id === null
                    || ($activeAssignmentId !== null && (int) $frame->device_assignment_id === $activeAssignmentId)
                );

            return [
                'id' => (int) $frame->id,
                'direction' => $frame->direction,
                'frame_type' => $frame->frame_type,
                'command_word' => $frame->command_word,
                'parse_ok' => (bool) $frame->parse_ok,
                'parse_error' => $frame->parse_error,
                'raw_frame' => $this->redactRawFrame($rawText, (string) $frame->direction, $frame->command_word, $frameAllowsLocation),
                'created_at' => $frame->created_at?->toISOString(),
            ];
        })->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function presentPendingCommands(?QueclinkDevice $tracker): array
    {
        if (! $tracker) {
            return [];
        }

        return QueclinkPendingCommand::query()
            ->where('queclink_device_id', $tracker->id)
            ->with('createdBy:id,name')
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(function (QueclinkPendingCommand $cmd): array {
                try {
                    $raw = (string) ($cmd->raw_command ?? '');
                } catch (Throwable) {
                    $raw = '';
                }

                return [
                    'id' => (int) $cmd->id,
                    'command_word' => $cmd->command_word,
                    'serial_number' => $cmd->serial_number,
                    'status' => $cmd->status,
                    'raw_command' => $this->redactRawFrame($raw, QueclinkRawFrame::DIRECTION_OUTBOUND, $cmd->command_word, true),
                    'ack_response' => $cmd->ack_response ? $this->redactRawFrame((string) $cmd->ack_response, QueclinkRawFrame::DIRECTION_INBOUND, $cmd->command_word, true) : null,
                    'failed_reason' => $cmd->failed_reason,
                    'created_by' => $cmd->createdBy?->name,
                    'created_at' => $cmd->created_at?->toISOString(),
                    'sent_at' => $cmd->sent_at?->toISOString(),
                    'acked_at' => $cmd->acked_at?->toISOString(),
                    'fulfilled_at' => $cmd->fulfilled_at?->toISOString(),
                    'expires_at' => $cmd->expires_at?->toISOString(),
                ];
            })
            ->values()
            ->all();
    }

    public function redactRawFrame(
        string $raw,
        string $direction,
        ?string $commandWord,
        bool $allowLocation,
    ): string {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return '';
        }

        // Always redact AT+GTXXX=<password>,...$ outbound passwords and secret fields.
        if (preg_match('/^(AT\+GT[A-Z0-9]+=)([^,$]*)(.*)$/i', $trimmed, $matches) === 1) {
            $prefix = $matches[1];
            $rest = $matches[3];
            $upperPrefix = strtoupper($prefix);

            // For AT+GTCFG=<password>,<new_password>,... also redact <new_password> if non-empty.
            if ($upperPrefix === 'AT+GTCFG=' && str_starts_with($rest, ',')) {
                $parts = explode(',', substr($rest, 1));
                if (($parts[0] ?? '') !== '') {
                    $parts[0] = '[REDACTED]';
                }
                $rest = ','.implode(',', $parts);
            }

            // For AT+GTPIN=<password>,<auto_unlock>,<pin>,... redact <pin> if non-empty.
            if ($upperPrefix === 'AT+GTPIN=' && str_starts_with($rest, ',')) {
                $parts = explode(',', substr($rest, 1));
                if (($parts[1] ?? '') !== '') {
                    $parts[1] = '[REDACTED]';
                }
                $rest = ','.implode(',', $parts);
            }

            return $prefix.'[REDACTED]'.$rest;
        }

        // Redact IMEI middle digits in raw frames to match Queclink hub masking.
        $sanitised = preg_replace_callback(
            '/\b(\d{4})\d{7}(\d{4})\b/',
            fn (array $m): string => $m[1].'•••••••'.$m[2],
            $trimmed,
        ) ?? $trimmed;

        // Redact coordinates in inbound report/buffer frames when location access is not permitted.
        $upperWord = strtoupper((string) $commandWord);
        $isAckFrame = str_starts_with(strtoupper($sanitised), '+ACK:')
            || str_starts_with(strtoupper($sanitised), '+SACK:');

        if (! $allowLocation && $direction === QueclinkRawFrame::DIRECTION_INBOUND && ! $isAckFrame && $upperWord !== 'GTALM') {
            $sanitised = preg_replace('/-?\d{1,3}\.\d{4,8}/', '[LOCATION REDACTED]', $sanitised) ?? $sanitised;
        }

        return $sanitised;
    }

    /** @return list<array<string, mixed>> */
    private function supportedCommandDefinitions(string $family, bool $canSafe, bool $canRisky): array
    {
        $specShort = $family === CommandBuilder::FAMILY_GL30M
            ? 'GL30MEUR01 Rev 2.04'
            : 'TRACGV500CGAN005 Rev 5.01';

        return [
            [
                'key' => 'request_location',
                'label' => 'Request current location',
                'description' => 'Asks the unit for an immediate GNSS fix (+RESP:GTRTL).',
                'at_preview' => 'AT+GTRTO=[REDACTED],1,,,,,XXXX$',
                'spec_ref' => "{$specShort} AT+GTRTO sub-command 1 (GPS)",
                'risky' => false,
                'enabled' => $canSafe,
            ],
            [
                'key' => 'read_config',
                'label' => 'Read configuration',
                'description' => 'Reads active settings from the unit (+RESP:GTALM).',
                'at_preview' => 'AT+GTRTO=[REDACTED],2,<section>,,,,XXXX$',
                'spec_ref' => "{$specShort} AT+GTRTO sub-command 2 (READ)",
                'risky' => false,
                'enabled' => $canSafe,
            ],
            [
                'key' => 'request_status',
                'label' => 'Request device status',
                'description' => 'Requests battery, cellular signal, and hardware status (+RESP:GTINF).',
                'at_preview' => 'AT+GTRTO=[REDACTED],A,,,,,XXXX$',
                'spec_ref' => "{$specShort} AT+GTRTO sub-command A (INF)",
                'risky' => false,
                'enabled' => $canSafe,
            ],
            [
                'key' => 'update_reporting_interval',
                'label' => 'Update reporting interval',
                'description' => 'Changes how often the unit sends timed position reports (requires confirmation).',
                'at_preview' => $family === CommandBuilder::FAMILY_GL30M
                    ? 'AT+GTCFG=[REDACTED],...,<interval_s>,...,XXXX$'
                    : 'AT+GTFRI=[REDACTED],1,1,,1,0000,0000,0,<interval_s>,...,XXXX$',
                'spec_ref' => $family === CommandBuilder::FAMILY_GL30M
                    ? 'GL30MEUR01 Rev 2.04 §3.2.2.1 AT+GTCFG'
                    : 'TRACGV500CGAN005 Rev 5.01 §3.4.1 AT+GTFRI',
                'risky' => true,
                'enabled' => $canRisky,
            ],
            [
                'key' => 'update_watchdog',
                'label' => 'Update scheduled watchdog',
                'description' => 'Configures automatic periodic watchdog reboot (requires confirmation).',
                'at_preview' => 'AT+GTDOG=[REDACTED],<mode>,,<days>,<HHMM>,,1,...,XXXX$',
                'spec_ref' => $family === CommandBuilder::FAMILY_GL30M
                    ? 'GL30MEUR01 Rev 2.04 §3.2.2.3 AT+GTDOG'
                    : 'TRACGV500CGAN005 Rev 5.01 §3.2.5 AT+GTDOG',
                'risky' => true,
                'enabled' => $canRisky,
            ],
            [
                'key' => 'reboot',
                'label' => 'Reboot tracker',
                'description' => 'Restarts the unit over the air; drops active TCP session briefly (requires confirmation).',
                'at_preview' => 'AT+GTRTO=[REDACTED],3,,,,,XXXX$',
                'spec_ref' => "{$specShort} AT+GTRTO sub-command 3 (REBOOT)",
                'risky' => true,
                'enabled' => $canRisky,
            ],
        ];
    }

    /** @param mixed $section @return array<string, mixed>|null */
    private function sanitiseSettingsSection(mixed $section): ?array
    {
        if (! is_array($section)) {
            return null;
        }

        $clean = [];
        foreach ($section as $key => $value) {
            if (! is_string($key)) {
                continue;
            }
            if (preg_match('/password|secret|pin|token/i', $key) === 1) {
                $clean[$key] = $value !== null && $value !== '' ? '[REDACTED]' : null;

                continue;
            }
            $clean[$key] = $value;
        }

        return $clean;
    }

    /** @param array<string, mixed> $material */
    private function extractPassword(array $material): ?string
    {
        foreach (['password', 'command_password', 'device_password', 'secret'] as $key) {
            $candidate = $material[$key] ?? null;
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    private function validatedSection(mixed $raw): string
    {
        $section = strtoupper(trim((string) $raw));
        if ($section === '') {
            return 'ALL';
        }
        if (! in_array($section, self::ALLOWED_SECTIONS, true)) {
            throw new InvalidArgumentException('Select a supported 3-letter Queclink configuration section.');
        }

        return $section;
    }

    private function validatedIntervalSeconds(mixed $raw): int
    {
        if (! is_numeric($raw) || (int) $raw != $raw || (int) $raw < 10 || (int) $raw > 86400) {
            throw new InvalidArgumentException('Enter a reporting interval between 10 and 86,400 seconds.');
        }

        return (int) $raw;
    }

    private function maskImei(string $imei): string
    {
        $trimmed = trim($imei);
        if (strlen($trimmed) <= 8) {
            return $trimmed;
        }

        return substr($trimmed, 0, 4).'•••••••'.substr($trimmed, -4);
    }

    private function openStreetMapUrl(float $lat, float $lng): string
    {
        return sprintf(
            'https://www.openstreetmap.org/?mlat=%0.6f&mlon=%0.6f#map=16/%0.6f/%0.6f',
            $lat,
            $lng,
            $lat,
            $lng,
        );
    }
}
