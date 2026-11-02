<?php

namespace App\Domain\SecurityDevices\Screens\Alerts;

use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorObservation;
use App\Domain\Monitoring\Models\MonitoringMaintenanceWindow;
use App\Domain\SecurityDevices\Alerts\Models\DeviceAlert;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Alerts › Quiet times (S&D drawing pg-sdr-13c): every quiet time (monitoring maintenance window) on the
 * viewer's Sites and devices, coming up first, then the ones that ended in the last 30 days, and what each
 * one held back. "Held back" is read from the checks themselves: each check keeps the quiet time that
 * paused it (maintenance_policy_evidence), so nothing here is guessed.
 *
 * Safety devices never pause: the monitoring engine never applies quiet time to cameras, alarms, doors,
 * sensors or care trackers (domains security and iot_healthcare), and a hub's own alert has no quiet time.
 */
final class QuietTimesPresenter
{
    public const int ENDED_DAYS = 30;

    /** Ended quiet times whose checks are read for "What quiet time held back". */
    private const int HELD_WINDOWS = 8;

    /** Checks read per quiet time, so one long outage can't slow the page. */
    private const int HELD_CHECKS = 2000;

    public function __construct(private readonly SecurityDevicesAccessService $access) {}

    /** Quiet times on now or still to come: the tab's count. */
    public function count(User $viewer, ?int $siteId, CarbonImmutable $now): int
    {
        return $this->windows($viewer, $siteId, $now)
            ->filter(fn (MonitoringMaintenanceWindow $window): bool => in_array($this->state($window, $now)['key'], ['now', 'soon', 'planned'], true))
            ->count();
    }

    /** @return array{rows: list<array<string, mixed>>, held: list<array<string, mixed>>, know: list<array{tone: string, text: string}>, can: array{add: bool, end: bool}} */
    /** @param  bool  $held  read what each one held back (the checks); the Site page leaves it out */
    public function present(User $viewer, ?int $siteId, CarbonImmutable $now, bool $held = true): array
    {
        $windows = $this->windows($viewer, $siteId, $now);
        $manage = $viewer->canDo('securityDevices.monitoring.manage');
        $deviceIds = $windows->pluck('device_id')->filter()->merge($windows->map(fn (MonitoringMaintenanceWindow $window): ?int => $window->monitor?->device_id))->filter()->unique()->values();
        $devices = $deviceIds->isEmpty() ? collect() : Device::query()->whereIn('id', $deviceIds)->get(['id', 'name', 'domain'])->keyBy('id');

        $rows = $windows->map(function (MonitoringMaintenanceWindow $window) use ($now, $devices, $manage): array {
            $state = $this->state($window, $now);
            $deviceId = $window->device_id ?? $window->monitor?->device_id;
            $device = $deviceId === null ? null : $devices->get((int) $deviceId);
            $occurrence = $state['occurrence'];
            if ($state['key'] === 'ended') {
                $state = [...$state, ...$this->alertsAfter($window, $occurrence, $devices)];
            }

            return [
                'id' => (int) $window->id,
                'version' => (int) $window->version,
                'scope' => [
                    'site_id' => (int) $window->site_id,
                    'device_id' => $window->device_id === null ? null : (int) $window->device_id,
                    'monitor_id' => $window->monitor_id === null ? null : (int) $window->monitor_id,
                    'monitor_device_id' => $window->monitor?->device_id === null ? null : (int) $window->monitor->device_id,
                ],
                'paused' => [
                    'name' => match (true) {
                        $device === null => 'Everything that can pause at '.($window->site?->name ?? 'this Site'),
                        $window->monitor_id !== null => $device->name.' · one check',
                        default => (string) $device->name,
                    },
                    'sub' => $device === null ? 'Network gear, computers and building systems only' : (string) ($window->site?->name ?? ''),
                    'icon' => $device === null ? 'site' : ((string) $device->domain === 'facilities' ? 'building' : 'network'),
                    'href' => $device === null ? null : '/security-devices/devices/'.$device->id,
                ],
                'when' => [
                    'starts_at' => $occurrence[0]->toIso8601String(),
                    'ends_at' => $occurrence[1]->toIso8601String(),
                    'repeats' => $window->recurrence,
                    'until' => $window->recurrence_until?->toIso8601String(),
                ],
                'why' => filled($window->reason) ? (string) $window->reason : (string) $window->name,
                'set_by' => $window->creator?->name,
                'state' => ['label' => $state['label'], 'tone' => $state['tone'], 'note' => $state['note']],
                // Ending one early is the same safe save as Settings had (cancel, with a reason).
                'can_end' => $manage && in_array($state['key'], ['now', 'soon', 'planned'], true),
                'ends_now' => $state['key'] === 'now',
                'live' => in_array($state['key'], ['now', 'soon', 'planned'], true),
                'sort' => match ($state['key']) {
                    'now' => '0'.$occurrence[1]->format('YmdHis'),
                    'soon', 'planned' => '1'.$occurrence[0]->format('YmdHis'),
                    default => '2'.str_pad((string) (PHP_INT_MAX - $occurrence[1]->getTimestamp()), 20, '0', STR_PAD_LEFT),
                },
            ];
        })->sortBy('sort')->values()->map(function (array $row): array {
            unset($row['sort']);

            return $row;
        })->all();

        return [
            'rows' => $rows,
            'held' => $held ? $this->held($windows, $now, $devices) : [],
            'know' => [
                ['tone' => 'info', 'text' => 'Alerts for the paused devices wait. If one is still down when quiet time ends, its alert goes out at its next check'],
                ['tone' => 'info', 'text' => 'Network gear and computers behind a paused device wait too. **Cameras, alarms, doors, sensors and care trackers never pause**, even behind it'],
                ['tone' => 'warning', 'text' => 'A camera or sensor behind a paused switch or router can still raise an alert while it restarts. Plan the work for a quiet part of the day'],
                ['tone' => 'info', 'text' => 'Hubs update in their own night window and don’t need quiet time. A hub that stops answering still raises its own alert'],
            ],
            'can' => ['add' => $manage, 'end' => $manage],
        ];
    }

