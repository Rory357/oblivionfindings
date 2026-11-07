<?php

namespace App\Domain\SecurityDevices\Screens\Alerts;

use App\Domain\SecurityDevices\Automations\AutomationDefinition;
use App\Domain\SecurityDevices\Automations\AutomationDevices;
use App\Domain\SecurityDevices\Automations\Automations;
use App\Domain\SecurityDevices\Automations\AutomationWords;
use App\Domain\SecurityDevices\Automations\Models\Automation;
use App\Domain\SecurityDevices\Automations\Models\AutomationRun;
use App\Domain\SecurityDevices\Automations\SafeActions;
use App\Domain\SecurityDevices\Modes\Models\HouseMode;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;

/**
 * Alerts › Rules & automations (pg-sdr-13b): the house's automations beside the alert rules, as
 * when → where → then with their last run; what Add automation (pop-up 40) chooses from (with each
 * Site's house modes for "House mode is"); and a test
 * run's lines (pop-up 41). Only the viewer's Sites; device names only for devices they can see.
 */
final class AutomationsPresenter
{
    public function __construct(
        private readonly Automations $automations,
        private readonly AutomationDevices $devices,
        private readonly SecurityDevicesAccessService $access,
    ) {}

    public static function canManage(User $viewer): bool
    {
        return $viewer->canDo('securityDevices.automations.manage');
    }

    public function count(User $viewer, ?int $siteId = null): int
    {
        return $this->automations->scoped($viewer, $siteId)->count();
    }

    /** @return list<array<string, mixed>> */
    public function rows(User $viewer, ?int $siteId = null): array
    {
        $list = $this->automations->scoped($viewer, $siteId)->with('site:id,name')->orderByDesc('is_on')->orderBy('name')->orderBy('id')->get();
        if ($list->isEmpty()) {
            return [];
        }
        $ids = [];
        foreach ($list as $automation) {
            $ids[] = $automation->triggerDeviceId();
            foreach ($automation->actionList() as $action) {
                foreach (['cameras', 'doors', 'panels'] as $key) {
                    array_push($ids, ...array_values((array) ($action[$key] ?? [])));
                }
            }
        }
        $ids = array_values(array_unique(array_map('intval', array_filter($ids, fn (mixed $id): bool => is_numeric($id) && (int) $id > 0))));
        $names = $ids === [] ? [] : $this->access->visibleDevices($viewer)->whereKey($ids)->pluck('name', 'id')
            ->mapWithKeys(fn (mixed $name, mixed $id): array => [(int) $id => (string) $name])->all();
        $roles = Role::query()->pluck('label', 'name')->map(fn (mixed $label): string => (string) $label)->all();
        $modes = HouseMode::query()->whereIn('site_id', $list->pluck('site_id')->unique()->values()->all())->pluck('name', 'id')
            ->mapWithKeys(fn (mixed $name, mixed $id): array => [(int) $id => (string) $name])->all();
        $canManage = self::canManage($viewer);

        return $list->map(fn (Automation $automation): array => [
            'id' => (int) $automation->id,
            'version' => (int) $automation->version,
            'name' => (string) $automation->name,
            'when' => AutomationWords::when($automation, $names, $modes),
            'sub' => AutomationWords::sub($automation),
            'icon' => AutomationWords::icon($automation),
            'where' => (string) ($automation->site?->name ?? 'One Site'),
            'then' => AutomationWords::then($automation, $names, $roles),
            'last_run' => $automation->last_run_at !== null ? [
                'at' => $automation->last_run_at->toIso8601String(),
                'test' => (bool) $automation->last_run_test,
                'outcome' => (string) ($automation->last_run_outcome ?? AutomationRun::OK),
            ] : null,
            'on' => (bool) $automation->is_on,
            'can_change' => $canManage,
            // Edit reuses Add automation, prefilled.
            'definition' => $canManage ? [
                'site_id' => (int) $automation->site_id,
                'name' => (string) $automation->name,
                'is_on' => (bool) $automation->is_on,
                'trigger' => (array) $automation->trigger,
                'conditions' => [
                    'between' => $automation->conditions['between'] ?? null,
                    'modes' => array_values(array_map('intval', (array) ($automation->conditions['modes'] ?? []))),
                ],
                'actions' => $automation->actionList(),
            ] : null,
        ])->values()->all();
    }

