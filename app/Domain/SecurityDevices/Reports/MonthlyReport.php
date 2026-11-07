<?php

namespace App\Domain\SecurityDevices\Reports;

use App\Domain\Monitoring\Models\Monitor;
use App\Domain\SecurityDevices\Cctv\Models\CctvSource;
use App\Domain\SecurityDevices\Alerts\Models\DeviceAlert;
use App\Domain\SecurityDevices\Cctv\Models\CameraPrivacyHours;
use App\Domain\SecurityDevices\Cctv\Models\CctvLiveSession;
use App\Domain\SecurityDevices\Cctv\Models\CctvRecordingReference;
use App\Domain\SecurityDevices\Cctv\Services\CctvContract;
use App\Domain\SecurityDevices\Cctv\Services\CctvRecordingReferences;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Screens\DeviceKind;
use App\Domain\SecurityDevices\Screens\DeviceState;
use App\Domain\SecurityDevices\Screens\Devices\DeviceDirectory;
use App\Domain\SecurityDevices\Screens\Devices\DeviceEntry;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * Overview › Reports (S&D rebuild drawing pg-sdr-18b): the monthly report for managers and the board.
 * Time offline by Site, alerts by kind, how fast safety alerts are picked up, upkeep and camera
 * picture time, then the camera viewing log. Months are NZ calendar months, compared in UTC.
 *
 * Everything is read from what Oblivion recorded, for the devices this person may see now
 * (SecurityDevicesAccessService), and a figure with nothing behind it is null ("Not available"),
 * never a guess. The camera viewing log is only for people who may see camera pictures (live views)
 * or recordings (saved clips). The same figures feed the page, the PDF, the CSV and the email.
 */
final class MonthlyReport
{
    public const string NZ = 'Pacific/Auckland';

    public const string VIEW = 'securityDevices.reports.view';

    /** Months to choose from, this one included (like readings and graphs, 13 months). */
    public const int MONTHS = 13;

    /** Weeks on the "time to pick up safety alerts" graph, ending with the report month's last week. */
    public const int SAFETY_WEEKS = 13;

    /** Rows of the camera viewing log on the page; Export has them all. */
    public const int LOG_ROWS = 50;

    /** Safety alerts: from the device itself (DeviceAlertEngine::SAFETY_EVENTS) and care plan sensors. */
    public const array SAFETY_KINDS = ['panic', 'duress', 'fall', 'alarm', 'leak', 'forced', 'tamper', 'care_out_of_bed', 'care_door_night', 'scald_risk'];

