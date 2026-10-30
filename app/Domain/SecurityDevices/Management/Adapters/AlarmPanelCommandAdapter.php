<?php

namespace App\Domain\SecurityDevices\Management\Adapters;

use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\SecurityDevices\Credentials\Services\CommandCredentialLeaseService;
use App\Domain\SecurityDevices\Drivers\Alarms\GallagherCommandCentreDriver;
use App\Domain\SecurityDevices\Drivers\Alarms\InnerRangeInceptionDriver;
use App\Domain\SecurityDevices\Drivers\Contracts\AlarmPanelDriver;
use App\Domain\SecurityDevices\Drivers\Services\DeviceDriverRegistry;
use App\Domain\SecurityDevices\Management\Contracts\CommandExecutionAdapter;
use App\Domain\SecurityDevices\Management\Data\CommandExecutionContext;
use App\Domain\SecurityDevices\Management\Data\CommandExecutionResult;
use App\Domain\SecurityDevices\Management\Data\CommandObservedState;
use App\Domain\SecurityDevices\Management\Enums\CommandAttemptStatus;
use App\Domain\SecurityDevices\Management\Services\DeclaredDeviceCommandCapabilities;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Models\ControlRoom\SignalSource;
use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

/**
 * Governed command execution adapter for `alarm.arm` and `alarm.disarm`.
 *
 * Enforces Site scope, declared capabilities, active `AlarmPanelDriver` resolution,
 * credential lease + `sodium_memzero`, and fresh area arm-state reconciliation.
 */
final class AlarmPanelCommandAdapter implements CommandExecutionAdapter
{
    public const array CAPABILITIES = [
        'alarm.arm',
        'alarm.disarm',
    ];

    public function __construct(
        private readonly CanonicalDeviceSiteResolver $sites,
        private readonly DeclaredDeviceCommandCapabilities $declaredCapabilities,
        private readonly DeviceDriverRegistry $drivers,
        private readonly CommandCredentialLeaseService $credentials,
    ) {}

    public function supports(Device $device, string $capability): bool
    {
        if (! in_array($capability, self::CAPABILITIES, true)
            || $device->domain !== 'security'
            || ! in_array($device->category, ['alarm', 'perimeter'], true)
            || ! $this->declaredCapabilities->supports($device, $capability)) {
            return false;
        }

        if (! $this->drivers->resolveAlarmDriver($device) instanceof AlarmPanelDriver) {
            return false;
        }

        try {
            $siteId = $this->sites->resolve((int) $device->id);

            return $siteId > 0 && $this->credentials->available($device, $siteId, $capability);
        } catch (Throwable) {
            return false;
        }
    }

    public function execute(CommandExecutionContext $context): CommandExecutionResult
    {
        $driver = $this->assertContext($context);
        $area = $this->validateArea((string) ($context->parameters['area'] ?? ''));

        $lease = $this->credentials->acquire($context);
        $material = [];

        try {
            $material = $lease->material();
            $token = $this->extractToken($material);

            if ($context->capability === 'alarm.arm') {
                $result = $this->dispatchArm($driver, $context->device, $area, $token);
            } else {
                $result = $this->dispatchDisarm($driver, $context->device, $area, $token);
            }

            if (! $result['ok']) {
                return new CommandExecutionResult(
                    status: CommandAttemptStatus::Failed,
                    safeFailureReason: $result['message'] !== ''
                        ? $result['message']
                        : 'The alarm panel rejected the requested area state change.',
                );
            }

            $occurredAt = CarbonImmutable::now('UTC')->startOfSecond();
            $this->recordPanelEvent(
                $context->device,
                $context->capability === 'alarm.arm' ? 'alarm_armed' : 'alarm_disarmed',
                $area,
                $driver->key(),
                $context->commandUuid,
                $occurredAt,
            );

            return new CommandExecutionResult(
                status: CommandAttemptStatus::Succeeded,
                safeSummary: [
                    'area' => $area,
                    'driver_key' => $driver->key(),
                    'provider_state' => 'accepted',
                ],
                providerRequestReference: $driver->key().':'.$context->commandUuid,
            );
        } finally {
            $this->erase($material);
            $this->credentials->release($lease);
        }
    }

    public function observe(CommandExecutionContext $context): CommandObservedState
    {
        $driver = $this->assertContext($context);
        $area = $this->validateArea((string) ($context->parameters['area'] ?? ''));

        $lease = $this->credentials->acquire($context);
        $material = [];

        try {
            $material = $lease->material();
            $token = $this->extractToken($material);
            $status = $this->dispatchStatus($driver, $context->device, $token);

            if (! $status['ok']) {
                throw new RuntimeException('Alarm panel area status could not be confirmed.');
            }

            $matchedArea = null;
            foreach ($status['areas'] as $candidate) {
                if (strcasecmp((string) ($candidate['id'] ?? ''), $area) === 0
                    || strcasecmp((string) ($candidate['name'] ?? ''), $area) === 0) {
                    $matchedArea = $candidate;
                    break;
                }
            }

            if (! is_array($matchedArea)) {
                throw new RuntimeException('Requested alarm area was not returned by the panel.');
            }

            $rawState = strtolower(trim((string) ($matchedArea['state'] ?? 'unknown')));
            if (! in_array($rawState, ['armed', 'disarmed', 'triggered'], true)) {
                throw new RuntimeException('Alarm area state is indeterminate.');
            }

            $armed = in_array($rawState, ['armed', 'triggered'], true);

            return new CommandObservedState(
                state: [
                    'armed' => $armed,
                    'area' => $area,
                ],
                observedAt: CarbonImmutable::now('UTC')->startOfSecond(),
                observationReference: $driver->key().':area-state:'.hash('sha256', $context->commandUuid.':'.$context->attemptUuid),
                safeEvidenceSummary: $armed
                    ? sprintf('Alarm controller freshly confirmed area %s is armed.', $area)
                    : sprintf('Alarm controller freshly confirmed area %s is disarmed.', $area),
            );
        } finally {
            $this->erase($material);
            $this->credentials->release($lease);
        }
    }

