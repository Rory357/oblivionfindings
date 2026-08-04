<?php

namespace App\Domain\SecurityDevices\Management\Services;

use App\Domain\SecurityDevices\Management\Data\CommandCapabilityDefinition;
use App\Domain\SecurityDevices\Management\Enums\ManagementLevel;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandBatch;
use App\Models\ItChange;
use App\Models\Site;
use App\Models\User;
use App\Services\Queclink\QueclinkConfigurationProfileService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class BulkDeviceCommandPresenter
{
    private const int CANDIDATE_LIMIT = 100;

    /** @var array<string, string> */
    private const array CAPABILITY_DOMAINS = [
        'network-it' => 'network_it',
        'security' => 'security',
        'healthcare' => 'healthcare',
        'tracking' => 'tracking',
        'facilities-iot' => 'facilities',
    ];

    public function __construct(
        private readonly CommandCapabilityRegistry $capabilities,
        private readonly DeclaredDeviceCommandCapabilities $declaredCapabilities,
        private readonly DeviceManagementAuthorizationService $authorization,
        private readonly DeviceCommandBatchService $batches,
        private readonly CommandChangeEligibilityService $changeEligibility,
        private readonly DeviceCommandBatchPresenter $batchPresenter,
        private readonly QueclinkConfigurationProfileService $configurationProfiles,
    ) {}

    /** @return array<string, mixed> */
    public function present(User $viewer, Builder $scope, string $workspace): array
    {
        $capabilityDomain = self::CAPABILITY_DOMAINS[$workspace] ?? null;
        abort_unless($capabilityDomain !== null, 404);
        $this->authorization->resetMemoizedState();

        $total = (clone $scope)->count();
        $devices = (clone $scope)
            ->with(['assignments' => fn ($assignment) => $assignment->active()])
            ->orderBy('name')
            ->orderBy('id')
            ->limit(self::CANDIDATE_LIMIT)
            ->get();
        $deviceRows = [];
        $actionDefinitions = [];
        $siteIds = [];
        $profileOptions = collect();

        foreach ($devices as $device) {
            $actions = [];
            $siteId = null;
            /** @var Collection<int, ItChange>|null $eligibleChanges */
            $eligibleChanges = null;
            foreach ($this->declaredCapabilities->forDevice($device) as $key) {
                try {
                    $definition = $this->capabilities->definition($key);
                } catch (DomainException) {
                    continue;
                }
                if ($definition->domain !== $capabilityDomain && $definition->key !== 'configuration.apply') {
                    continue;
                }
                $authorization = $this->authorization->evaluate($viewer, $device, $definition);
                if (! $authorization->allowed && $authorization->concealed) {
                    continue;
                }
                $availability = $authorization->allowed
                    ? $this->batches->targetAvailability($viewer, $device, $definition)
                    : [
                        'available' => false,
                        'code' => $authorization->code,
                        'reason' => $authorization->reason,
                        'site_id' => null,
                    ];
                if ($definition->key === 'configuration.apply') {
                    $compatibleProfiles = $this->configurationProfiles->compatibleProfiles($device);
                    $profileOptions = $profileOptions->merge($compatibleProfiles)->unique('id')->values();
                    if ($availability['available'] && $compatibleProfiles->isEmpty()) {
                        $availability = [
                            ...$availability,
                            'available' => false,
                            'code' => 'configuration_profile_required',
                            'reason' => 'No active desired configuration profile is approved for this provider and Device class.',
                        ];
                    }
                }
                if ($availability['available'] && $definition->requiresChange) {
                    $eligibleChanges ??= $availability['site_id'] === null
                        ? collect()
                        : $this->changeEligibility->eligibleFor(
                            $viewer,
                            $device,
                            (int) $availability['site_id'],
                        );
                    if ($eligibleChanges->isEmpty()) {
                        $availability = [
                            ...$availability,
                            'available' => false,
                            'code' => 'it_change_required',
                            'reason' => 'No current approved IT Change is linked to this Device and Site.',
                        ];
                    }
                }
                if ($availability['site_id'] !== null) {
                    $siteId ??= (int) $availability['site_id'];
                    $siteIds[] = (int) $availability['site_id'];
                }
                $actions[$key] = [
                    'available' => (bool) $availability['available'],
                    'state' => $availability['code'],
                    'reason' => $availability['reason'],
                ];
                $actionDefinitions[$key] = $definition;
            }

            $deviceRows[] = [
                'id' => (int) $device->id,
                'name' => $device->name,
                'uid' => $device->device_uid,
                'category' => $device->category,
                'subcategory' => $device->subcategory,
                'provider' => $device->provider,
                'status' => $device->status?->value ?? (string) $device->status,
                'health' => $device->health_status?->value ?? (string) $device->health_status,
                'siteId' => $siteId,
                'changeOptions' => ($eligibleChanges ?? collect())
                    ->map(fn ($change): array => [
                        'id' => (int) $change->id,
                        'reference' => $change->ticket->reference,
                        'title' => $change->ticket->title,
                        'workflowState' => $change->ticket->workflow_state,
                        'maintenanceEndsAt' => $change->maintenance_ends_at?->toISOString(),
                    ])
                    ->values()
                    ->all(),
                'actions' => $actions,
            ];
        }

        $sites = Site::query()
            ->whereKey(array_values(array_unique($siteIds)))
            ->pluck('name', 'id');
        $deviceRows = collect($deviceRows)->map(function (array $device) use ($sites): array {
            $device['siteName'] = $device['siteId'] === null
                ? 'Site unavailable'
                : ($sites[$device['siteId']] ?? 'Site unavailable');

            return $device;
        })->values()->all();

        $actions = collect($actionDefinitions)
            ->map(function (CommandCapabilityDefinition $definition) use ($deviceRows, $profileOptions): array {
                $eligibleCount = collect($deviceRows)
                    ->filter(fn (array $device): bool => (bool) ($device['actions'][$definition->key]['available'] ?? false))
                    ->count();
                $declaredCount = collect($deviceRows)
                    ->filter(fn (array $device): bool => isset($device['actions'][$definition->key]))
                    ->count();

                return [
                    'key' => $definition->key,
                    'label' => $definition->label,
                    'risk' => $definition->risk->value,
                    'level' => $definition->level->value,
                    'sensitivity' => $definition->sensitivity,
                    'impact' => $definition->impact,
                    'expectedResult' => $definition->expectedResult,
                    'requiresStepUp' => $definition->requiresStepUp,
                    'requiresMfa' => $definition->requiresMfa,
                    'requiresFreshObservation' => $definition->requiresFreshObservation,
                    'requiresApproval' => $definition->requiresApproval,
                    'requiresChange' => $definition->requiresChange,
                    'expiresAfterSeconds' => $definition->expiresAfterSeconds,
                    'confirmationMode' => $definition->confirmationMode->value,
                    'eligibleCount' => $eligibleCount,
                    'declaredCount' => $declaredCount,
                    'parameters' => collect($definition->parameters)
                        ->map(function (array $schema, string $name) use ($profileOptions): array {
                            $profileSource = ($schema['source'] ?? null) === 'compatible_configuration_profiles';

                            return [
                                'name' => $name,
                                'label' => $profileSource ? 'Approved configuration profile' : Str::headline($name),
                                'type' => $schema['type'],
                                'min' => $schema['min'] ?? null,
                                'max' => $schema['max'] ?? $schema['max_length'] ?? null,
                                'options' => $profileSource
                                    ? $profileOptions->pluck('id')->map(fn (int $id): string => (string) $id)->all()
                                    : ($schema['enum'] ?? []),
                                'optionLabels' => $profileSource
                                    ? $profileOptions->mapWithKeys(fn ($profile): array => [
                                        (string) $profile->id => $profile->name.' · v'.$profile->version,
                                    ])->all()
                                    : [],
                            ];
                        })->values()->all(),
                ];
            })
            ->sortBy(fn (array $action): string => $action['risk'].'|'.$action['label'])
            ->values()
            ->all();

        $recentBatches = [];
        foreach (DeviceCommandBatch::query()
            ->where('workspace', $workspace)
            ->latest('id')
            ->limit(25)
            ->get() as $batch) {
            try {
                $presented = $this->batchPresenter->present($viewer, $batch);
            } catch (HttpExceptionInterface $exception) {
                if (! in_array($exception->getStatusCode(), [403, 404], true)) {
                    throw $exception;
                }

                continue;
            }
            $recentBatches[] = [
                'id' => $presented['id'],
                'uuid' => $presented['uuid'],
                'label' => $presented['label'],
                'risk' => $presented['risk'],
                'status' => $presented['status'],
                'requestedBy' => $presented['requestedBy'],
                'requestedAt' => $presented['requestedAt'],
                'summary' => $presented['summary'],
                'href' => "/security-devices/command-batches/{$presented['id']}",
            ];
            if (count($recentBatches) >= 12) {
                break;
            }
        }

        return [
            'workspace' => $workspace,
            'actions' => $actions,
            'devices' => $deviceRows,
            'candidateCount' => count($deviceRows),
            'totalVisibleCount' => $total,
            'truncated' => $total > self::CANDIDATE_LIMIT,
            'targetLimit' => self::CANDIDATE_LIMIT,
            'canObserve' => $this->authorization->allowsLevel($viewer, ManagementLevel::Observe),
            'canRequest' => $this->authorization->allowsLevel($viewer, ManagementLevel::Operate),
            'stepUpCurrent' => $this->stepUpCurrent(),
            'recentBatches' => $recentBatches,
        ];
    }

    private function stepUpCurrent(): bool
    {
        $confirmedAt = request()->session()->get('auth.password_confirmed_at');
        if (! is_numeric($confirmedAt)) {
            return false;
        }
        $confirmed = CarbonImmutable::createFromTimestampUTC((int) $confirmedAt);
        $now = CarbonImmutable::now('UTC');

        return ! $confirmed->isFuture()
            && $confirmed->greaterThanOrEqualTo($now->subSeconds(max(60, (int) config('security_devices.step_up_max_age_seconds', 900))));
    }
}
