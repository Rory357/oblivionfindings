<?php

namespace App\Domain\SecurityDevices\Screens\Overview;

use App\Domain\Monitoring\Discovery\Models\DiscoveryRun;
use App\Domain\Monitoring\Models\MonitorObservation;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Hubs\Services\CoveringHubResolver;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Screens\DeviceKind;
use App\Domain\SecurityDevices\Screens\DeviceState;
use App\Domain\SecurityDevices\Screens\Devices\DeviceDirectory;
use App\Domain\SecurityDevices\Screens\Devices\DeviceEntry;
use App\Domain\SecurityDevices\Screens\Sites\SitesIndexPresenter;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\ControlRoomAlert;
use App\Models\Site;
use App\Models\User;
use App\Services\ControlRoom\ControlRoomAlertAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Security & devices home (S&D rebuild drawings pg-sdr-1 with its first-time view 1b, and the
 * Trends tab pg-sdr-18). Scoped by SecurityDevicesAccessService. The support worker's view (1c)
 * and the Reports tab (18b) come later; the Activity tab (18c) waits for its drawing's approval.
 */
final class OverviewPresenter
{
    public const array TABS = ['overview', 'trends'];

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly DeviceDirectory $directory,
        private readonly CoveringHubResolver $hubs,
        private readonly ControlRoomAlertAccessService $alertAccess,
        private readonly SitesIndexPresenter $sites,
    ) {}

    /**
     * @param  array{tab?: mixed, site?: mixed}  $input
     * @return array<string, mixed>
     */
    public function present(User $viewer, array $input): array
    {
        $tab = in_array($input['tab'] ?? null, self::TABS, true) ? $input['tab'] : 'overview';
        $siteId = is_scalar($input['site'] ?? null) && ctype_digit((string) $input['site']) ? (int) $input['site'] : null;
        if ($siteId !== null) {
            $this->access->assertCanViewSite($viewer, $siteId);
        }
        $siteOptions = $this->access->accessibleSites($viewer)
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('archived')->orWhere('archived', false))
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get(['id', 'name']);
        $siteIds = $siteId !== null ? [$siteId] : $siteOptions->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $entries = $this->directory->entries($viewer, $siteId);
        $hubs = collect($siteIds)
            ->map(fn (int $id): ?MonitoringCollector => $this->hubs->forSite($id))
            ->filter()
            ->unique(fn (MonitoringCollector $hub): int => (int) $hub->id)
            ->values();
        $alerts = $this->alerts($viewer, $entries);
        $firstTime = $siteId === null && $entries->isEmpty() && $hubs->isEmpty();

        return [
            'tab' => $tab,
            'site' => $siteId === null ? 'all' : (string) $siteId,
            'sites' => $siteOptions->map(fn (Site $site): array => ['value' => (string) $site->id, 'label' => (string) $site->name])->all(),
            'first_time' => $firstTime ? $this->firstTime($siteOptions->count(), $siteIds) : null,
            'meters' => $this->meters($entries, $alerts),
            'overview' => $tab === 'overview' && ! $firstTime ? $this->overview($viewer, $entries, $hubs, $alerts, $siteId) : null,
            'trends' => $tab === 'trends' ? $this->trends($viewer, $entries, $alerts) : null,
            'can' => [
                'add' => $viewer->canDo('securityDevices.devices.create'),
                'scan' => $viewer->canDo('securityDevices.integrations.manage'),
                'connect' => $viewer->canDo('securityDevices.integrations.manage'),
                'assign' => $viewer->canDo('securityDevices.devices.assign'),
            ],
        ];
    }

    /**
     * @param  Collection<int, DeviceEntry>  $entries
     * @param  list<array<string, mixed>>  $alerts
     * @return array<string, mixed>
     */
    private function meters(Collection $entries, array $alerts): array
    {
        $watched = $entries->filter(fn (DeviceEntry $entry): bool => $entry->state !== DeviceState::NotWatched);
        $online = $watched->filter(fn (DeviceEntry $entry): bool => $entry->state === DeviceState::Online);
        $offline = $entries->filter(fn (DeviceEntry $entry): bool => $entry->state === DeviceState::Offline)
            ->sortBy(fn (DeviceEntry $entry) => $entry->offlineSince?->getTimestamp() ?? PHP_INT_MAX)
            ->values();
        $cantCheck = $entries->filter(fn (DeviceEntry $entry): bool => $entry->state === DeviceState::CantCheck);
        $checked = $watched->map(fn (DeviceEntry $entry) => $entry->lastCheckedAt)->filter()->max();
        $behind = $cantCheck->pluck('behind')->filter()->unique()->values();
        $first = $offline->first();

        return [
            'online' => [
                'count' => $online->count(),
                'of' => $watched->count(),
                'caption' => $watched->isEmpty()
                    ? ['text' => 'Add a hub to start checking', 'at' => null]
                    : ['text' => $checked !== null ? 'Checked {ago}' : 'Not checked yet', 'at' => $checked?->toIso8601String()],
            ],
            'offline' => [
                'count' => $offline->count(),
                'caption' => match (true) {
                    $first === null => ['text' => 'Nothing offline', 'at' => null],
                    $first->offlineSince !== null => [
                        'text' => $first->name().', since {time}'.($offline->count() > 1 ? ' and '.($offline->count() - 1).' more' : ''),
                        'at' => $first->offlineSince->toIso8601String(),
                    ],
                    default => ['text' => $first->name().($offline->count() > 1 ? ' and '.($offline->count() - 1).' more' : ''), 'at' => null],
                },
            ],
            'cant_check' => [
                'count' => $cantCheck->count(),
                'caption' => match (true) {
                    $cantCheck->isEmpty() => 'Everything can be checked',
                    $behind->count() === 1 => 'Behind '.$behind->first(),
                    default => 'Behind a router or hub that’s down',
                },
            ],
            'alerts' => [
                'count' => count($alerts),
                'caption' => $alerts === [] ? 'None open' : $alerts[0]['title'].(count($alerts) > 1 ? ' and '.(count($alerts) - 1).' more' : ''),
            ],
        ];
    }

    /**
     * @param  Collection<int, DeviceEntry>  $entries
     * @param  Collection<int, MonitoringCollector>  $hubs
     * @param  list<array<string, mixed>>  $alerts
     * @return array<string, mixed>
     */
    private function overview(User $viewer, Collection $entries, Collection $hubs, array $alerts, ?int $siteId): array
    {
        // Needs attention: device problems and open device alerts, newest first.
        $attention = collect($alerts)->map(fn (array $alert): array => [
            'key' => 'alert-'.$alert['id'],
            'at' => $alert['at'],
            'tone' => 'warning',
            'title' => $alert['title'],
            'sub' => trim($alert['device'].($alert['site'] !== '' ? ' · '.$alert['site'] : ''), ' ·'),
            'href' => $alert['href'],
            'action' => 'Open alert',
        ]);
        foreach ($entries as $entry) {
            if (! $entry->needsAttention() || $entry->state === DeviceState::CantCheck) {
                continue;
            }
            $attention->push([
                'key' => 'device-'.$entry->id(),
                'at' => ($entry->offlineSince ?? $entry->lastCheckedAt)?->toIso8601String(),
                'tone' => $entry->state === DeviceState::Offline ? 'critical' : 'warning',
                'title' => match (true) {
                    $entry->state === DeviceState::Offline => $entry->name().' is offline',
                    $entry->state === DeviceState::Problem => $entry->name().($entry->failingCheck !== null ? ': '.$entry->failingCheck : ' needs attention'),
                    default => $entry->name().' battery is at '.$entry->battery().'%',
                },
                'sub' => trim($entry->subline().($entry->siteName !== null ? ' · '.$entry->siteName : ''), ' ·'),
                'href' => '/security-devices/devices/'.$entry->id(),
                'action' => 'Open device',
            ]);
        }

        $cameras = $entries->filter(fn (DeviceEntry $entry): bool => $entry->kind === DeviceKind::Camera)->values();
        $notInstalled = $hubs->filter(fn (MonitoringCollector $hub): bool => in_array(
            is_array($hub->update_state) ? ($hub->update_state['outcome'] ?? null) : null,
            ['rolled_back', 'failed'],
            true,
        ))->count();
        $hubsOnline = $hubs->filter(fn (MonitoringCollector $hub): bool => in_array((string) $hub->status, ['online', 'degraded'], true))->count();
        $siteCards = collect($this->sites->present($viewer, [])['cards'])
            ->filter(fn (array $card): bool => $siteId === null || $card['id'] === $siteId)
            ->sortBy(fn (array $card): string => ($card['needs_attention'] ? '0' : '1').mb_strtolower($card['name']))
            ->take(6)
            ->values()
            ->all();

        return [
            'attention' => $attention
                ->sortByDesc(fn (array $row): string => (string) $row['at'])
                ->take(8)
                ->values()
                ->all(),
            'attention_count' => $attention->count(),
            'sites' => $siteCards,
            'cameras' => $cameras->take(6)->map(fn (DeviceEntry $entry): array => [
                'id' => $entry->id(),
                'name' => $entry->name(),
                'site' => $entry->siteName,
                'state' => $entry->state->value,
                'href' => '/security-devices/devices/'.$entry->id(),
            ])->all(),
            'camera_count' => $cameras->count(),
            'hubs' => $hubs->isEmpty() ? null : [
                'online' => $hubsOnline,
                'offline' => $hubs->count() - $hubsOnline,
                'not_installed' => $notInstalled,
                'calm' => $hubsOnline === $hubs->count() && $notInstalled === 0
                    ? ($hubs->count() === 1 ? 'The hub is answering' : 'All '.$hubs->count().' hubs are answering')
                    : null,
            ],
            'activity' => $this->activity($entries, 6),
        ];
    }

    /**
     * The four steps a new organisation goes through, with how far it has got.
     *
     * @param  list<int>  $siteIds
     * @return array<string, mixed>
     */
    private function firstTime(int $siteCount, array $siteIds): array
    {
        $scanned = $siteIds !== [] && DiscoveryRun::query()
            ->whereHas('scope', fn ($scope) => $scope->whereIn('site_id', $siteIds))
            ->whereNotNull('completed_at')
            ->exists();

        return [
            'site_count' => $siteCount,
            'steps' => [
                ['label' => 'Add a hub', 'meta' => 'Download it for your Site, then Next, Next, Finish. Nothing to type', 'state' => 'now'],
                ['label' => 'Scan your network', 'meta' => 'The hub looks for cameras, network gear, printers and more', 'state' => $scanned ? 'done' : 'todo'],
                ['label' => 'Add the devices you want', 'meta' => 'Tick them, or add them all', 'state' => 'todo'],
                ['label' => 'Turn on alerts', 'meta' => 'Choose who is told when something goes offline', 'state' => 'todo'],
            ],
        ];
    }

    /**
     * @param  Collection<int, DeviceEntry>  $entries
     * @param  list<array<string, mixed>>  $alerts
     * @return array<string, mixed>
     */
    private function trends(User $viewer, Collection $entries, array $alerts): array
    {
        $now = CarbonImmutable::now();

        return [
            'online' => $this->onlineByDay($entries, $now),
            'kinds' => $entries
                ->groupBy(fn (DeviceEntry $entry): string => $entry->tab())
                ->map(fn (Collection $group, string $tab): array => ['tab' => $tab, 'count' => $group->count()])
                ->sortByDesc('count')
                ->values()
                ->all(),
            'device_count' => $entries->count(),
            'alerts_per_week' => $this->alertsPerWeek($viewer, $entries, $now),
            'batteries' => $entries
                ->filter(fn (DeviceEntry $entry): bool => $entry->battery() !== null && $entry->battery() <= 35)
                ->sortBy(fn (DeviceEntry $entry): int => (int) $entry->battery())
                ->take(5)
                ->map(fn (DeviceEntry $entry): array => [
                    'id' => $entry->id(),
                    'name' => $entry->name(),
                    'site' => $entry->siteName,
                    'battery' => $entry->battery(),
                    'href' => '/security-devices/devices/'.$entry->id(),
                ])
                ->values()
                ->all(),
            'slowest' => $this->slowest($entries, $now),
        ];
    }

    /**
     * For each NZ day of the last 30, how many watched devices answered all day, and how many were
     * watched. Counted in the database; only devices with checks are included.
     *
     * @param  Collection<int, DeviceEntry>  $entries
     * @return array{labels: list<string>, online: list<int|null>, watched: list<int|null>, note: string}|null
     */
    private function onlineByDay(Collection $entries, CarbonImmutable $now): ?array
    {
        $byMonitor = [];
        foreach ($entries as $entry) {
            foreach ($entry->monitorIds as $monitorId) {
                $byMonitor[$monitorId] = $entry->id();
            }
        }
        if ($byMonitor === []) {
            return null;
        }
        $firstDay = $now->setTimezone('Pacific/Auckland')->startOfDay()->subDays(29);
        $offset = $now->setTimezone('Pacific/Auckland')->getOffset();
        $dayIndex = [];
        $labels = [];
        for ($day = 0; $day < 30; $day++) {
            $date = $firstDay->addDays($day);
            $dayIndex[$date->format('Y-m-d')] = $day;
            $labels[] = $date->format('j M');
        }
        $failedByDevice = [];
        $seenByDevice = [];
        foreach (array_chunk(array_keys($byMonitor), 500) as $chunk) {
            $rows = MonitorObservation::query()
                ->whereIn('monitor_id', $chunk)
                ->where('observed_at', '>=', $firstDay->utc())
                ->whereIn('state', ['healthy', 'degraded', 'failed'])
                ->selectRaw('monitor_id, DATE(DATE_ADD(observed_at, INTERVAL ? SECOND)) AS nz_day, SUM(state <> ?) AS bad, COUNT(*) AS seen', [$offset, 'healthy'])
                ->groupBy('monitor_id', 'nz_day')
                ->toBase()
                ->get();
            foreach ($rows as $row) {
                $day = $dayIndex[(string) $row->nz_day] ?? null;
                $device = $byMonitor[(int) $row->monitor_id] ?? null;
                if ($day === null || $device === null) {
                    continue;
                }
                $seenByDevice[$day][$device] = true;
                if ((int) $row->bad > 0) {
                    $failedByDevice[$day][$device] = true;
                }
            }
        }
        $online = [];
        $watched = [];
        $worst = null;
        for ($day = 0; $day < 30; $day++) {
            $seen = count($seenByDevice[$day] ?? []);
            $bad = count($failedByDevice[$day] ?? []);
            $watched[] = $seen === 0 ? null : $seen;
            $online[] = $seen === 0 ? null : $seen - $bad;
            if ($seen > 0 && $bad > 0 && ($worst === null || $bad > $worst['bad'])) {
                $worst = ['day' => $labels[$day], 'bad' => $bad];
            }
        }

        return [
            'labels' => $labels,
            'online' => $online,
            'watched' => $watched,
            'note' => $worst === null
                ? 'Every watched device answered all day, every day.'
                : 'Most devices with a missed check: '.$worst['bad'].' on '.$worst['day'].'.',
        ];
    }

    /**
     * Open and closed device alerts the viewer may read, per week, for the last six weeks.
     *
     * @param  Collection<int, DeviceEntry>  $entries
     * @return array{labels: list<string>, values: list<int>}|null
     */
    private function alertsPerWeek(User $viewer, Collection $entries, CarbonImmutable $now): ?array
    {
        if ($entries->isEmpty() || (! $viewer->canDo('controlRoom.viewAny') && ! $viewer->canDo('controlRoom.alerts.view'))) {
            return null;
        }
        $start = $now->setTimezone('Pacific/Auckland')->startOfWeek()->subWeeks(5);
        $alerts = ControlRoomAlert::query()
            ->whereHas('device', fn ($device) => $device->whereIn('canonical_device_id', $entries->map(fn (DeviceEntry $entry): int => $entry->id())->all()))
            ->where('created_at', '>=', $start->utc())
            ->limit(5000)
            ->get(['id', 'created_at', 'device_id', 'site_id', 'status']);
        $readable = $this->alertAccess->readableIds($alerts, $viewer)->flip();
        $values = array_fill(0, 6, 0);
        $labels = [];
        for ($week = 0; $week < 6; $week++) {
            $labels[] = $start->addWeeks($week)->format('j M');
        }
        foreach ($alerts as $alert) {
            if (! $readable->has((int) $alert->id) || $alert->created_at === null) {
                continue;
            }
            $week = intdiv((int) $start->diffInSeconds(CarbonImmutable::parse($alert->created_at), false), 7 * 86_400);
            if ($week >= 0 && $week < 6) {
                $values[$week]++;
            }
        }

        return ['labels' => $labels, 'values' => $values];
    }

    /**
     * The five slowest-answering devices this week, by average reply time, with their usual
     * (the 30 days before).
     *
     * @param  Collection<int, DeviceEntry>  $entries
     * @return list<array<string, mixed>>
     */
    private function slowest(Collection $entries, CarbonImmutable $now): array
    {
        $byMonitor = [];
        foreach ($entries as $entry) {
            foreach ($entry->monitorIds as $monitorId) {
                $byMonitor[$monitorId] = $entry;
            }
        }
        if ($byMonitor === []) {
            return [];
        }
        $weekAgo = $now->subDays(7);
        $averages = [];
        foreach (array_chunk(array_keys($byMonitor), 500) as $chunk) {
            $rows = MonitorObservation::query()
                ->whereIn('monitor_id', $chunk)
                ->where('observed_at', '>=', $now->subDays(37))
                ->whereNotNull('latency_ms')
                ->selectRaw('monitor_id, AVG(CASE WHEN observed_at >= ? THEN latency_ms END) AS week, AVG(CASE WHEN observed_at < ? THEN latency_ms END) AS usual', [$weekAgo, $weekAgo])
                ->groupBy('monitor_id')
                ->toBase()
                ->get();
            foreach ($rows as $row) {
                $entry = $byMonitor[(int) $row->monitor_id] ?? null;
                if ($entry === null || $row->week === null) {
                    continue;
                }
                $id = $entry->id();
                if (! isset($averages[$id]) || (float) $row->week > $averages[$id]['week']) {
                    $averages[$id] = ['entry' => $entry, 'week' => (float) $row->week, 'usual' => $row->usual === null ? null : (float) $row->usual];
                }
            }
        }

        return collect($averages)
            ->sortByDesc('week')
            ->take(5)
            ->map(fn (array $row): array => [
                'id' => $row['entry']->id(),
                'name' => $row['entry']->name(),
                'model' => $row['entry']->model(),
                'kind' => $row['entry']->kind->value,
                'site' => $row['entry']->siteName,
                'week_ms' => (int) round($row['week']),
                'usual_ms' => $row['usual'] === null ? null : (int) round($row['usual']),
                'href' => '/security-devices/devices/'.$row['entry']->id(),
            ])
            ->values()
            ->all();
    }

    /**
     * The latest things that happened to the viewer's devices.
     *
     * @param  Collection<int, DeviceEntry>  $entries
     * @return list<array<string, mixed>>
     */
    private function activity(Collection $entries, int $limit): array
    {
        if ($entries->isEmpty()) {
            return [];
        }
        $byId = $entries->keyBy(fn (DeviceEntry $entry): int => $entry->id());

        return DeviceEvent::query()
            ->whereIn('device_id', $byId->keys()->all())
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'device_id', 'event_type', 'severity', 'occurred_at'])
            ->map(function (DeviceEvent $event) use ($byId): array {
                $entry = $byId->get((int) $event->device_id);

                return [
                    'id' => (int) $event->id,
                    'at' => $event->occurred_at?->toIso8601String(),
                    'tone' => match ((string) $event->severity) {
                        'critical', 'error', 'high' => 'critical',
                        'warning', 'medium' => 'warning',
                        default => 'info',
                    },
                    'title' => self::eventWords((string) $event->event_type),
                    'sub' => trim(($entry?->name() ?? '').($entry?->siteName !== null ? ' · '.$entry->siteName : ''), ' ·'),
                    'href' => '/security-devices/devices/'.$event->device_id,
                ];
            })
            ->all();
    }

    /**
     * Open alerts about the viewer's devices that they may read, newest first.
     *
     * @param  Collection<int, DeviceEntry>  $entries
     * @return list<array{id: int, title: string, at: ?string, device: string, site: string, href: string}>
     */
    private function alerts(User $viewer, Collection $entries): array
    {
        if ($entries->isEmpty() || (! $viewer->canDo('controlRoom.viewAny') && ! $viewer->canDo('controlRoom.alerts.view'))) {
            return [];
        }
        $byId = $entries->keyBy(fn (DeviceEntry $entry): int => $entry->id());
        $alerts = ControlRoomAlert::query()
            ->whereIn('status', ControlRoomAlert::ACTIVE_STATUSES)
            ->whereHas('device', fn ($device) => $device->whereIn('canonical_device_id', $byId->keys()->all()))
            ->with('device:id,canonical_device_id')
            ->orderByDesc('id')
            ->limit(200)
            ->get(['id', 'alert_type', 'device_id', 'status', 'created_at']);
        $readable = $this->alertAccess->readableIds($alerts, $viewer)->flip();

        return $alerts
            ->filter(fn (ControlRoomAlert $alert): bool => $readable->has((int) $alert->id))
            ->map(function (ControlRoomAlert $alert) use ($byId): array {
                $entry = $byId->get((int) $alert->device?->canonical_device_id);

                return [
                    'id' => (int) $alert->id,
                    'title' => trim((string) $alert->alert_type) !== '' ? trim((string) $alert->alert_type) : 'An alert is open',
                    'at' => $alert->created_at?->toIso8601String(),
                    'device' => (string) ($entry?->name() ?? ''),
                    'site' => (string) ($entry?->siteName ?? ''),
                    'href' => '/control-room/alerts/'.$alert->id,
                ];
            })
            ->values()
            ->all();
    }

    /** "device_offline" → "Device offline". */
    private static function eventWords(string $type): string
    {
        $words = Str::of($type)->replace(['.', '_', '-'], ' ')->squish()->lower()->toString();

        return $words === '' ? 'Something changed' : Str::ucfirst($words);
    }
}