    private function assertContext(CommandExecutionContext $context): AlarmPanelDriver
    {
        if (! in_array($context->capability, self::CAPABILITIES, true)
            || $context->device->domain !== 'security'
            || ! in_array($context->device->category, ['alarm', 'perimeter'], true)
            || $this->sites->resolve((int) $context->device->id) !== $context->siteId) {
            throw new RuntimeException('Alarm panel command scope is invalid.');
        }

        $driver = $this->drivers->resolveAlarmDriver($context->device);
        if (! $driver instanceof AlarmPanelDriver) {
            throw new RuntimeException('No active local API alarm driver matches this Device.');
        }

        return $driver;
    }

    private function validateArea(string $area): string
    {
        $trimmed = trim($area);
        if ($trimmed === '' || preg_match('/^[A-Za-z0-9._:-]{1,80}$/', $trimmed) !== 1) {
            throw new RuntimeException('A valid alarm area identifier is required.');
        }

        return $trimmed;
    }

    /**
     * @param  array<string, scalar|null>  $material
     */
    private function extractToken(array $material): string
    {
        $token = $material['api_key'] ?? $material['api_token'] ?? $material['token'] ?? null;
        if (! is_string($token)
            || strlen($token) < 8
            || strlen($token) > 4090
            || preg_match('/[\x00-\x20\x7f]/', $token) === 1) {
            throw new RuntimeException('Alarm API credential material is invalid.');
        }

        return $token;
    }

    /**
     * @return array{ok: bool, reason_code: string, message: string}
     */
    private function dispatchArm(AlarmPanelDriver $driver, Device $device, string $area, #[\SensitiveParameter] string $token): array
    {
        if ($driver instanceof GallagherCommandCentreDriver) {
            return $driver->armAreaWithToken($device, $area, $token);
        }
        if ($driver instanceof InnerRangeInceptionDriver) {
            return $driver->armAreaWithToken($device, $area, $token);
        }

        return $driver->armArea($device, $area);
    }

    /**
     * @return array{ok: bool, reason_code: string, message: string}
     */
    private function dispatchDisarm(AlarmPanelDriver $driver, Device $device, string $area, #[\SensitiveParameter] string $token): array
    {
        if ($driver instanceof GallagherCommandCentreDriver) {
            return $driver->disarmAreaWithToken($device, $area, $token);
        }
        if ($driver instanceof InnerRangeInceptionDriver) {
            return $driver->disarmAreaWithToken($device, $area, $token);
        }

        return $driver->disarmArea($device, $area);
    }

    /**
     * @return array{
     *     ok: bool,
     *     reason_code: string,
     *     message: string,
     *     areas: list<array{id: string, name: string, state: string}>,
     *     zones: list<array{id: string, name: string, state: string}>,
     *     doors: list<array{id: string, name: string, state: string}>
     * }
     */
    private function dispatchStatus(AlarmPanelDriver $driver, Device $device, #[\SensitiveParameter] string $token): array
    {
        if ($driver instanceof GallagherCommandCentreDriver) {
            return $driver->fetchStatusWithToken($device, $token);
        }
        if ($driver instanceof InnerRangeInceptionDriver) {
            return $driver->fetchStatusWithToken($device, $token);
        }

        return $driver->fetchStatus($device);
    }

    private function recordPanelEvent(
        Device $device,
        string $eventType,
        string $area,
        string $driverKey,
        string $commandUuid,
        CarbonImmutable $occurredAt,
    ): void {
        $attributes = [
            'device_id' => $device->id,
            'event_type' => $eventType,
            'severity' => 'info',
            'source' => $driverKey,
            'occurred_at' => $occurredAt,
            'payload' => [
                'area' => $area,
                'command_uuid' => $commandUuid,
            ],
        ];

        if (SignalSource::query()->where('slug', 'security_devices')->exists()) {
            DeviceEvent::query()->create($attributes);
        } else {
            DeviceEvent::withoutEvents(fn () => DeviceEvent::query()->create($attributes));
        }
    }

    /**
     * @param  array<string, scalar|null>  $material
     */
    private function erase(array &$material): void
    {
        foreach ($material as &$value) {
            if (is_string($value) && $value !== '') {
                if (function_exists('sodium_memzero')) {
                    sodium_memzero($value);
                } else {
                    $value = str_repeat("\0", strlen($value));
                }
            }
            $value = null;
        }
        unset($value);
        $material = [];
    }
}
