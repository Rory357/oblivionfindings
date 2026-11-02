<?php

namespace App\Domain\SecurityDevices\Screens\Devices;

use App\Domain\SecurityDevices\Management\Services\BulkDeviceCommandPresenter;
use App\Domain\SecurityDevices\Management\Services\CommandCapabilityRegistry;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandBatchService;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Screens\DeviceKind;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;

/**
 * The Devices bulk bar (S&D rebuild C6; plan: "/command-batches/{id} → Bulk bar on Devices runs the
 * action with one confirm; results land in the device logbooks"): the governed actions the ticked
 * devices support, for this person.
 *
 * It reads today's bulk command presenter, so who may act, on which device, at which Site and with
 * which provider stays the batch engine's own decision. The confirm then posts to the existing
 * command-batch routes, which check everything again.
 */
final class BulkActionOptions
{
    /** Device domain → today's command-batch workspace. */
    private const array WORKSPACES = [
        'it_infrastructure' => 'network-it',
        'security' => 'security',
        'iot_healthcare' => 'healthcare',
        'facilities' => 'facilities-iot',
    ];

    /** A batch needs at least two targets; one device's actions are on its own page. */
    private const int MIN_TARGETS = 2;

    public const int MAX_DEVICES = 100;

    /** The keys that may start a command batch (the /command-batches route's own middleware). */
    public const array KEYS = [
        'securityDevices.commands.operate',
        'securityDevices.commands.manage',
        'securityDevices.commands.control',
        'securityDevices.commands.admin',
    ];