    /** What "routers and gateways" means for time offline by Site. */
    private const array NETWORK_KINDS = [DeviceKind::Router, DeviceKind::SensorGateway];

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly DeviceDirectory $directory,
    ) {}

    public static function canView(User $viewer): bool
    {
        return $viewer->canDo(self::VIEW);
    }

    /**
     * The NZ calendar month asked for (YYYY-MM), within the last 13; otherwise this month.
     *
     * @return array{key: string, label: string, short: string, range: string, start: CarbonImmutable, end: CarbonImmutable, current: bool}
     */
    public function month(mixed $key, CarbonImmutable $now): array
    {
        $first = $now->setTimezone(self::NZ)->startOfMonth();
        $chosen = $first;
        if (is_string($key) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $key) === 1) {
            $candidate = CarbonImmutable::createFromFormat('!Y-m', $key, self::NZ);
            if ($candidate instanceof CarbonImmutable && $candidate->gte($first->subMonths(self::MONTHS - 1)) && $candidate->lte($first)) {
                $chosen = $candidate;
            }
        }
        $end = $chosen->addMonth();

        return [
            'key' => $chosen->format('Y-m'),
            'label' => $chosen->format('F Y'),
            'short' => $chosen->format('F'),
            'range' => '1–'.$chosen->daysInMonth.' '.$chosen->format('M'),
            'start' => $chosen->utc(),
            'end' => $end->utc(),
            'current' => $chosen->equalTo($first),
        ];
    }

    /** @return list<array{value: string, label: string}> */
    public function months(CarbonImmutable $now): array
    {
        $first = $now->setTimezone(self::NZ)->startOfMonth();
        $out = [];
        for ($i = 0; $i < self::MONTHS; $i++) {
            $month = $first->subMonths($i);
            $out[] = ['value' => $month->format('Y-m'), 'label' => $month->format('F Y')];
        }

        return $out;
    }

    /**
     * The Reports tab.
     *
     * @param  Collection<int, DeviceEntry>|null  $entries  the viewer's devices, when the caller has them
     * @return array<string, mixed>
     */
    public function present(User $viewer, ?int $siteId, mixed $monthKey, ?Collection $entries = null, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $month = $this->month($monthKey, $now);
        $figures = $this->figures($viewer, $siteId, $month, $now, $entries);
        $views = $this->cameraViews($viewer, $siteId, $month);
        $query = array_filter(['month' => $month['key'], 'site' => $siteId !== null ? (string) $siteId : null]);
        $qs = '?'.http_build_query($query);

        return [
            'month' => [
                'key' => $month['key'],
                'label' => $month['label'],
                'short' => $month['short'],
                'range' => $month['range'],
                'current' => $month['current'],
                // The full month is ready on the 1st at 7:00 am, when the email goes.
                'ready_at' => $month['current'] ? MonthlyReportEmail::sendTime($month['end'])->toIso8601String() : null,
            ],
            'months' => $this->months($now),
            ...$figures,
            'camera_views' => $views === null ? null : [
                'rows' => array_map(fn (array $row): array => [
                    'key' => $row['key'],
                    'at' => $row['at']->toIso8601String(),
                    'who' => $row['who'],
                    'camera' => $row['camera'],
                    'camera_id' => $row['camera_id'],
                    'kind' => $row['kind'],
                    'from' => $row['from']?->toIso8601String(),
                    'to' => $row['to']?->toIso8601String(),
                    'reason' => $row['reason'],
                ], array_slice($views['rows'], 0, self::LOG_ROWS)),
                'total' => count($views['rows']),
                'live' => $views['live'],
                'clips' => $views['clips'],
                'export' => '/security-devices/monthly-report/camera-views.csv'.$qs,
            ],
            'kept' => [
                'door_names_days' => \App\Domain\SecurityDevices\Screens\Access\AccessNames::KEEP_DAYS,
                'audit_years' => max(1, (int) config('retention.audit_log_years', 2)),
                'months' => self::MONTHS,
            ],
            'links' => [
                'pdf' => '/security-devices/monthly-report.pdf'.$qs,
                'csv' => '/security-devices/monthly-report.csv'.$qs,
                'devices' => '/security-devices/exports/devices.csv',
                'events' => '/security-devices/exports/events.csv',
                'upkeep' => '/security-devices/exports/upkeep.csv',
            ],
        ];
    }

    /**
     * The month's figures for this viewer and (optionally) one of their Sites.
     *
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $month
     * @param  Collection<int, DeviceEntry>|null  $entries
     * @return array{site_label: string, device_count: int, offline: array<string, mixed>|null, alerts: array<string, mixed>, safety: array<string, mixed>|null, upkeep: array<string, int>|null, cameras: array<string, mixed>|null}
     */
    public function figures(User $viewer, ?int $siteId, array $month, CarbonImmutable $now, ?Collection $entries = null): array
    {
        if ($siteId !== null) {
            $this->access->assertCanViewSite($viewer, $siteId);
        }
        $entries ??= $this->directory->entries($viewer, $siteId);
        $start = $month['start'];
        $until = $month['end']->min($now);

        return [
            'site_label' => $siteId === null ? 'All your Sites' : (string) (Site::query()->whereKey($siteId)->value('name') ?? 'One Site'),
            'device_count' => $entries->count(),
            'offline' => $this->offlineBySite($viewer, $siteId, $entries, $start, $until, $now),
            'alerts' => $this->alertsByKind($viewer, $siteId, $start, $until),
            'safety' => $this->safety($viewer, $siteId, $until),
            'upkeep' => $entries->isEmpty() ? null : $this->upkeep($viewer, $siteId, $entries, $start, $until),
            'cameras' => $this->cameraTime($entries, $start, $until, $now),
        ];
    }

    /**
     * Minutes each Site's routers and gateways had an offline alert open in the month. Overlapping
     * outages at one Site count once. Null when the viewer has no router or gateway that's watched.
     *
     * @param  Collection<int, DeviceEntry>  $entries
     * @return array{sites: list<array{name: string, minutes: int, still_offline: bool}>}|null
     */
    private function offlineBySite(User $viewer, ?int $siteId, Collection $entries, CarbonImmutable $start, CarbonImmutable $until, CarbonImmutable $now): ?array
    {
        $network = $entries->filter(fn (DeviceEntry $entry): bool => in_array($entry->kind, self::NETWORK_KINDS, true)
            && $entry->siteId !== null && $entry->state !== DeviceState::NotWatched && $entry->state !== DeviceState::Retired)->values();
        if ($until->lte($start)) {
            return null;
        }
        // Each outage counts at the Site where it happened (the alert's own Site), not where the device is now.
        $allowed = $siteId !== null ? [$siteId] : $this->access->accessibleSiteIds($viewer);
        $alerts = DeviceAlert::query()
            ->with('device:id,domain,category,subcategory')
            ->whereIn('site_id', $allowed === [] ? [0] : $allowed)
            ->where('kind', 'offline')
            ->where('opened_at', '<', $until)
            ->where(fn (Builder $query) => $query->whereNull('closed_at')->orWhere('closed_at', '>', $start))
            ->get(['id', 'device_id', 'site_id', 'opened_at', 'closed_at'])
            ->filter(fn (DeviceAlert $alert): bool => $alert->device !== null && in_array(DeviceKind::of($alert->device), self::NETWORK_KINDS, true))
            ->values();
        if ($network->isEmpty() && $alerts->isEmpty()) {
            return null;
        }
        $siteIds = $network->map(fn (DeviceEntry $entry): int => (int) $entry->siteId)->merge($alerts->map(fn (DeviceAlert $alert): int => (int) $alert->site_id))->unique()->values()->all();
        $names = Site::query()->whereKey($siteIds)->pluck('name', 'id')->map(fn (mixed $name): string => (string) $name);
        $spans = [];
        $open = [];
        $alerts
            ->each(function (DeviceAlert $alert) use (&$spans, &$open, $now): void {
                $site = (int) $alert->site_id;
                $spans[$site][] = [$alert->opened_at->getTimestamp(), ($alert->closed_at ?? $now)->getTimestamp()];
                if ($alert->closed_at === null) {
                    $open[$site] = true;
                }
            });

        $sites = $names->map(fn (string $name, int $site): array => [
            'name' => $name,
            'minutes' => intdiv(Intervals::length(Intervals::clip(Intervals::union($spans[$site] ?? []), $start->getTimestamp(), $until->getTimestamp())), 60),
            'still_offline' => isset($open[$site]) && $until->equalTo($now),
        ])->sortBy(fn (array $row): string => str_pad((string) $row['minutes'], 10, '0', STR_PAD_LEFT).mb_strtolower($row['name']))->values()->all();

        return ['sites' => $sites];
    }

    /**
     * Device alerts opened in the month, by kind in plain words, most first.
     *
     * @return array{total: int, kinds: list<array{label: string, count: int}>}
     */
    private function alertsByKind(User $viewer, ?int $siteId, CarbonImmutable $start, CarbonImmutable $until): array
    {
        $counts = [];
        $this->scopedAlerts($viewer, $siteId)
            ->where('opened_at', '>=', $start)
            ->where('opened_at', '<', $until)
            ->selectRaw('kind, COUNT(*) AS total')
            ->groupBy('kind')
            ->toBase()
            ->get()
            ->each(function (object $row) use (&$counts): void {
                $label = self::kindLabel((string) $row->kind);
                $counts[$label] = ($counts[$label] ?? 0) + (int) $row->total;
            });
        arsort($counts);

        return [
            'total' => array_sum($counts),
            'kinds' => array_map(fn (string $label, int $count): array => ['label' => $label, 'count' => $count], array_keys($counts), array_values($counts)),
        ];
    }

    /**
     * How long safety alerts waited for someone (acknowledged, assigned or marked done, whichever
     * came first), averaged per NZ week for the 13 weeks to the end of the month, and the month's
     * own totals. Null when there was no safety alert in those weeks.
     *
     * @return array<string, mixed>|null
     */
    private function safety(User $viewer, ?int $siteId, CarbonImmutable $until): ?array
    {
        $lastWeek = $until->subSecond()->setTimezone(self::NZ)->startOfWeek();
        $firstWeek = $lastWeek->subWeeks(self::SAFETY_WEEKS - 1);
        $alerts = $this->scopedAlerts($viewer, $siteId)
            ->whereIn('kind', self::SAFETY_KINDS)
            ->where('opened_at', '>=', $firstWeek->utc())
            ->where('opened_at', '<', $until)
            ->get(['id', 'opened_at', 'acknowledged_at', 'assigned_at', 'closed_at', 'close_reason']);
        if ($alerts->isEmpty()) {
            return null;
        }
        $monthStart = $until->subSecond()->setTimezone(self::NZ)->startOfMonth()->utc();
        $waits = array_fill(0, self::SAFETY_WEEKS, []);
        $month = ['count' => 0, 'picked' => 0, 'waits' => []];
        foreach ($alerts as $alert) {
            $picked = collect([$alert->acknowledged_at, $alert->assigned_at, $alert->close_reason === 'done' ? $alert->closed_at : null])
                ->filter()
                ->min(fn (CarbonImmutable $at): int => $at->getTimestamp());
            $wait = $picked === null ? null : max(0, $picked - $alert->opened_at->getTimestamp()) / 60;
            $week = (int) round(($alert->opened_at->setTimezone(self::NZ)->startOfWeek()->getTimestamp() - $firstWeek->getTimestamp()) / 604_800);
            if ($wait !== null && $week >= 0 && $week < self::SAFETY_WEEKS) {
                $waits[$week][] = $wait;
            }
            if ($alert->opened_at->gte($monthStart)) {
                $month['count']++;
                if ($wait !== null) {
                    $month['picked']++;
                    $month['waits'][] = $wait;
                }
            }
        }
        $average = fn (array $values): ?float => $values === [] ? null : round(array_sum($values) / count($values), 1);

        return [
            'weeks' => array_map(fn (int $week): array => [
                'label' => $firstWeek->addWeeks($week)->format('j M'),
                'minutes' => $average($waits[$week]),
            ], range(0, self::SAFETY_WEEKS - 1)),
            'month' => [
                'count' => $month['count'],
                'picked' => $month['picked'],
                'not_picked' => $month['count'] - $month['picked'],
                'average_minutes' => $average($month['waits']),
            ],
        ];
    }

    /**
     * Upkeep in the month: batteries changed (a low-battery alert closed because a new battery
     * reported), software updates and settings changes Oblivion saw on the devices.
     *
     * @param  Collection<int, DeviceEntry>  $entries
     * @return array{batteries: int, software: int, settings: int}
     */
    private function upkeep(User $viewer, ?int $siteId, Collection $entries, CarbonImmutable $start, CarbonImmutable $until): array
    {
        $ids = $entries->map(fn (DeviceEntry $entry): int => $entry->id())->all();
        $events = fn (string $type): int => $this->access->applyTemporalEventCustodyScope(
            DeviceEvent::query()
                ->whereIn('device_events.device_id', $ids)
                ->where('device_events.event_type', $type)
                ->where('device_events.occurred_at', '>=', $start)
                ->where('device_events.occurred_at', '<', $until),
            $viewer,
        )->count();

        return [
            'batteries' => $this->scopedAlerts($viewer, $siteId)
                ->where('kind', 'battery')
                ->where('close_reason', 'recovered')
                ->where('closed_at', '>=', $start)
                ->where('closed_at', '<', $until)
                ->count(),
            'software' => $events('firmware_updated'),
            'settings' => $events('config_changed'),
        ];
    }

    /**
     * Hours the watched cameras had a picture and hours they didn't (an offline alert was open),
     * leaving out each camera's privacy hours (its current schedule) and time before it was added.
     * Null when no camera is watched.
     *
     * @param  Collection<int, DeviceEntry>  $entries
     * @return array{cameras: int, with_hours: float, without_hours: float, percent: float|null, worst: list<array{name: string, hours: float}>}|null
     */
    private function cameraTime(Collection $entries, CarbonImmutable $start, CarbonImmutable $until, CarbonImmutable $now): ?array
    {
        $cameras = $entries->filter(fn (DeviceEntry $entry): bool => $entry->kind === DeviceKind::Camera && $entry->monitorIds !== []
            && $entry->state !== DeviceState::NotWatched && $entry->state !== DeviceState::Retired)->keyBy(fn (DeviceEntry $entry): int => $entry->id());
        if ($cameras->isEmpty() || $until->lte($start)) {
            return null;
        }
        $ids = $cameras->keys()->all();
        $added = Device::query()->whereKey($ids)->pluck('created_at', 'id');
        // Time before a camera had a check isn't good time: it starts when its first check was added.
        $watchedFrom = Monitor::query()->whereIn('id', $cameras->flatMap(fn (DeviceEntry $entry): array => $entry->monitorIds)->unique()->values()->all())
            ->get(['id', 'created_at'])->mapWithKeys(fn (Monitor $monitor): array => [(int) $monitor->id => $monitor->created_at]);
        $privacy = CameraPrivacyHours::query()->whereIn('device_id', $ids)->where('enabled', true)->get(['device_id', 'ranges'])->keyBy('device_id');
        $offline = [];
        DeviceAlert::query()
            ->whereIn('device_id', $ids)
            ->where('kind', 'offline')
            ->where('opened_at', '<', $until)
            ->where(fn (Builder $query) => $query->whereNull('closed_at')->orWhere('closed_at', '>', $start))
            ->get(['device_id', 'opened_at', 'closed_at'])
            ->each(function (DeviceAlert $alert) use (&$offline, $now): void {
                $offline[(int) $alert->device_id][] = [$alert->opened_at->getTimestamp(), ($alert->closed_at ?? $now)->getTimestamp()];
            });

        $with = 0;
        $without = 0;
        $worst = [];
        foreach ($cameras as $id => $entry) {
            $addedAt = $added->get($id);
            $firstCheck = collect($entry->monitorIds)->map(fn (mixed $monitorId) => $watchedFrom->get((int) $monitorId))->filter()->min();
            if ($firstCheck === null) {
                continue; // no check history to stand behind
            }
            $from = max($start->getTimestamp(), $addedAt !== null ? CarbonImmutable::parse($addedAt)->getTimestamp() : $start->getTimestamp(), CarbonImmutable::parse($firstCheck)->getTimestamp());
            $to = $until->getTimestamp();
            if ($to <= $from) {
                continue;
            }
            $ranges = $privacy->get($id)?->ranges;
            $private = is_array($ranges) ? Intervals::weekly($ranges, $from, $to, self::NZ) : [];
            $down = Intervals::clip(Intervals::union($offline[$id] ?? []), $from, $to);
            $noPicture = Intervals::length($down) - Intervals::overlap($down, $private);
            $span = $to - $from - Intervals::length($private);
            $with += max(0, $span - $noPicture);
            $without += max(0, $noPicture);
            if ($noPicture >= 1800) {
                $worst[] = ['name' => $entry->name(), 'hours' => round($noPicture / 3600, 1)];
            }
        }
        usort($worst, fn (array $a, array $b): int => $b['hours'] <=> $a['hours']);

        return [
            'cameras' => $cameras->count(),
            'with_hours' => round($with / 3600, 1),
            'without_hours' => round($without / 3600, 1),
            'percent' => $with + $without > 0 ? floor($with / ($with + $without) * 1000) / 10 : null,
            'worst' => array_slice($worst, 0, 3),
        ];
    }

    /**
     * The camera viewing log: live views (with the reason given first) for people who may see
     * camera pictures, and clips saved from recordings for people who may see recordings. Only
     * cameras the viewer may see now; newest first. Null when the viewer holds neither key.
     *
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $month
     * @return array{rows: list<array<string, mixed>>, live: bool, clips: bool}|null
     */
    public function cameraViews(User $viewer, ?int $siteId, array $month): ?array
    {
        $live = $viewer->canDo('securityDevices.cctv.media.view');
        $clips = $viewer->canDo(CctvRecordingReferences::VIEW);
        if (! $live && ! $clips) {
            return null;
        }
        $visible = ($siteId === null ? $this->access->visibleDevices($viewer) : $this->access->visibleDevicesForSite($viewer, $siteId))
            ->where('devices.category', 'cctv');
        $cameras = $visible->get(['devices.id', 'devices.name'])->keyBy('id');
        if ($cameras->isEmpty()) {
            return ['rows' => [], 'live' => $live, 'clips' => $clips];
        }
        $siteNames = $this->directory->entries($viewer, $siteId, $cameras->keys()->all())
            ->mapWithKeys(fn (DeviceEntry $entry): array => [$entry->id() => $entry->siteName]);
        $place = fn (int $id): string => trim((string) ($cameras->get($id)?->name ?? 'A camera').($siteNames->get($id) ? ' · '.$siteNames->get($id) : ''));

        $rows = [];
        if ($live) {
            $liveSites = $siteId !== null ? [$siteId] : $this->access->accessibleSiteIds($viewer);
            CctvLiveSession::query()
                ->whereIn('camera_id', $cameras->keys()->all())
                ->whereIn('source_id', CctvSource::query()->whereIn('site_id', $liveSites === [] ? [0] : $liveSites)->select('id'))
                ->where('created_at', '>=', $month['start'])
                ->where('created_at', '<', $month['end'])
                ->orderByDesc('id')
                ->get(['id', 'camera_id', 'actor_id', 'purpose', 'created_at'])
                ->each(function (CctvLiveSession $session) use (&$rows, $place): void {
                    $rows[] = [
                        'key' => 'live-'.$session->id,
                        'at' => CarbonImmutable::parse($session->created_at),
                        'actor_id' => (int) $session->actor_id,
                        'camera' => $place((int) $session->camera_id),
                        'camera_id' => (int) $session->camera_id,
                        'kind' => 'live',
                        'from' => null,
                        'to' => null,
                        'reason' => trim((string) $session->purpose),
                    ];
                });
        }
        if ($clips) {
            $siteIds = $this->access->accessibleSiteIds($viewer);
            CctvRecordingReference::query()
                ->whereIn('camera_id', $cameras->keys()->all())
                ->whereIn('site_id', $siteIds === [] ? [0] : $siteIds)
                ->where('created_at', '>=', $month['start'])
                ->where('created_at', '<', $month['end'])
                ->orderByDesc('id')
                ->get()
                ->each(function (CctvRecordingReference $reference) use (&$rows, $place): void {
                    try {
                        $meta = $reference->metadata;
                    } catch (Throwable) {
                        return;
                    }
                    // Only a reference whose sealed record still matches (as the camera page reads them).
                    if (! is_array($meta) || ! hash_equals((string) $reference->metadata_hash, CctvContract::hash($meta)) || ($meta['reference_uuid'] ?? null) !== $reference->uuid) {
                        return;
                    }
                    $rows[] = [
                        'key' => 'clip-'.$reference->uuid,
                        'at' => CarbonImmutable::parse($reference->created_at),
                        'actor_id' => (int) $reference->actor_id,
                        'camera' => $place((int) $reference->camera_id),
                        'camera_id' => (int) $reference->camera_id,
                        'kind' => 'clip',
                        'from' => is_string($meta['requested_start_at'] ?? null) ? CarbonImmutable::parse($meta['requested_start_at']) : null,
                        'to' => is_string($meta['requested_end_at'] ?? null) ? CarbonImmutable::parse($meta['requested_end_at']) : null,
                        'reason' => trim((string) ($meta['purpose'] ?? '')),
                    ];
                });
        }
        $names = User::query()->whereKey(array_values(array_unique(array_column($rows, 'actor_id'))))->pluck('name', 'id');
        foreach ($rows as &$row) {
            $row['who'] = (string) ($names[$row['actor_id']] ?? 'A former user');
            unset($row['actor_id']);
        }
        unset($row);
        usort($rows, fn (array $a, array $b): int => $b['at']->getTimestamp() <=> $a['at']->getTimestamp());

        return ['rows' => $rows, 'live' => $live, 'clips' => $clips];
    }

    /**
     * Device alerts on devices this person may see, raised at the viewer's Sites: history stays with
     * the Site where it happened, not where a device has moved to since. Alerts with no Site only for
     * people who may see devices with no Site.
     *
     * @return Builder<DeviceAlert>
     */
    private function scopedAlerts(User $viewer, ?int $siteId): Builder
    {
        $visible = ($siteId === null ? $this->access->visibleDevices($viewer) : $this->access->visibleDevicesForSite($viewer, $siteId))->select('devices.id');
        $sites = $siteId !== null ? [$siteId] : $this->access->accessibleSiteIds($viewer);
        $unassigned = $siteId === null && $this->access->canViewUnassigned($viewer);

        return DeviceAlert::query()->whereIn('device_id', $visible)
            ->where(fn (Builder $query) => $query->whereIn('site_id', $sites === [] ? [0] : $sites)->when($unassigned, fn (Builder $none) => $none->orWhereNull('site_id')));
    }

    /** A device alert's kind in plain words. */
    public static function kindLabel(string $kind): string
    {
        return match (true) {
            $kind === 'offline' => 'Device offline',
            $kind === 'failing' => 'A check failing',
            $kind === 'hub_offline' => 'Hub not answering',
            $kind === 'battery' => 'Battery low',
            str_starts_with($kind, 'reading_') => 'Reading too high or low',
            in_array($kind, ['care_out_of_bed', 'care_door_night'], true) => 'Care plan sensor',
            $kind === 'scald_risk' => 'Hot water too hot',
            $kind === 'panic' => 'Panic button',
            $kind === 'duress' => 'Duress code',
            $kind === 'fall' => 'Possible fall',
            $kind === 'alarm' => 'Alarm going off',
            $kind === 'leak' => 'Water leak',
            $kind === 'forced' => 'Door forced open',
            $kind === 'tamper' => 'Tampered with',
            in_array($kind, ['mode_door_check', 'mode_alarm_check'], true) => 'House mode check',
            $kind === 'automation' => 'Automation',
            in_array($kind, ['access_lockdown', 'access_evacuation'], true) => 'Lockdown or evacuation',
            in_array($kind, ['mqtt_logins', 'mqtt_exposed'], true) => 'Sensor broker',
            default => Str::ucfirst(Str::of($kind)->replace(['_', '.', '-'], ' ')->squish()->lower()->toString()) ?: 'Other',
        };
    }
}
