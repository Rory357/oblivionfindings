<?php

namespace App\Domain\SecurityDevices\Screens\Alerts;

use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Models\MonitoringMaintenanceWindow;
use App\Domain\SecurityDevices\Alerts\DeviceAlertEngine;
use App\Domain\SecurityDevices\Alerts\Models\DeviceAlert;
use App\Domain\SecurityDevices\Alerts\Models\DeviceAlertEvent;
use App\Domain\SecurityDevices\Hubs\Services\CoveringHubResolver;
use App\Domain\SecurityDevices\Screens\Devices\DeviceDirectory;
use App\Domain\SecurityDevices\Screens\Devices\DeviceEntry;
use App\Domain\SecurityDevices\Screens\Sites\AlertWords;
use App\Domain\SecurityDevices\Screens\Sites\SiteStream;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Alerts (S&D rebuild drawing pg-sdr-13): a work queue of what needs someone, who has been
 * told and what happened next, with the selected alert's story and timeline beside it.
 */
final class AlertsPresenter
{
    /** S&D round 3: Quiet times (13c) and On call (13d) join the rail. */
    public const array TABS = ['open', 'snoozed', 'done', 'rules', 'quiet', 'oncall'];

    public const int DONE_PER_PAGE = 25;

    /** Recently closed alerts stay at the bottom of Open for this long, marked resolved. */
    public const int RESOLVED_HOURS = 12;

