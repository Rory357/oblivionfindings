<?php

namespace App\Domain\SecurityDevices\Management\Services;

use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\SecurityDevices\Config\WorkspaceConfig;
use App\Domain\SecurityDevices\Management\Data\BulkCommandRequestInput;
use App\Domain\SecurityDevices\Management\Data\CommandCapabilityDefinition;
use App\Domain\SecurityDevices\Management\Data\CommandRequestInput;
use App\Domain\SecurityDevices\Management\Enums\CommandConfirmationMode;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandBatch;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use UnexpectedValueException;

final class DeviceCommandBatchService
{
    private const int MIN_TARGETS = 2;

    private const int MAX_TARGETS = 100;

    /** @var array<string, array{capability_domain: string, device_domain: string}> */
    private const array WORKSPACES = [
        'network-it' => ['capability_domain' => 'network_it', 'device_domain' => 'it_infrastructure'],
        'security' => ['capability_domain' => 'security', 'device_domain' => 'security'],
        'healthcare' => ['capability_domain' => 'healthcare', 'device_domain' => 'iot_healthcare'],
        'tracking' => ['capability_domain' => 'tracking', 'device_domain' => 'tracking'],
        'facilities-iot' => ['capability_domain' => 'facilities', 'device_domain' => 'facilities'],
    ];