    /**
     * What Add automation (40) chooses from: the person's Sites, the devices at each they can see, the
     * roles people can be told by, the safe actions (SafeActions::choices(), D12), the readings and
     * each Site's house modes ("House mode is", pg-sdr-12c).
     *
     * @return array<string, mixed>
     */
    public function formOptions(User $viewer): array
    {
        $siteIds = $this->access->accessibleSiteIds($viewer);
        $modes = [];
        if ($siteIds !== []) {
            HouseMode::query()->whereIn('site_id', $siteIds)->orderBy('sort_order')->orderBy('id')->get(['id', 'site_id', 'name'])
                ->each(function (HouseMode $mode) use (&$modes): void {
                    $modes[(string) $mode->site_id][] = ['id' => (int) $mode->id, 'name' => (string) $mode->name];
                });
        }
        $sites = $siteIds === [] ? collect() : Site::query()->whereIn('id', $siteIds)->where('is_active', true)->where('archived', false)->orderBy('name')->get(['id', 'name']);
        $devices = [];
        foreach ($this->devices->forViewer($viewer) as $siteId => $groups) {
            foreach ($groups as $group => $list) {
                $devices[(string) $siteId][$group] = array_map(
                    fn (int $id, string $name): array => ['id' => $id, 'name' => $name],
                    array_keys($list),
                    array_values($list),
                );
            }
        }

        return [
            'sites' => $sites->map(fn (Site $site): array => ['value' => (string) $site->id, 'label' => (string) $site->name])->values()->all(),
            'devices' => (object) $devices,
            'roles' => Role::query()->orderBy('level')->orderBy('label')->get(['name', 'label'])
                ->map(fn (Role $role): array => ['value' => (string) $role->name, 'label' => (string) $role->label])->values()->all(),
            'actions' => SafeActions::choices(),
            'modes' => (object) $modes,
            'metrics' => array_map(
                fn (string $key, array $spec): array => ['value' => $key, ...$spec],
                array_keys(AutomationDefinition::METRICS),
                array_values(AutomationDefinition::METRICS),
            ),
            'max_actions' => AutomationDefinition::MAX_ACTIONS,
        ];
    }

    /**
     * A run's lines for pop-up 41.
     *
     * @return array<string, mixed>
     */
    public static function run(AutomationRun $run): array
    {
        $lines = array_values(array_filter((array) $run->lines, 'is_array'));
        $actions = array_values(array_unique(array_filter(array_column($lines, 'action'), fn (mixed $key): bool => is_string($key) && array_key_exists($key, SafeActions::ACTIONS))));
        $broken = array_values(array_unique(array_column(array_filter($lines, fn (array $line): bool => ($line['tone'] ?? '') === 'critical' && array_key_exists((string) ($line['action'] ?? ''), SafeActions::ACTIONS)), 'action')));
        $skipped = count(array_filter($lines, fn (array $line): bool => ($line['action'] ?? '') === 'skipped'));
        $total = count($actions) + $skipped;
        $worked = count($actions) - count($broken);

        return [
            'id' => (int) $run->id,
            'test' => (bool) $run->test,
            'at' => $run->at->toIso8601String(),
            'summary' => (string) $run->summary,
            'outcome' => (string) $run->outcome,
            'lines' => array_map(fn (array $line): array => [
                'tone' => in_array($line['tone'] ?? null, ['success', 'warning', 'critical', 'info'], true) ? $line['tone'] : 'info',
                'text' => (string) ($line['text'] ?? ''),
                'at' => (string) ($line['at'] ?? $run->at->toIso8601String()),
            ], $lines),
            'result' => match (true) {
                $total === 0 => 'Nothing to run',
                $worked === $total => $total === 1 ? 'The action worked' : "All {$total} actions worked",
                $worked === 0 => $total === 1 ? 'The action didn’t work' : "None of the {$total} actions worked",
                default => "{$worked} of {$total} actions worked",
            },
        ];
    }
}