    private const array PLURAL = [
        'Network switch' => 'network switches', 'Router' => 'routers', 'Wi-Fi point' => 'Wi-Fi points', 'Network device' => 'network devices',
        'Camera' => 'cameras', 'Video recorder' => 'video recorders', 'Alarm panel' => 'alarm panels', 'Door' => 'doors', 'Sensor' => 'sensors',
        'Sensor gateway' => 'sensor gateways', 'Care tracker' => 'care trackers', 'Vehicle tracker' => 'vehicle trackers', 'Computer' => 'computers',
        'UPS (power)' => 'UPSs', 'Printer' => 'printers', 'Hub' => 'hubs', 'Other device' => 'other devices',
    ];

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly DeviceDirectory $directory,
        private readonly CoveringHubResolver $hubs,
        private readonly AlertRulesPresenter $rules,
        private readonly AutomationsPresenter $automations,
        private readonly QuietTimesPresenter $quietTimes,
        private readonly OnCallPresenter $onCall,
    ) {}

    public static function canOpen(User $viewer): bool
    {
        return $viewer->canDo('securityDevices.events.view') || $viewer->canDo('securityDevices.alerts.acknowledge');
    }

    /**
     * @param  array{tab?: mixed, site?: mixed, alert?: mixed, page?: mixed, q?: mixed}  $query
     * @return array<string, mixed>
     */
    public function present(User $viewer, array $query = [], ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $tab = in_array($query['tab'] ?? null, self::TABS, true) ? (string) $query['tab'] : 'open';
        $siteIds = $this->access->accessibleSiteIds($viewer);
        $siteFilter = is_numeric($query['site'] ?? null) && in_array((int) $query['site'], $siteIds, true) ? (int) $query['site'] : null;
        $base = $this->scoped($viewer, $siteFilter);
        // The header search narrows the lists (queue, Done); the numbers and tab counts stay whole.
        $search = is_string($query['q'] ?? null) ? trim(mb_substr($query['q'], 0, 100)) : '';

        $open = (clone $base)->with(['device:id,name', 'site:id,name', 'acknowledgedBy:id,name', 'assignedTo:id,name'])
            ->where('state', DeviceAlert::STATE_OPEN)->get()
            ->sortBy(fn (DeviceAlert $alert): string => ($alert->severity === 'critical' ? '0' : '1').($alert->acknowledged_at ? '1' : '0').str_pad((string) (PHP_INT_MAX - $alert->opened_at->getTimestamp()), 20, '0', STR_PAD_LEFT))
            ->values();
        $resolved = (clone $base)->with(['device:id,name', 'site:id,name'])
            ->where('state', DeviceAlert::STATE_DONE)->where('closed_at', '>=', $now->subHours(self::RESOLVED_HOURS))
            ->orderByDesc('closed_at')->limit(10)->get();
        $snoozed = (clone $base)->with(['device:id,name', 'site:id,name', 'acknowledgedBy:id,name', 'assignedTo:id,name'])
            ->where('state', DeviceAlert::STATE_SNOOZED)->orderBy('snoozed_until')->get();
        // A care rule's alert names its resident only to someone who may see them (as the Site page does).
        $this->hide = SiteStream::carePeople($viewer, $open->concat($resolved)->concat($snoozed));
        $matches = fn (DeviceAlert $alert): bool => $search === ''
            || str_contains(mb_strtolower($this->title($alert).' '.($alert->device?->name ?? '').' '.($alert->site?->name ?? '')), mb_strtolower($search));
        $doneCount = (clone $base)->where('state', DeviceAlert::STATE_DONE)->where('closed_at', '>=', $now->subDays(30))->count();
        // S&D Phase F: the tab counts the house automations beside the alert rules.
        $rules = $this->rules->count($viewer, $siteFilter) + $this->automations->count($viewer, $siteFilter);

        $queue = match ($tab) {
            'open' => [...$open->filter($matches)->map(fn (DeviceAlert $alert): array => $this->row($alert))->values()->all(), ...$resolved->filter($matches)->map(fn (DeviceAlert $alert): array => $this->row($alert))->values()->all()],
            'snoozed' => $snoozed->filter($matches)->map(fn (DeviceAlert $alert): array => $this->row($alert))->values()->all(),
            default => [],
        };
        $done = $tab === 'done' ? $this->done($viewer, $base, max(1, (int) ($query['page'] ?? 1)), $now, $search) : null;

        $selectedId = is_numeric($query['alert'] ?? null) ? (int) $query['alert'] : ($queue[0]['id'] ?? ($done['rows'][0]['id'] ?? null));
        $selected = $selectedId !== null && in_array($tab, ['open', 'snoozed', 'done'], true) ? $this->selected($viewer, (int) $selectedId, $siteFilter) : null;

        $needsNow = $open->filter(fn (DeviceAlert $alert): bool => $alert->severity === 'critical' && $alert->acknowledged_at === null && $alert->assigned_to_user_id === null);
        $waiting = $open->reject(fn (DeviceAlert $alert): bool => $needsNow->contains('id', $alert->id));
        $oldestWaiting = $waiting->min(fn (DeviceAlert $alert): int => $alert->opened_at->getTimestamp());

        return [
            'tab' => $tab,
            'counts' => ['open' => $open->count(), 'snoozed' => $snoozed->count(), 'done' => $doneCount, 'rules' => $rules, 'quiet' => $this->quietTimes->count($viewer, $siteFilter, $now)],
            'site' => $siteFilter === null ? 'all' : (string) $siteFilter,
            'q' => $search === '' ? null : $search,
            'sites' => $siteIds === [] ? [] : Site::query()->whereIn('id', $siteIds)->where('is_active', true)->where('archived', false)->orderBy('name')
                ->get(['id', 'name'])->map(fn (Site $site): array => ['value' => (string) $site->id, 'label' => (string) $site->name])->all(),
            'meters' => [
                'needs_now' => [
                    'count' => $needsNow->count(),
                    'caption' => $needsNow->isEmpty() ? 'Nobody needed right now' : (string) ($needsNow->first()?->device?->name ?? $needsNow->first()?->title).($needsNow->count() > 1 ? ' and '.($needsNow->count() - 1).' more' : ''),
                ],
                'waiting' => [
                    'count' => $waiting->count(),
                    'caption' => $oldestWaiting === null ? 'Nothing waiting' : ['text' => 'Oldest {ago}', 'at' => CarbonImmutable::createFromTimestamp($oldestWaiting, 'UTC')->toIso8601String()],
                ],
                'quiet' => $this->quietTime($viewer, $now),
            ],
            'queue' => $queue,
            'done' => $done,
            'selected' => $selected,
            'rules' => $tab === 'rules' ? $this->rules->present($viewer, $siteFilter) : null,
            // S&D Phase F: the house automations (when, only if, then), in the same list as the rules.
            'automations' => $tab === 'rules' ? $this->automations->rows($viewer, $siteFilter) : null,
            // Alerts per week (13b), counted from the alerts themselves. Who was told isn't kept per
            // person or role (the timeline joins the names), so that chart isn't drawn.
            'per_week' => $tab === 'rules' ? $this->perWeek($base, $now) : null,
            // S&D round 3: Quiet times (13c) from the maintenance windows, On call (13d) from Rostering.
            'quiet_times' => $tab === 'quiet' ? $this->quietTimes->present($viewer, $siteFilter, $now) : null,
            'on_call' => $tab === 'oncall' ? $this->onCall->present($viewer, $base, $now) : null,
            'can' => [
                'acknowledge' => $viewer->canDo('securityDevices.alerts.acknowledge'),
                'quiet_time' => $viewer->canDo('securityDevices.monitoring.manage'),
                'add_rule' => $viewer->canDo('securityDevices.monitoring.manage'),
                'report_incident' => $viewer->canDo('incidents.create'),
                'add_automation' => AutomationsPresenter::canManage($viewer),
                // pg-sdr-18b: Export (the device events CSV that Reports had), same key as before.
                'export' => $viewer->canDo('securityDevices.reports.view'),
            ],
        ];
    }

    /** Alerts the viewer may see: their Sites' devices only. @return Builder<DeviceAlert> */
    public function scoped(User $viewer, ?int $siteId = null): Builder
    {
        $visible = ($siteId === null ? $this->access->visibleDevices($viewer) : $this->access->visibleDevicesForSite($viewer, $siteId))->select('devices.id');

        return DeviceAlert::query()->whereIn('device_id', $visible);
    }

    /** @var array<int, string|bool> alert id → AlertWords' $hide, for the alerts on this page */
    private array $hide = [];

    private function title(DeviceAlert $alert): string
    {
        return (new AlertWords($alert, $this->hide[(int) $alert->id] ?? false))->title();
    }

    /** @return array<string, mixed> */
    private function row(DeviceAlert $alert): array
    {
        $tone = $alert->state === DeviceAlert::STATE_DONE ? 'success' : ($alert->severity === 'critical' ? 'critical' : 'warning');
        $who = match (true) {
            $alert->state === DeviceAlert::STATE_DONE => 'Resolved {closed}',
            $alert->assignedTo !== null => $alert->assignedTo->name.' has it',
            $alert->acknowledgedBy !== null => $alert->acknowledgedBy->name.' has it',
            $alert->state === DeviceAlert::STATE_SNOOZED => 'Snoozed until {snoozed}',
            default => $this->lastTold($alert) ?? 'Nobody acknowledged yet',
        };

        return [
            'id' => (int) $alert->id,
            'title' => $this->title($alert),
            'tone' => $tone,
            'state' => $alert->state,
            'opened_at' => $alert->opened_at->toIso8601String(),
            'closed_at' => $alert->closed_at?->toIso8601String(),
            'snoozed_until' => $alert->snoozed_until?->toIso8601String(),
            'sub' => trim(($alert->site?->name ? $alert->site->name.' · ' : '').$who),
            'badge' => ['tone' => $tone, 'text' => match (true) {
                $alert->state === DeviceAlert::STATE_DONE => 'Resolved',
                $alert->kind === 'offline' => 'Offline',
                $alert->kind === 'hub_offline' => 'Hub',
                in_array($alert->kind, ['panic', 'alarm', 'leak', 'forced', 'tamper'], true) => 'Safety',
                $alert->kind === 'battery' => 'Battery',
                str_starts_with($alert->kind, 'reading_') => 'Reading',
                in_array($alert->kind, ['mqtt_logins', 'mqtt_exposed'], true) => 'Sensor broker',
                $alert->kind === 'automation' => 'Automation',
                default => $alert->severity === 'critical' ? 'Failing' : 'Warning',
            }],
        ];
    }

    private function lastTold(DeviceAlert $alert): ?string
    {
        $told = DeviceAlertEvent::query()->where('alert_id', $alert->id)->where('kind', 'told')->latest('id')->value('text');

        return is_string($told) ? $told : null;
    }

    /** @return array<string, mixed>|null */
    private function selected(User $viewer, int $alertId, ?int $siteId): ?array
    {
        $alert = $this->scoped($viewer, $siteId)->with(['device', 'site:id,name', 'acknowledgedBy:id,name', 'assignedTo:id,name', 'events.actor:id,name'])->find($alertId);
        if (! $alert instanceof DeviceAlert) {
            return null;
        }
        $device = $alert->device;
        $entry = $device ? $this->directory->entries($viewer, null, [(int) $device->id])->first() : null;
        $active = $alert->isActive();
        $model = $entry?->model() ?? trim(trim((string) $device?->manufacturer).' '.trim((string) $device?->model));
        $where = trim((string) ($alert->site?->name ?? '').(trim((string) $device?->location_description) !== '' ? ' · '.trim((string) $device->location_description) : ''));
        $affected = $this->affected($viewer, $alert);
        $words = new AlertWords($alert, SiteStream::carePeople($viewer, collect([$alert]))[(int) $alert->id] ?? false);

        return [
            'id' => (int) $alert->id,
            'title' => $words->title(),
            'tone' => $alert->state === DeviceAlert::STATE_DONE ? 'success' : ($alert->severity === 'critical' ? 'critical' : 'warning'),
            'state' => $alert->state,
            'label' => match (true) {
                $alert->state === DeviceAlert::STATE_DONE => ['text' => 'Resolved · {closed}', 'at' => $alert->closed_at?->toIso8601String()],
                $alert->state === DeviceAlert::STATE_SNOOZED => ['text' => 'Snoozed until {time}', 'at' => $alert->snoozed_until?->toIso8601String()],
                $alert->severity === 'critical' && $alert->acknowledged_at === null && $alert->assigned_to_user_id === null => ['text' => 'Needs someone now · '.($alert->kind === 'offline' ? 'offline' : 'open').' {for}', 'at' => $alert->opened_at->toIso8601String()],
                default => ['text' => 'Open {for}', 'at' => $alert->opened_at->toIso8601String()],
            },
            'opened_at' => $alert->opened_at->toIso8601String(),
            'closed_at' => $alert->closed_at?->toIso8601String(),
            'device' => $device ? [
                'id' => (int) $device->id,
                'name' => (string) $device->name,
                'sub' => implode(' · ', array_values(array_filter([$model ?: null, $device->ip_address ? (string) $device->ip_address : null, $alert->site?->name]))),
            ] : null,
            'facts' => array_values(array_filter([
                $where !== '' ? ['icon' => 'map-pin', 'text' => $where, 'at' => null] : null,
                ['icon' => 'clock', 'text' => '**{time}**', 'at' => $alert->opened_at->toIso8601String()],
                $device ? ['icon' => 'network', 'text' => $this->checkedBy((int) ($alert->site_id ?? 0)), 'at' => null] : null,
            ])),
            'saw' => (string) $words->text($this->saw($alert)),
            'affects' => $affected,
            'timeline' => $alert->events->map(fn (DeviceAlertEvent $event): array => [
                'id' => (int) $event->id,
                'at' => $event->at->toIso8601String(),
                'tone' => match ($event->kind) {
                    'opened', 'worse' => $alert->severity === 'critical' ? 'critical' : 'warning',
                    'reminded', 'back' => 'warning',
                    'recovered' => 'success',
                    default => 'info',
                },
                'title' => (string) $words->text($event->text),
                'sub' => $words->text($event->detail),
            ])->values()->all(),
            'acknowledged' => $alert->acknowledgedBy ? ['by' => $alert->acknowledgedBy->name, 'at' => $alert->acknowledged_at?->toIso8601String(), 'note' => $alert->acknowledgement_note] : null,
            'assigned' => $alert->assignedTo ? ['name' => $alert->assignedTo->name, 'at' => $alert->assigned_at?->toIso8601String()] : null,
            'can' => [
                'acknowledge' => $active && $viewer->canDo('securityDevices.alerts.acknowledge'),
                'assign' => $active && $viewer->canDo('securityDevices.alerts.acknowledge'),
                'snooze' => $active && $viewer->canDo('securityDevices.alerts.acknowledge'),
                'done' => $active && $viewer->canDo('securityDevices.alerts.acknowledge') && in_array($alert->kind, DeviceAlertEngine::safetyKinds(), true),
                'report_incident' => $viewer->canDo('incidents.create'),
                // S&D Phase F: a scald-risk alert raises its H&S hazard once; then it links to it.
                'raise_hazard' => $alert->kind === 'scald_risk' && $alert->hazard_id === null && $viewer->canDo('hazards.create'),
            ],
            'hazard_url' => $alert->hazard_id !== null ? '/hazards/'.$alert->hazard_id : null,
        ];
    }

    private function checkedBy(int $siteId): string
    {
        $hub = $siteId > 0 ? $this->hubs->forSite($siteId) : null;
        if ($hub === null) {
            return 'Checked by the Oblivion server';
        }
        $label = CoveringHubResolver::label($hub);

        return (int) $hub->site_id === $siteId ? "Checked by {$label}" : "{$label}, over the link to this Site";
    }

    private function saw(DeviceAlert $alert): string
    {
        $name = (string) ($alert->device?->name ?? 'The device');
        if (str_starts_with($alert->kind, 'reading_')) {
            // e.g. "Room 3 sensor: temperature 34 °C" → its reading went out of the usual range.
            return $alert->title.' at {time}, out of the usual range. It closes by itself when the reading is back.';
        }

        return match ($alert->kind) {
            'offline' => "{$name} stopped answering at {time}.",
            'hub_offline' => "{$name} stopped answering at {time}. Devices at its Sites can’t be checked until it’s back; they keep working on their own.",
            'panic' => "The panic button on {$name} was pressed at {time}. Control Room has it too.",
            'alarm' => "{$name} reported an alarm at {time}. Control Room has it too.",
            'leak' => "{$name} reported a water leak at {time}.",
            'forced' => "{$name} reported the door forced open at {time}. Control Room has it too.",
            'tamper' => "{$name} reported being tampered with at {time}.",
            'battery' => "{$name}’s battery dropped below ".DeviceAlertEngine::BATTERY_LOW."% at {time}. It closes by itself once a new battery reports.",
            'mqtt_logins' => "The sensor relay on {$name} refused a run of wrong logins at {time}.",
            'automation' => 'An automation opened this at {time}. Check, then mark it done.',
            'mqtt_exposed' => "At {time} Oblivion’s public address answered on an MQTT port. Sensor readings must never be reachable from the internet.",
            default => $alert->severity === 'critical' ? "A check on {$name} started failing at {time}." : "A check on {$name} started needing attention at {time}.",
        };
    }

    /**
     * Devices behind this one that can't be checked while it's down, in words.
     *
     * @return array{count: int, text: string}|null
     */
    private function affected(User $viewer, DeviceAlert $alert): ?array
    {
        if (! $alert->isActive()) {
            return null;
        }
        $query = Monitor::query()->where('effective_state', MonitorState::Suppressed->value)->whereNotNull('device_id');
        if ($alert->kind === 'hub_offline') {
            $hub = MonitoringCollector::query()->where('collector_device_id', $alert->device_id)->first();
            if ($hub === null) {
                return null;
            }
            $query->where('suppression_reason', 'collector_path')->where('collector_id', $hub->id);
        } else {
            $rootMonitors = Monitor::query()->where('device_id', $alert->device_id)->pluck('id');
            $query->whereIn('root_cause_monitor_id', $rootMonitors);
        }
        $deviceIds = $query->distinct()->pluck('device_id')->map(fn (mixed $id): int => (int) $id)->reject(fn (int $id): bool => $id === (int) $alert->device_id)->values()->all();
        if ($deviceIds === []) {
            return null;
        }
        $entries = $this->directory->entries($viewer, null, $deviceIds);
        if ($entries->isEmpty()) {
            return null;
        }
        $byKind = $entries->groupBy(fn (DeviceEntry $entry): string => $entry->kind->label())
            ->map(fn (Collection $group, string $label): string => $group->count() === 1 ? 'the '.lcfirst($label) : $group->count().' '.(self::PLURAL[$label] ?? lcfirst($label).'s'))
            ->sortKeys()
            ->values()
            ->all();
        $count = $entries->count();
        $what = $alert->kind === 'hub_offline' ? 'at its Sites' : 'behind it';
        $list = count($byKind) > 1 ? implode(', ', array_slice($byKind, 0, -1)).' and '.end($byKind) : $byKind[0];

        return ['count' => $count, 'text' => ($count === 1 ? "1 device {$what} can’t be checked: " : "{$count} devices {$what} can’t be checked: ").$list.'.'];
    }

    /** @return array<string, mixed> */
    private function done(User $viewer, Builder $base, int $page, CarbonImmutable $now, string $search = ''): array
    {
        $query = (clone $base)->with(['device:id,name', 'site:id,name'])
            ->where('state', DeviceAlert::STATE_DONE)->where('closed_at', '>=', $now->subDays(30));
        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            // A care alert's title names its resident: it's found by device or Site only, never by that name.
            $query->where(fn (Builder $match) => $match->where(fn (Builder $title) => $title->where('title', 'like', $like)->whereNotIn('kind', ['care_out_of_bed', 'care_door_night']))
                ->orWhereHas('device', fn (Builder $device) => $device->where('name', 'like', $like))
                ->orWhereHas('site', fn (Builder $site) => $site->where('name', 'like', $like)));
        }
        $paginator = $query->orderByDesc('closed_at')->paginate(self::DONE_PER_PAGE, ['*'], 'page', $page);
        $this->hide = SiteStream::carePeople($viewer, collect($paginator->items()));

        return [
            'rows' => collect($paginator->items())->map(fn (DeviceAlert $alert): array => [
                'id' => (int) $alert->id,
                'title' => $this->title($alert),
                'site' => $alert->site?->name,
                'opened_at' => $alert->opened_at->toIso8601String(),
                'closed_at' => $alert->closed_at?->toIso8601String(),
                'lasted' => DeviceAlertEngine::duration($alert->opened_at, $alert->closed_at ?? $now),
                'how' => match ($alert->close_reason) {
                    'behind' => 'Covered by the device in front of it',
                    default => 'Cleared by itself',
                },
            ])->all(),
            'page' => $paginator->currentPage(),
            'pages' => max(1, $paginator->lastPage()),
            'total' => $paginator->total(),
        ];
    }

    /**
     * Alerts opened per NZ week, for up to the last six weeks. Weeks before the first alert was ever
     * recorded aren't shown, so a week with nothing recorded never reads as a quiet week.
     *
     * @param  Builder<DeviceAlert>  $base
     * @return array{labels: list<string>, values: list<int>}|null
     */
    private function perWeek(Builder $base, CarbonImmutable $now): ?array
    {
        $zone = (string) config('app.worker_timezone', 'Pacific/Auckland');
        $first = (clone $base)->orderBy('opened_at')->first(['id', 'opened_at']);
        if (! $first instanceof DeviceAlert) {
            return null;
        }
        $thisWeek = $now->setTimezone($zone)->startOfWeek();
        $start = $thisWeek->subWeeks(5);
        $firstWeek = $first->opened_at->setTimezone($zone)->startOfWeek();
        if ($firstWeek->gt($start)) {
            $start = $firstWeek;
        }
        $counts = [];
        $labels = [];
        for ($week = $start; $week->lte($thisWeek); $week = $week->addWeek()) {
            $counts[$week->format('Y-m-d')] = 0;
            $labels[] = $week->format('j M');
        }
        (clone $base)->where('opened_at', '>=', $start->utc())->orderBy('id')->limit(5000)->get(['id', 'opened_at'])
            ->each(function (DeviceAlert $alert) use (&$counts, $zone): void {
                $key = $alert->opened_at->setTimezone($zone)->startOfWeek()->format('Y-m-d');
                if (array_key_exists($key, $counts)) {
                    $counts[$key]++;
                }
            });

        return ['labels' => $labels, 'values' => array_values($counts)];
    }

    /**
     * Devices Quiet time can pause and a rule can name, with their Site: network gear, computers and
     * building systems only. Cameras, alarms, doors, health kit and trackers are never offered,
     * because their alerts never pause (a life-safety rule in the monitoring engine).
     *
     * @return list<array{id: int, name: string, site_id: int, site: string, kind: string, domain: string}>
     */
    public function pickableDevices(User $viewer): array
    {
        return $this->directory->entries($viewer)
            ->filter(fn (DeviceEntry $entry): bool => $entry->siteId !== null
                && in_array((string) $entry->device->domain, ['it_infrastructure', 'facilities'], true))
            ->sortBy(fn (DeviceEntry $entry): string => mb_strtolower(($entry->siteName ?? '').' '.$entry->name()))
            ->map(fn (DeviceEntry $entry): array => [
                'id' => $entry->id(),
                'name' => $entry->name(),
                'site_id' => (int) $entry->siteId,
                'site' => (string) ($entry->siteName ?? ''),
                'kind' => $entry->kind->label(),
                'domain' => (string) $entry->device->domain,
            ])
            ->values()
            ->all();
    }

    /** @return array{text: string, caption: string|array{text: string, at: string|null}} */
    private function quietTime(User $viewer, CarbonImmutable $now): array
    {
        $visible = $this->access->visibleDevices($viewer)->select('devices.id');
        $windows = MonitoringMaintenanceWindow::query()
            ->where('status', 'active')
            ->where('ends_at', '>', $now)
            ->where(fn ($query) => $query->whereNull('device_id')->orWhereIn('device_id', $visible))
            ->whereIn('site_id', $this->access->accessibleSiteIds($viewer) ?: [0])
            ->orderBy('starts_at')
            ->limit(20)
            ->get();
        $current = $windows->filter(fn (MonitoringMaintenanceWindow $window): bool => $window->starts_at <= $now);
        $next = $windows->first(fn (MonitoringMaintenanceWindow $window): bool => $window->starts_at > $now);
        $reason = fn (MonitoringMaintenanceWindow $window): string => filled($window->reason) ? ', '.mb_substr((string) $window->reason, 0, 60) : '';

        return [
            'text' => $current->isEmpty() ? 'None now' : ($current->count() === 1 ? '1 now' : $current->count().' now'),
            'caption' => match (true) {
                $current->isNotEmpty() => ['text' => 'Until {time}'.$reason($current->first()), 'at' => $current->min('ends_at')?->toIso8601String()],
                $next !== null => ['text' => 'Next: {time}'.$reason($next), 'at' => $next->starts_at->toIso8601String()],
                default => 'Nothing planned',
            },
        ];
    }
}
