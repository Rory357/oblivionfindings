<?php

namespace App\Domain\SecurityDevices\Management\Adapters;

use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\SecurityDevices\Credentials\Services\CommandCredentialLeaseService;
use App\Domain\SecurityDevices\Management\Contracts\CommandExecutionAdapter;
use App\Domain\SecurityDevices\Management\Data\CommandExecutionContext;
use App\Domain\SecurityDevices\Management\Data\CommandExecutionResult;
use App\Domain\SecurityDevices\Management\Data\CommandObservedState;
use App\Domain\SecurityDevices\Management\Enums\CommandAttemptStatus;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandAttempt;
use App\Domain\SecurityDevices\Management\Models\DeviceConfigurationProfile;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\FleetTelemetryEvent;
use App\Models\Queclink\QueclinkDevice;
use App\Models\Queclink\QueclinkPendingCommand;
use App\Services\Queclink\CommandBuilder;
use App\Services\Queclink\ConfigurationSnapshotService;
use App\Services\Queclink\QueclinkConfigurationProfileService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class QueclinkTrackingCommandAdapter implements CommandExecutionAdapter
{
    private const LOCATION_REFRESH = 'tracking.location_refresh';

    private const CONFIGURATION_REFRESH = 'configuration.refresh';

    private const DEVICE_REBOOT = 'device.reboot';

    private const CONFIGURATION_APPLY = 'configuration.apply';

    private const SUPPORTED_CAPABILITIES = [
        self::LOCATION_REFRESH,
        self::CONFIGURATION_REFRESH,
        self::DEVICE_REBOOT,
        self::CONFIGURATION_APPLY,
    ];

    private const PROVIDER = 'queclink';

    public function __construct(
        private readonly CanonicalDeviceSiteResolver $sites,
        private readonly CommandCredentialLeaseService $credentials,
        private readonly CommandBuilder $commands,
        private readonly ConfigurationSnapshotService $configurations,
        private readonly QueclinkConfigurationProfileService $configurationProfiles,
    ) {}

    public function supports(Device $device, string $capability): bool
    {
        if (! in_array($capability, self::SUPPORTED_CAPABILITIES, true)
            || strtolower(trim((string) $device->provider)) !== self::PROVIDER
            || $device->domain !== 'tracking') {
            return false;
        }

        try {
            $this->pairedDevice($device);
            $siteId = $this->sites->resolve((int) $device->id);

            return $this->credentials->available($device, $siteId, $capability);
        } catch (Throwable) {
            return false;
        }
    }

    public function execute(CommandExecutionContext $context): CommandExecutionResult
    {
        $queclinkDevice = $this->assertContext($context);
        $attempt = DeviceCommandAttempt::query()
            ->with('request')
            ->where('attempt_uuid', $context->attemptUuid)
            ->firstOrFail();
        $this->assertExecutionIdentity($attempt, $context);
        $pending = QueclinkPendingCommand::query()
            ->where('device_command_attempt_id', $attempt->id)
            ->first();
        if ($pending !== null) {
            return $this->acceptedResult($context);
        }
        if ($this->hasActiveProviderCommand($queclinkDevice)) {
            return $this->busyResult();
        }

        $lease = $this->credentials->acquire($context);
        $material = [];
        $built = [];
        try {
            $material = $lease->material();
            $password = $this->password($material);
            $family = $this->familyFor($queclinkDevice, $context->device);
            $built = match ($context->capability) {
                self::LOCATION_REFRESH => [[
                    ...$this->commands->requestLocation($family, $password),
                    'role' => 'action',
                ]],
                self::CONFIGURATION_REFRESH => [[
                    ...$this->commands->readConfiguration(
                        $family,
                        (string) ($context->parameters['section'] ?? 'all'),
                        $password,
                    ),
                    'role' => 'verification',
                ]],
                self::DEVICE_REBOOT => [[
                    ...$this->commands->reboot($family, $password),
                    'role' => 'action',
                ]],
                self::CONFIGURATION_APPLY => $this->configurationProfiles->buildGovernedSequence(
                    $this->configurationProfile($context),
                    $family,
                    $password,
                ),
                default => throw new RuntimeException('The Queclink command capability is unsupported.'),
            };

            $queued = DB::transaction(function () use ($context, $queclinkDevice, $built): string {
                QueclinkDevice::query()->whereKey($queclinkDevice->id)->lockForUpdate()->firstOrFail();
                $attempt = DeviceCommandAttempt::query()
                    ->with('request')
                    ->where('attempt_uuid', $context->attemptUuid)
                    ->lockForUpdate()
                    ->firstOrFail();
                $this->assertExecutionIdentity($attempt, $context);
                $request = $attempt->request;

                $pending = QueclinkPendingCommand::query()
                    ->where('device_command_attempt_id', $attempt->id)
                    ->lockForUpdate()
                    ->first();
                if ($pending !== null) {
                    return 'existing';
                }
                if ($this->hasActiveProviderCommand($queclinkDevice)) {
                    return 'busy';
                }
                foreach ($built as $position => $providerCommand) {
                    QueclinkPendingCommand::query()->create([
                        'queclink_device_id' => $queclinkDevice->id,
                        'imei' => $queclinkDevice->imei,
                        'command_word' => $providerCommand['command_word'],
                        'raw_command' => $providerCommand['raw'],
                        'serial_number' => $providerCommand['serial'],
                        'status' => QueclinkPendingCommand::STATUS_QUEUED,
                        'created_by_user_id' => $request->requested_by_user_id,
                        'device_command_request_id' => $request->id,
                        'device_command_attempt_id' => $attempt->id,
                        'governed_sequence' => $position + 1,
                        'governed_role' => $providerCommand['role'],
                        'expires_at' => $context->expiresAt,
                    ]);
                }

                return 'created';
            });

            if ($queued === 'busy') {
                return $this->busyResult();
            }

            return $this->acceptedResult($context);
        } finally {
            $this->erase($material);
            foreach ($built as &$providerCommand) {
                if (isset($providerCommand['raw']) && is_string($providerCommand['raw']) && $providerCommand['raw'] !== '') {
                    sodium_memzero($providerCommand['raw']);
                }
            }
            unset($providerCommand);
            $this->credentials->release($lease);
        }
    }

    public function observe(CommandExecutionContext $context): CommandObservedState
    {
        $this->assertContext($context);
        if ($context->capability === self::CONFIGURATION_REFRESH) {
            return $this->observeConfiguration($context);
        }
        if ($context->capability === self::DEVICE_REBOOT) {
            return $this->observeReboot($context);
        }
        if ($context->capability === self::CONFIGURATION_APPLY) {
            return $this->observeConfigurationApply($context);
        }

        $attempt = DeviceCommandAttempt::query()
            ->where('attempt_uuid', $context->attemptUuid)
            ->firstOrFail();
        $pending = QueclinkPendingCommand::query()
            ->where('device_command_attempt_id', $attempt->id)
            ->first();
        if (! $pending || $pending->fulfilled_telemetry_event_id === null || $pending->fulfilled_at === null) {
            throw new RuntimeException('A fresh Queclink location observation is not yet available.');
        }

        $event = FleetTelemetryEvent::query()
            ->whereKey($pending->fulfilled_telemetry_event_id)
            ->where('device_id', $context->device->id)
            ->where('vendor', self::PROVIDER)
            ->where('consent_blocked', false)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->first();
        if (! $event
            || $pending->sent_at === null
            || $event->received_at === null
            || $event->received_at->lt($pending->sent_at)
            || $pending->fulfilled_at->lt($event->received_at)) {
            throw new RuntimeException('The Queclink location evidence does not match the governed delivery window.');
        }

        return new CommandObservedState(
            state: ['action_completed' => true],
            observedAt: CarbonImmutable::instance($event->received_at),
            observationReference: 'fleet-telemetry:'.$event->id,
            safeEvidenceSummary: 'A fresh governed Queclink location observation was received after the tracker request. Location values remain in the privacy-controlled Tracking surfaces.',
        );
    }

    private function assertContext(CommandExecutionContext $context): QueclinkDevice
    {
        if (! in_array($context->capability, self::SUPPORTED_CAPABILITIES, true)
            || strtolower(trim((string) $context->device->provider)) !== self::PROVIDER
            || $context->device->domain !== 'tracking'
            || $this->sites->resolve((int) $context->device->id) !== $context->siteId
            || $context->expiresAt->isPast()) {
            throw new RuntimeException('The Queclink command scope is invalid or expired.');
        }

        return $this->pairedDevice($context->device);
    }

    private function pairedDevice(Device $device): QueclinkDevice
    {
        $queclinkDevice = QueclinkDevice::query()
            ->where('device_id', $device->id)
            ->where('status', QueclinkDevice::STATUS_PAIRED)
            ->latest('id')
            ->first();
        if (! $queclinkDevice) {
            throw new RuntimeException('The canonical Device is not paired to Queclink native intake.');
        }

        $identifiers = collect([$device->imei, $device->device_uid])
            ->filter(fn (mixed $identifier): bool => is_string($identifier) && trim($identifier) !== '')
            ->map(fn (string $identifier): string => trim($identifier));
        if (! $identifiers->contains(fn (string $identifier): bool => hash_equals($queclinkDevice->imei, $identifier))) {
            throw new RuntimeException('The Queclink provider identity does not match the canonical Device.');
        }

        return $queclinkDevice;
    }

    private function assertExecutionIdentity(DeviceCommandAttempt $attempt, CommandExecutionContext $context): void
    {
        $request = $attempt->request;
        if ((int) $request->device_id !== (int) $context->device->id
            || (int) $request->site_id !== $context->siteId
            || ! hash_equals($request->command_uuid, $context->commandUuid)
            || $request->capability !== $context->capability) {
            throw new RuntimeException('The Queclink execution identity does not match the governed request.');
        }
    }

    private function acceptedResult(CommandExecutionContext $context): CommandExecutionResult
    {
        $configuration = $context->capability === self::CONFIGURATION_REFRESH;
        $configurationApply = $context->capability === self::CONFIGURATION_APPLY;
        $reboot = $context->capability === self::DEVICE_REBOOT;

        return new CommandExecutionResult(
            status: CommandAttemptStatus::Accepted,
            safeSummary: [
                'delivery_state' => 'queued_for_tracker',
                'reconciliation' => $configuration
                    ? 'fresh_protected_configuration_required'
                    : ($configurationApply
                        ? 'sequential_acknowledgements_and_exact_configuration_required'
                        : ($reboot ? 'fresh_reconnection_required' : 'fresh_governed_location_required')),
            ],
            providerRequestReference: 'queclink-native:'.$context->commandUuid,
        );
    }

    private function busyResult(): CommandExecutionResult
    {
        return new CommandExecutionResult(
            status: CommandAttemptStatus::Failed,
            safeFailureReason: 'Another tracker command is still active. Wait for its governed result before trying again.',
        );
    }

    private function hasActiveProviderCommand(QueclinkDevice $device): bool
    {
        return QueclinkPendingCommand::query()
            ->where('queclink_device_id', $device->id)
            ->whereIn('status', [
                QueclinkPendingCommand::STATUS_QUEUED,
                QueclinkPendingCommand::STATUS_SENT,
                QueclinkPendingCommand::STATUS_ACKED,
            ])
            ->where(fn ($query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', CarbonImmutable::now('UTC')))
            ->exists();
    }

    private function observeConfiguration(CommandExecutionContext $context): CommandObservedState
    {
        $attempt = DeviceCommandAttempt::query()
            ->where('attempt_uuid', $context->attemptUuid)
            ->firstOrFail();
        $pending = QueclinkPendingCommand::query()
            ->where('device_command_attempt_id', $attempt->id)
            ->where('governed_role', 'verification')
            ->first();
        if (! $pending || $pending->fulfilled_raw_frame_id === null || $pending->fulfilled_at === null) {
            throw new RuntimeException('A fresh protected Queclink configuration observation is not yet available.');
        }
        $frame = $pending->fulfilledRawFrame;
        if (! $frame
            || (int) $frame->queclink_device_id !== (int) $pending->queclink_device_id
            || $pending->sent_at === null
            || $frame->created_at === null
            || $frame->created_at->lt($pending->sent_at)) {
            throw new RuntimeException('The Queclink configuration evidence does not match the governed delivery window.');
        }
        $providerDevice = $this->pairedDevice($context->device);
        $snapshot = $this->configurations->latestForDevice($providerDevice);
        $receivedAt = is_string($snapshot['received_at'] ?? null)
            ? CarbonImmutable::parse($snapshot['received_at'])
            : null;
        $section = strtoupper((string) ($context->parameters['section'] ?? 'all'));
        if (($snapshot['available'] ?? false) !== true
            || $receivedAt === null
            || $receivedAt->lt($pending->sent_at)
            || ($section !== 'ALL' && ! array_key_exists($section, (array) ($snapshot['sections'] ?? [])))) {
            throw new RuntimeException('The protected Queclink configuration snapshot is incomplete or does not match the request.');
        }

        return new CommandObservedState(
            state: ['action_completed' => true],
            observedAt: $receivedAt,
            observationReference: 'queclink-frame:'.$frame->id,
            safeEvidenceSummary: 'A fresh protected Queclink configuration snapshot was received after the governed request. Configuration contents remain encrypted and are not included in routine evidence.',
        );
    }

    private function observeReboot(CommandExecutionContext $context): CommandObservedState
    {
        $attempt = DeviceCommandAttempt::query()
            ->where('attempt_uuid', $context->attemptUuid)
            ->firstOrFail();
        $pending = QueclinkPendingCommand::query()
            ->where('device_command_attempt_id', $attempt->id)
            ->first();
        if (! $pending
            || $pending->fulfilled_raw_frame_id === null
            || $pending->fulfilled_at === null
            || ! is_string($pending->sent_session_id)
            || $pending->sent_session_id === '') {
            throw new RuntimeException('A fresh Queclink reconnection is not yet available.');
        }
        $frame = $pending->fulfilledRawFrame;
        if (! $frame
            || (int) $frame->queclink_device_id !== (int) $pending->queclink_device_id
            || $frame->direction !== 'inbound'
            || ! $frame->parse_ok
            || $frame->session_id === null
            || hash_equals($pending->sent_session_id, (string) $frame->session_id)
            || $pending->sent_at === null
            || $frame->created_at === null
            || $frame->created_at->lt($pending->sent_at)) {
            throw new RuntimeException('The Queclink reconnection evidence does not match the governed restart window.');
        }

        return new CommandObservedState(
            state: ['availability' => 'online'],
            observedAt: CarbonImmutable::instance($frame->created_at),
            observationReference: 'queclink-frame:'.$frame->id,
            safeEvidenceSummary: 'The paired Queclink tracker established a new listener session after the governed restart request.',
        );
    }

    private function observeConfigurationApply(CommandExecutionContext $context): CommandObservedState
    {
        $attempt = DeviceCommandAttempt::query()
            ->where('attempt_uuid', $context->attemptUuid)
            ->firstOrFail();
        $verification = QueclinkPendingCommand::query()
            ->where('device_command_attempt_id', $attempt->id)
            ->where('governed_role', 'verification')
            ->first();
        if (! $verification || $verification->fulfilled_raw_frame_id === null || $verification->fulfilled_at === null) {
            throw new RuntimeException('A fresh protected post-change configuration observation is not yet available.');
        }
        $frame = $verification->fulfilledRawFrame;
        if (! $frame
            || $verification->sent_at === null
            || $frame->created_at === null
            || $frame->created_at->lt($verification->sent_at)) {
            throw new RuntimeException('The post-change configuration evidence does not match the governed delivery window.');
        }

        $profile = $this->configurationProfile($context);
        $providerDevice = $this->pairedDevice($context->device);
        $snapshot = $this->configurations->latestForDevice($providerDevice);
        $receivedAt = is_string($snapshot['received_at'] ?? null)
            ? CarbonImmutable::parse($snapshot['received_at'])
            : null;
        if ($receivedAt === null || $receivedAt->lt($verification->sent_at)) {
            throw new RuntimeException('The protected configuration observation is not current for this apply request.');
        }
        $matches = $this->configurationProfiles->matches($profile, $snapshot);

        return new CommandObservedState(
            state: [
                'configuration_profile_uuid' => $profile->uuid,
                'configuration_payload_hash' => $matches ? $profile->payload_hash : 'configuration-mismatch',
            ],
            observedAt: $receivedAt,
            observationReference: 'queclink-frame:'.$frame->id,
            safeEvidenceSummary: $matches
                ? 'A protected post-change read matched every declared field in the approved immutable configuration profile.'
                : 'A protected post-change read completed, but one or more declared profile fields did not match. Do not retry until the actual state is reviewed.',
        );
    }

    private function configurationProfile(CommandExecutionContext $context): DeviceConfigurationProfile
    {
        return $this->configurationProfiles->assertCompatible(
            $context->device,
            (int) ($context->parameters['configuration_profile_id'] ?? 0),
        );
    }

    /** @param array<string, scalar|null> $material */
    private function password(#[\SensitiveParameter] array $material): string
    {
        $password = $material['command_password'] ?? null;
        if (! is_string($password)
            || preg_match('/^[A-Za-z0-9._-]{1,20}$/', $password) !== 1) {
            throw new RuntimeException('The governed Queclink command credential is invalid.');
        }

        return $password;
    }

    /** @param array<string, scalar|null> $material */
    private function erase(#[\SensitiveParameter] array &$material): void
    {
        foreach ($material as &$value) {
            if (is_string($value) && $value !== '') {
                sodium_memzero($value);
            }
            $value = null;
        }
        unset($value);
        $material = [];
    }

    private function familyFor(QueclinkDevice $queclinkDevice, Device $device): string
    {
        $hint = strtolower((string) ($queclinkDevice->model_hint ?: $device->model));
        if (str_contains($hint, 'gl30') || str_contains($hint, 'gl-30')) {
            return CommandBuilder::FAMILY_GL30M;
        }
        if (str_contains($hint, 'gv500')) {
            return CommandBuilder::FAMILY_GV500CG;
        }

        return in_array(strtolower((string) $device->category), [
            'personal_tracker',
            'lone_worker_tracker',
            'client_tracker',
        ], true)
            ? CommandBuilder::FAMILY_GL30M
            : CommandBuilder::FAMILY_GV500CG;
    }
}