    /** @var list<string> */
    private const array ELEVATED_BULK_SENSITIVITIES = [
        'personal_location',
        'privileged_remote',
        'destructive_endpoint',
        'security_control',
        'cctv_media',
        'availability_control',
        'broad_availability',
        'healthcare_technical',
        'facilities_control',
    ];

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly DeviceManagementAuthorizationService $authorization,
        private readonly CanonicalDeviceSiteResolver $sites,
        private readonly CommandCapabilityRegistry $capabilities,
        private readonly DeclaredDeviceCommandCapabilities $declaredCapabilities,
        private readonly CommandObservationFreshnessService $freshness,
        private readonly CommandExecutionRouteResolver $executionRoutes,
        private readonly DeviceCommandRequestService $requests,
    ) {}

    public function create(User $actor, BulkCommandRequestInput $input): DeviceCommandBatch
    {
        abort_unless($actor->canDo('securityDevices.devices.view'), 403);
        $workspace = $this->workspace($input->workspace);
        $deviceIds = collect($input->deviceIds)
            ->filter(fn (mixed $id): bool => is_int($id) || ctype_digit((string) $id))
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($deviceIds->count() < self::MIN_TARGETS || $deviceIds->count() > self::MAX_TARGETS) {
            throw ValidationException::withMessages([
                'device_ids' => 'Choose between 2 and 100 visible Devices for a governed bulk action.',
            ]);
        }

        try {
            $capability = $this->capabilities->definition(trim($input->capability));
        } catch (DomainException) {
            throw ValidationException::withMessages([
                'capability' => 'This bulk management action is not recognised.',
            ]);
        }
        if ($capability->domain !== $workspace['capability_domain'] && $capability->key !== 'configuration.apply') {
            abort(404);
        }
        $itChangeIds = $this->normaliseChangeIds($input->itChangeIds, $deviceIds, $capability);

        $devices = $this->visibleWorkspaceDevices($actor, $deviceIds, $workspace['device_domain']);
        if ($devices->count() !== $deviceIds->count()) {
            abort(404);
        }
        $orderedDevices = $deviceIds
            ->map(fn (int $id): Device => $devices->get($id))
            ->values();

        $availability = $orderedDevices->mapWithKeys(
            fn (Device $device): array => [$device->id => $this->targetAvailability($actor, $device, $capability)],
        );
        $availableSiteIds = $availability
            ->where('available', true)
            ->pluck('site_id')
            ->filter()
            ->unique()
            ->values();
        $this->assertBulkControls($actor, $input, $capability, $deviceIds->count(), $availableSiteIds->count());

        $parameters = Arr::sortRecursive($input->parameters);
        $reason = trim($input->reason);
        $idempotencyKey = trim($input->idempotencyKey);
        validator([
            'reason' => $reason,
            'idempotency_key' => $idempotencyKey,
        ], [
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'idempotency_key' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9:_-]+$/'],
        ])->validate();

        $contractHash = $this->contractHash(
            workspace: $input->workspace,
            deviceIds: $deviceIds->sort()->values()->all(),
            capability: $capability->key,
            parameters: $parameters,
            reason: $reason,
            itChangeIds: $itChangeIds,
            impactAcknowledged: $input->impactAcknowledged,
            confirmationText: trim((string) $input->confirmationText),
        );
        $existing = DeviceCommandBatch::query()
            ->where('requested_by_user_id', $actor->id)
            ->where('capability', $capability->key)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if ($existing) {
            if (! hash_equals($existing->contract_hash, $contractHash)) {
                throw ValidationException::withMessages([
                    'idempotency_key' => 'This idempotency key is already bound to a different bulk command contract.',
                ]);
            }

            return $existing;
        }

        $batchUuid = (string) Str::orderedUuid();
        $now = CarbonImmutable::now('UTC')->startOfSecond();

        return DB::transaction(function () use (
            $actor,
            $input,
            $capability,
            $orderedDevices,
            $availability,
            $parameters,
            $reason,
            $idempotencyKey,
            $contractHash,
            $batchUuid,
            $now,
            $itChangeIds,
        ): DeviceCommandBatch {
            $existing = DeviceCommandBatch::query()
                ->where('requested_by_user_id', $actor->id)
                ->where('capability', $capability->key)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                if (! hash_equals($existing->contract_hash, $contractHash)) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => 'This idempotency key is already bound to a different bulk command contract.',
                    ]);
                }

                return $existing;
            }

            $targets = [];
            foreach ($orderedDevices as $position => $device) {
                $targetAvailability = $availability->get($device->id);
                $siteId = $targetAvailability['site_id'];
                if (! $targetAvailability['available']) {
                    $targets[] = $this->excludedTarget(
                        $device,
                        $siteId,
                        $position,
                        $targetAvailability['code'],
                        $targetAvailability['reason'],
                    );

                    continue;
                }

                try {
                    $command = $this->requests->request($device, $actor, new CommandRequestInput(
                        capability: $capability->key,
                        parameters: $parameters,
                        reason: $reason,
                        idempotencyKey: 'bulk:'.$batchUuid.':'.$device->id,
                        stepUpConfirmedAt: $input->stepUpConfirmedAt,
                        itChangeId: $itChangeIds[(int) $device->id] ?? null,
                        impactAcknowledged: $input->impactAcknowledged,
                        confirmationText: $capability->confirmationMode === CommandConfirmationMode::TypeDeviceName
                            ? $device->name
                            : null,
                    ));
                    $targets[] = [
                        'device_id' => (int) $device->id,
                        'site_id' => $siteId,
                        'device_command_request_id' => (int) $command->id,
                        'position' => $position + 1,
                        'inclusion_status' => 'included',
                        'safe_exclusion_code' => null,
                        'safe_exclusion_reason' => null,
                        'created_at' => $now,
                    ];
                } catch (ValidationException $failure) {
                    $targets[] = $this->excludedTarget(
                        $device,
                        $siteId,
                        $position,
                        $this->validationCode($failure),
                        $this->validationReason($failure),
                    );
                } catch (HttpExceptionInterface $failure) {
                    if (! in_array($failure->getStatusCode(), [403, 404], true)) {
                        throw $failure;
                    }
                    $targets[] = $this->excludedTarget(
                        $device,
                        $siteId,
                        $position,
                        'authorization_changed',
                        'This Device is no longer eligible under the current workspace, Site, ownership, or sensitivity rules.',
                    );
                }
            }

            $included = collect($targets)->where('inclusion_status', 'included');
            if ($included->isEmpty()) {
                throw ValidationException::withMessages([
                    'device_ids' => 'None of the selected Devices can currently enter this governed command lifecycle.',
                ]);
            }

            $batch = DeviceCommandBatch::query()->create([
                'batch_uuid' => $batchUuid,
                'requested_by_user_id' => $actor->id,
                'workspace' => $input->workspace,
                'capability' => $capability->key,
                'capability_version' => 1,
                'risk' => $capability->risk,
                'confirmation_mode' => $capability->confirmationMode,
                'reason' => $reason,
                'safe_parameter_summary' => Arr::only($parameters, $capability->safeSummaryFields),
                'idempotency_key' => $idempotencyKey,
                'contract_hash' => $contractHash,
                'target_count' => count($targets),
                'included_count' => $included->count(),
                'excluded_count' => collect($targets)->where('inclusion_status', 'excluded')->count(),
                'site_count' => $included->pluck('site_id')->filter()->unique()->count(),
                'impact_acknowledged_at' => $input->impactAcknowledged ? $now : null,
            ]);
            $batch->targets()->createMany($targets);

            return $batch;
        });
    }

    /** @return array{available: bool, code: string, reason: string, site_id: int|null} */
    public function targetAvailability(
        User $actor,
        Device $device,
        CommandCapabilityDefinition $capability,
    ): array {
        $authorization = $this->authorization->evaluate($actor, $device, $capability, fresh: true);
        if (! $authorization->allowed) {
            return $this->availability(false, $authorization->code, $authorization->reason);
        }

        try {
            $siteId = $this->sites->resolve((int) $device->id);
        } catch (UnexpectedValueException) {
            return $this->availability(false, 'site_scope_unavailable', 'The Device does not resolve to one current operational Site.');
        }
        if (! $this->declaredCapabilities->supports($device, $capability->key)) {
            return $this->availability(false, 'capability_not_declared', 'The provider has not declared this action for the Device.', $siteId);
        }
        $deviceState = $device->status?->value ?? (string) $device->status;
        if (! in_array($deviceState, $capability->allowedCurrentStates, true)) {
            return $this->availability(false, 'device_state_blocked', 'The Device current state does not allow this action.', $siteId);
        }
        if ($capability->requiresFreshObservation && ! $this->freshness->inspect($device)->isFresh()) {
            return $this->availability(false, 'observation_not_current', 'A fresh Device observation is required.', $siteId);
        }
        if ($capability->requiresMfa && $actor->two_factor_confirmed_at === null) {
            return $this->availability(false, 'mfa_required', 'Configured multi-factor authentication is required.', $siteId);
        }
        if (! $this->executionRoutes->resolve($device, $siteId, $capability->key)->available) {
            return $this->availability(false, 'provider_adapter_required', 'No approved execution and reconciliation adapter is currently available.', $siteId);
        }

        return $this->availability(true, 'available', 'Ready for governed request validation.', $siteId);
    }

    /** @param Collection<int, int> $deviceIds @return Collection<int, Device> */
    private function visibleWorkspaceDevices(User $actor, Collection $deviceIds, string $deviceDomain): Collection
    {
        return $this->access->visibleDevices($actor)
            ->whereIn('id', $deviceIds->all())
            ->where('domain', $deviceDomain)
            ->get()
            ->keyBy(fn (Device $device): int => (int) $device->id);
    }

    /** @return array{capability_domain: string, device_domain: string} */
    private function workspace(string $workspace): array
    {
        $definition = self::WORKSPACES[$workspace] ?? null;
        if ($definition === null || WorkspaceConfig::get($workspace) === null) {
            throw ValidationException::withMessages(['workspace' => 'Choose a supported Security & Devices workspace.']);
        }

        return $definition;
    }

    private function assertBulkControls(
        User $actor,
        BulkCommandRequestInput $input,
        CommandCapabilityDefinition $capability,
        int $targetCount,
        int $siteCount,
    ): void {
        $elevated = $capability->isHighRisk()
            || $capability->requiresStepUp
            || $capability->requiresMfa
            || $siteCount > 1
            || in_array($capability->sensitivity, self::ELEVATED_BULK_SENSITIVITIES, true);
        if (! $elevated) {
            return;
        }
        if (! $input->impactAcknowledged) {
            throw ValidationException::withMessages([
                'impact_acknowledged' => 'Acknowledge the combined impact before creating this governed bulk action.',
            ]);
        }
        $now = CarbonImmutable::now('UTC')->startOfSecond();
        $stepUpMaxAge = max(60, (int) config('security_devices.step_up_max_age_seconds', 900));
        if ($input->stepUpConfirmedAt === null
            || $input->stepUpConfirmedAt->isFuture()
            || $input->stepUpConfirmedAt->lessThan($now->subSeconds($stepUpMaxAge))) {
            throw ValidationException::withMessages([
                'confirmation_text' => 'Recent identity confirmation is required for this bulk action.',
            ]);
        }
        $expected = self::confirmationPhrase($targetCount);
        if (! hash_equals($expected, trim((string) $input->confirmationText))) {
            throw ValidationException::withMessages([
                'confirmation_text' => "Type {$expected} to confirm the exact target count.",
            ]);
        }
        if ($capability->risk->value === 'critical' && $actor->two_factor_confirmed_at === null) {
            throw ValidationException::withMessages([
                'confirmation_text' => 'Configured multi-factor authentication is required for this critical bulk action.',
            ]);
        }
    }

    public static function confirmationPhrase(int $targetCount): string
    {
        return 'BULK '.$targetCount.' DEVICES';
    }

    /** @param list<int> $deviceIds @param array<string, mixed> $parameters @param array<int, int> $itChangeIds */
    private function contractHash(
        string $workspace,
        array $deviceIds,
        string $capability,
        array $parameters,
        string $reason,
        array $itChangeIds,
        bool $impactAcknowledged,
        string $confirmationText,
    ): string {
        return hash('sha256', json_encode([
            'workspace' => $workspace,
            'device_ids' => $deviceIds,
            'capability' => $capability,
            'parameters' => $parameters,
            'reason' => $reason,
            'it_change_ids' => $itChangeIds,
            'impact_acknowledged' => $impactAcknowledged,
            'confirmation_text' => $confirmationText,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<int, int>  $input
     * @param  Collection<int, int>  $deviceIds
     * @return array<int, int>
     */
    private function normaliseChangeIds(
        array $input,
        Collection $deviceIds,
        CommandCapabilityDefinition $capability,
    ): array {
        $changes = [];
        foreach ($input as $deviceId => $changeId) {
            if ((! is_int($deviceId) && ! ctype_digit((string) $deviceId))
                || (! is_int($changeId) && ! ctype_digit((string) $changeId))
                || (int) $deviceId < 1
                || (int) $changeId < 1) {
                throw ValidationException::withMessages([
                    'it_change_ids' => 'Each Device change selection must contain a valid Device and IT Change identifier.',
                ]);
            }
            if (! $deviceIds->containsStrict((int) $deviceId)) {
                throw ValidationException::withMessages([
                    'it_change_ids' => 'IT Change selections may reference only the chosen Devices.',
                ]);
            }
            $changes[(int) $deviceId] = (int) $changeId;
        }
        ksort($changes, SORT_NUMERIC);
        if (! $capability->requiresChange && $changes !== []) {
            throw ValidationException::withMessages([
                'it_change_ids' => 'This management action does not use an IT Change maintenance window.',
            ]);
        }

        return $changes;
    }

    /** @return array<string, mixed> */
    private function excludedTarget(
        Device $device,
        ?int $siteId,
        int $position,
        string $code,
        string $reason,
    ): array {
        return [
            'device_id' => (int) $device->id,
            'site_id' => $siteId,
            'device_command_request_id' => null,
            'position' => $position + 1,
            'inclusion_status' => 'excluded',
            'safe_exclusion_code' => Str::limit(Str::snake($code), 80, ''),
            'safe_exclusion_reason' => Str::limit($reason, 1000),
            'created_at' => CarbonImmutable::now('UTC')->startOfSecond(),
        ];
    }

    private function validationCode(ValidationException $failure): string
    {
        $field = array_key_first($failure->errors()) ?? 'request';

        return 'validation_'.Str::snake((string) $field);
    }

    private function validationReason(ValidationException $failure): string
    {
        return Str::limit((string) collect($failure->errors())->flatten()->first(
            fn (mixed $message): bool => is_string($message),
        ), 1000);
    }

    /** @return array{available: bool, code: string, reason: string, site_id: int|null} */
    private function availability(bool $available, string $code, string $reason, ?int $siteId = null): array
    {
        return [
            'available' => $available,
            'code' => $code,
            'reason' => $reason,
            'site_id' => $siteId,
        ];
    }
}