    /** @return Collection<int, MonitoringMaintenanceWindow> */
    private function windows(User $viewer, ?int $siteId, CarbonImmutable $now): Collection
    {
        $siteIds = $siteId === null ? $this->access->accessibleSiteIds($viewer) : [$siteId];
        if ($siteIds === []) {
            return collect();
        }
        $visible = ($siteId === null ? $this->access->visibleDevices($viewer) : $this->access->visibleDevicesForSite($viewer, $siteId))->select('devices.id');
        $since = $now->subDays(self::ENDED_DAYS);

        return MonitoringMaintenanceWindow::query()
            ->with(['site:id,name', 'monitor:id,device_id', 'creator:id,name', 'cancelledBy:id,name'])
            ->whereIn('site_id', $siteIds)
            ->where(fn (Builder $query) => $query->whereNull('device_id')->orWhereIn('device_id', $visible))
            ->where(fn (Builder $query) => $query->whereNull('monitor_id')->orWhereIn('monitor_id', Monitor::query()->whereIn('device_id', (clone $visible))->select('id')))
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $open) => $open->whereNull('cancelled_at')->where(fn (Builder $end) => $end
                    ->where('ends_at', '>=', $since)
                    ->orWhere('recurrence_until', '>=', $since)))
                ->orWhere('cancelled_at', '>=', $since))
            ->orderByDesc('starts_at')
            ->limit(60)
            ->get();
    }

    /**
     * Where a quiet time stands now, with the occurrence it shows (the current or next one for a repeating
     * quiet time, or its last one once it has ended).
     *
     * @return array{key: string, label: string, tone: string, note: string|array{text: string, at: string|null}, occurrence: array{CarbonImmutable, CarbonImmutable}}
     */
    private function state(MonitoringMaintenanceWindow $window, CarbonImmutable $now): array
    {
        $occurrence = $this->occurrence($window, $window->cancelled_at ?? $now);
        [$start, $end] = $occurrence;
        $setAt = $window->created_at === null ? null : CarbonImmutable::parse($window->created_at)->utc();
        $by = $window->cancelledBy?->name;

        if ($window->cancelled_at !== null) {
            $cancelled = CarbonImmutable::instance($window->cancelled_at)->utc();
            $began = $cancelled >= $window->starts_at;

            return [
                'key' => $began ? 'ended_early' : 'cancelled',
                'label' => $began ? 'Ended early' : 'Cancelled',
                'tone' => $began ? 'success' : 'neutral',
                'note' => ['text' => ($began ? 'Ended {time}' : 'Cancelled {time}').($by ? ' by '.$by : ''), 'at' => $cancelled->toIso8601String()],
                'occurrence' => $began ? [$start, min($end, $cancelled)] : $occurrence,
            ];
        }
        if ($window->status === 'completed' || $end <= $now) {
            return ['key' => 'ended', 'label' => 'Ended', 'tone' => 'success', 'note' => '', 'occurrence' => $occurrence];
        }
        if ($start <= $now) {
            return ['key' => 'now', 'label' => 'On now', 'tone' => 'warning', 'note' => ['text' => 'Until {time}', 'at' => $end->toIso8601String()], 'occurrence' => $occurrence];
        }
        $set = $setAt === null ? '' : ['text' => 'Set {time}', 'at' => $setAt->toIso8601String()];
        $minutes = (int) ceil(($start->getTimestamp() - $now->getTimestamp()) / 60);
        if ($minutes <= 24 * 60) {
            $words = $minutes < 60 ? $minutes.' min' : (intdiv($minutes, 60).' h'.($minutes % 60 > 0 && $minutes < 600 ? ' '.($minutes % 60).' min' : ''));

            return ['key' => 'soon', 'label' => 'Starts in '.$words, 'tone' => 'info', 'note' => $set, 'occurrence' => $occurrence];
        }

        return ['key' => 'planned', 'label' => 'Planned', 'tone' => 'neutral', 'note' => $set, 'occurrence' => $occurrence];
    }

    /**
     * The occurrence that covers $at, else the next one, else the last one. A repeating quiet time repeats on
     * its own wall clock (its time zone), so a daily 2:00 am stays 2:00 am across daylight saving.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function occurrence(MonitoringMaintenanceWindow $window, CarbonImmutable $at): array
    {
        $start = CarbonImmutable::instance($window->starts_at)->utc();
        $end = CarbonImmutable::instance($window->ends_at)->utc();
        if ($window->recurrence === null || $window->recurrence_until === null || $at < $end) {
            return [$start, $end];
        }
        $zone = (string) ($window->timezone ?: 'UTC');
        $step = $window->recurrence === 'weekly' ? 7 : 1;
        $length = $end->getTimestamp() - $start->getTimestamp();
        $until = CarbonImmutable::instance($window->recurrence_until)->utc();
        $last = [$start, $end];
        for ($n = 1; $n <= 400; $n++) {
            $from = $start->setTimezone($zone)->addDays($n * $step)->utc();
            if ($from > $until) {
                break;
            }
            $to = $from->addSeconds($length);
            $last = [$from, $to];
            if ($at < $to) {
                break;
            }
        }

        return $last;
    }

    /**
     * Whether an alert went out for the paused device(s) during an ended quiet time or just after it.
     *
     * @param  array{CarbonImmutable, CarbonImmutable}  $occurrence
     * @param  Collection<int, Device>  $devices
     * @return array{tone: string, note: string}
     */
    private function alertsAfter(MonitoringMaintenanceWindow $window, array $occurrence, Collection $devices): array
    {
        $deviceId = $window->device_id ?? $window->monitor?->device_id;
        $count = DeviceAlert::query()
            ->whereIn('kind', ['offline', 'failing'])
            ->whereBetween('opened_at', [$occurrence[0], $occurrence[1]->addMinutes(15)])
            ->when($deviceId !== null,
                fn (Builder $query) => $query->where('device_id', (int) $deviceId),
                fn (Builder $query) => $query->where('site_id', (int) $window->site_id)
                    ->whereIn('device_id', Device::query()->whereIn('domain', ['it_infrastructure', 'facilities'])->select('id')))
            ->count();

        return $count === 0
            ? ['tone' => 'success', 'note' => 'No alerts went out for it']
            : ['tone' => 'warning', 'note' => ($count === 1 ? '1 alert' : $count.' alerts').' went out: still down when it ended'];
    }

    /**
     * What quiet time held back: the checks that failed while a quiet time paused them, newest quiet time
     * first, one line per device, and when the device answered again.
     *
     * @param  Collection<int, MonitoringMaintenanceWindow>  $windows
     * @param  Collection<int, Device>  $devices
     * @return list<array{at: string, tone: string, title: string, sub: string|array{text: string, at: string|null}}>
     */
    private function held(Collection $windows, CarbonImmutable $now, Collection $devices): array
    {
        $lines = [];
        $read = $windows
            ->filter(fn (MonitoringMaintenanceWindow $window): bool => CarbonImmutable::instance($window->starts_at) <= $now)
            ->take(self::HELD_WINDOWS);
        foreach ($read as $window) {
            $until = min($now, CarbonImmutable::instance($window->recurrence_until ?? $window->ends_at), $window->cancelled_at === null ? $now : CarbonImmutable::instance($window->cancelled_at));
            $checks = MonitorObservation::query()
                ->where('site_id', (int) $window->site_id)
                ->whereBetween('observed_at', [CarbonImmutable::instance($window->starts_at), $until])
                ->whereIn('state', [MonitorState::Failed->value, MonitorState::Degraded->value, MonitorState::Unknown->value])
                ->when($window->device_id !== null, fn (Builder $query) => $query->where('device_id', (int) $window->device_id))
                ->when($window->monitor_id !== null, fn (Builder $query) => $query->where('monitor_id', (int) $window->monitor_id))
                ->orderBy('observed_at')
                ->limit(self::HELD_CHECKS)
                ->get(['id', 'monitor_id', 'device_id', 'state', 'observed_at', 'maintenance_policy_evidence']);
            $paused = $checks->filter(fn (MonitorObservation $check): bool => ($check->maintenance_policy_evidence['state'] ?? null) === 'applied'
                && (int) ($check->maintenance_policy_evidence['winner']['snapshot']['id'] ?? 0) === (int) $window->id);
            foreach ($paused->groupBy('device_id') as $deviceId => $group) {
                $first = CarbonImmutable::instance($group->first()->observed_at)->utc();
                $last = CarbonImmutable::instance($group->last()->observed_at)->utc();
                $back = MonitorObservation::query()
                    ->where('monitor_id', (int) $group->last()->monitor_id)
                    ->where('observed_at', '>', $last)
                    ->where('state', MonitorState::Healthy->value)
                    ->orderBy('observed_at')
                    ->value('observed_at');
                $back = $back === null ? null : CarbonImmutable::parse($back)->utc();
                $name = $devices->get((int) $deviceId)?->name ?? Device::query()->whereKey((int) $deviceId)->value('name') ?? 'A device';
                $missed = $group->count();
                $backInTime = $back !== null && $back <= $until->addMinutes(15);
                $lines[] = [
                    'at' => $first->toIso8601String(),
                    'tone' => $backInTime ? 'success' : 'warning',
                    'title' => $name.($missed === 1 ? ' missed 1 check' : " missed {$missed} checks"),
                    'sub' => $backInTime
                        ? ['text' => 'Held while quiet time was on · answering again {time}', 'at' => $back->toIso8601String()]
                        : 'Held while quiet time was on · still down when it ended, so its alert went out',
                ];
            }
        }

        usort($lines, fn (array $a, array $b): int => strcmp($b['at'], $a['at']));

        return array_slice($lines, 0, 8);
    }
}