    public static function allowed(User $user): bool
    {
        return $user->canDo('securityDevices.devices.view')
            && collect(self::KEYS)->contains(fn (string $key): bool => $user->canDo($key));
    }

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly BulkDeviceCommandPresenter $presenter,
        private readonly CommandCapabilityRegistry $capabilities,
    ) {}

    /**
     * @param  list<int>  $ids
     * @return array{actions: list<array<string, mixed>>, left_out: list<array{id: int, name: string, reason: string}>, step_up_current: bool}
     */
    public function present(User $viewer, array $ids): array
    {
        $devices = $this->access->visibleDevices($viewer)
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'domain', 'category', 'subcategory']);

        $leftOut = [];
        /** @var array<string, list<int>> $groups */
        $groups = [];
        foreach ($devices as $device) {
            $domain = (string) $device->domain;
            // Stephan, 1 Nov: trackers are owned by Fleet and read-only in Security & devices.
            if ($domain === 'tracking' || DeviceKind::of($device)->tab() === 'trackers') {
                $leftOut[(int) $device->id] = $this->leftOut($device, 'Trackers belong to Fleet, so they can’t be changed from here.');

                continue;
            }
            $workspace = self::WORKSPACES[$domain] ?? null;
            if ($workspace === null) {
                $leftOut[(int) $device->id] = $this->leftOut($device, 'This kind of device has no actions Oblivion can run.');

                continue;
            }
            $groups[$workspace][] = (int) $device->id;
        }

        $actions = [];
        $covered = [];
        $stepUpCurrent = false;
        foreach ($groups as $workspace => $groupIds) {
            $domain = (string) array_search($workspace, self::WORKSPACES, true);
            $scope = $this->access->visibleDevices($viewer)
                ->whereIn('id', $groupIds)
                ->where('domain', $domain);
            $data = $this->presenter->present($viewer, $scope, $workspace, withRecent: false);
            $stepUpCurrent = $stepUpCurrent || (bool) $data['stepUpCurrent'];
            if (! $data['canRequest']) {
                continue;
            }
            /** @var Collection<int, array<string, mixed>> $rows */
            $rows = collect($data['devices']);
            foreach ($data['actions'] as $action) {
                $key = (string) $action['key'];
                $targets = $rows->filter(fn (array $row): bool => isset($row['actions'][$key]))->values();
                if ($targets->count() < self::MIN_TARGETS) {
                    continue;
                }
                try {
                    $definition = $this->capabilities->definition($key);
                } catch (DomainException) {
                    continue;
                }
                $ready = $targets->filter(fn (array $row): bool => (bool) $row['actions'][$key]['available']);
                $siteCount = $ready->pluck('siteId')->filter()->unique()->count();
                foreach ($targets as $row) {
                    $covered[(int) $row['id']] = true;
                }

                $actions[] = [
                    'key' => $key,
                    'workspace' => $workspace,
                    'label' => (string) $action['label'],
                    'risk' => (string) $action['risk'],
                    'impact' => (string) $action['impact'],
                    'expected' => (string) $action['expectedResult'],
                    'needs_approval' => (bool) $action['requiresApproval'],
                    'needs_change' => (bool) $action['requiresChange'],
                    'elevated' => DeviceCommandBatchService::elevated($definition, $siteCount),
                    'phrase' => DeviceCommandBatchService::confirmationPhrase($targets->count()),
                    'parameters' => array_values(array_map(fn (array $parameter): array => [
                        'name' => (string) $parameter['name'],
                        'label' => (string) $parameter['label'],
                        'type' => (string) $parameter['type'],
                        'min' => $parameter['min'],
                        'max' => $parameter['max'],
                        'options' => array_values(array_map('strval', (array) $parameter['options'])),
                        'option_labels' => (object) ($parameter['optionLabels'] ?? []),
                    ], (array) $action['parameters'])),
                    'ready' => $ready->count(),
                    'devices' => $targets->map(fn (array $row): array => [
                        'id' => (int) $row['id'],
                        'name' => (string) $row['name'],
                        'site' => (string) $row['siteName'],
                        'ready' => (bool) $row['actions'][$key]['available'],
                        // Why it can't take part right now, from the batch engine's own checks.
                        'reason' => (bool) $row['actions'][$key]['available'] ? null : self::why($row['actions'][$key]),
                        'changes' => array_values(array_map(fn (array $change): array => [
                            'id' => (int) $change['id'],
                            'label' => trim($change['reference'].' · '.$change['title']),
                        ], (array) $row['changeOptions'])),
                    ])->all(),
                ];
            }
        }

        foreach ($devices as $device) {
            $id = (int) $device->id;
            if (! isset($leftOut[$id]) && ! isset($covered[$id])) {
                $leftOut[$id] = $this->leftOut($device, 'No action it supports is shared with another ticked device.');
            }
        }

        usort($actions, fn (array $a, array $b): int => [count($b['devices']), $a['label']] <=> [count($a['devices']), $b['label']]);

        return [
            'actions' => $actions,
            'left_out' => array_values($leftOut),
            'step_up_current' => $stepUpCurrent,
        ];
    }

    /**
     * The engine's reason in plain words; an unknown code keeps the engine's own sentence.
     *
     * @param  array{available: bool, state: string, reason: string}  $availability
     */
    private static function why(array $availability): string
    {
        return match ($availability['state']) {
            'capability_not_declared' => 'Its maker doesn’t offer this action for it.',
            'device_state_blocked' => 'It isn’t in a state that allows this right now.',
            'observation_not_current' => 'Oblivion hasn’t heard from it recently enough.',
            'mfa_required' => 'This needs two-step sign-in on your account.',
            'provider_adapter_required' => 'Oblivion can’t send this to it yet.',
            'site_scope_unavailable' => 'It isn’t placed at one Site.',
            'it_change_required' => 'No approved IT change covers it right now.',
            'configuration_profile_required' => 'No approved settings profile fits it.',
            default => (string) $availability['reason'],
        };
    }

    /** @return array{id: int, name: string, reason: string} */
    private function leftOut(Device $device, string $reason): array
    {
        return ['id' => (int) $device->id, 'name' => (string) $device->name, 'reason' => $reason];
    }
}
