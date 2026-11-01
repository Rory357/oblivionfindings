<?php

namespace App\Domain\SecurityDevices\Screens\Devices\Pages;

use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Cctv\Models\CctvSource;
use App\Domain\SecurityDevices\Endpoints\Presenters\EndpointDevicePosturePresenter;
use App\Domain\SecurityDevices\Hubs\Services\CoveringHubResolver;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Models\DeviceRelationship;
use App\Domain\SecurityDevices\Screens\DeviceKind;
use App\Domain\SecurityDevices\Screens\DeviceState;
use App\Domain\SecurityDevices\Screens\Devices\DeviceDirectory;
use App\Domain\SecurityDevices\Screens\Devices\DeviceEntry;
use App\Domain\SecurityDevices\Screens\Devices\DeviceLast24Hours;
use App\Domain\SecurityDevices\Screens\Devices\DevicesIndexPresenter;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * One device page per kind (S&D rebuild C2, drawings pg-sdr-3, 9, 10, 16, 17, 20, 21, 22 and the
 * shared tabs 3c and 5d) for network gear, computers, printers, UPSs, video recorders, sensor
 * gateways and other devices. The kind decides the tabs and the overview. Everything is scoped by
 * SecurityDevicesAccessService before it gets here; a number with no data behind it is null, which
 * the page shows as "Not available". Only the open tab's data is read.
 */
final class DevicePagePresenter
{
    public const array TAB_LABELS = [
        'overview' => 'Overview',
        'health' => 'Health',
        'remote' => 'Remote support',
        'checks' => 'Checks & alerts',
        'notes' => 'Notes & documents',
    ];

    /** The kinds this page is for; cameras and the safety kit keep today's page until Phase E. */
    public const array KINDS = [
        DeviceKind::Switch, DeviceKind::Router, DeviceKind::WifiPoint, DeviceKind::NetworkDevice,
        DeviceKind::Computer, DeviceKind::Printer, DeviceKind::Ups, DeviceKind::Recorder,
        DeviceKind::SensorGateway, DeviceKind::Other,
    ];

    private const array NETWORK = [DeviceKind::Switch, DeviceKind::Router, DeviceKind::WifiPoint, DeviceKind::NetworkDevice];

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly DeviceDirectory $directory,
        private readonly DeviceObservations $observations,
        private readonly DeviceSettingsBackups $backups,
        private readonly DeviceChecksTab $checks,
        private readonly DeviceNotesTab $notes,
        private readonly DeviceTroubleState $trouble,
        private readonly ComputerTabs $computer,
        private readonly DeviceLast24Hours $last24Hours,
        private readonly DevicesIndexPresenter $cards,
    ) {}

    public static function serves(DeviceKind $kind): bool
    {
        return in_array($kind, self::KINDS, true);
    }

    /** network, computer or other: which group of page parts it uses. */
    public static function group(DeviceKind $kind): string
    {
        return match (true) {
            in_array($kind, self::NETWORK, true) => 'network',
            $kind === DeviceKind::Computer => 'computer',
            default => 'other',
        };
    }

    /**
     * @param  array{tab?: mixed, range?: mixed}  $input
     * @return array<string, mixed>
     */
    public function present(User $viewer, Device $device, array $input): array
    {
        $now = CarbonImmutable::now();
        $kind = DeviceKind::of($device);
        $group = self::group($kind);
        $entry = $this->directory->entries($viewer, null, [(int) $device->id])->first();
        $state = $entry?->state ?? DeviceState::Retired;
        $siteId = $entry?->siteId;
        $siteName = $entry?->siteName;
        $custody = DeviceObservations::custodyStart($device);
        $canSeeChecks = $viewer->canDo('securityDevices.events.view');
        $range = is_string($input['range'] ?? null) && array_key_exists($input['range'], DeviceObservations::RANGES) ? $input['range'] : '24h';

        // Every enabled check, for the numbers; the Checks tab lists them only for staff who may.
        $monitors = $device->monitors()
            ->where('is_enabled', true)
            ->with([
                'profile:id,name,interval_seconds,stale_after_seconds,failure_confirmations,failure_duration_seconds',
                'collector:id,name,site_id,status',
            ])
            ->orderBy('name')
            ->get();
        $latest = $this->observations->latest($monitors, $custody);

        $posture = $group === 'computer' ? app(EndpointDevicePosturePresenter::class)->present($viewer, $device, $siteId) : null;
        $backup = $group === 'network' ? $this->backups->action($viewer, $device, $siteId) : null;
        $backupHistory = $group === 'network' ? $this->backups->history($viewer, $device, $siteId) : [];
        $remote = $posture !== null && data_get($posture, 'links.remoteSupportHref') !== null
            ? $this->computer->remoteSupport($device, $posture)
            : null;
        $inTrouble = $group === 'network' && $state === DeviceState::Offline && $entry !== null;

        $tabs = array_values(array_filter([
            'overview',
            $group === 'computer' && $posture !== null ? 'health' : null,
            $remote !== null ? 'remote' : null,
            $canSeeChecks ? 'checks' : null,
            'notes',
        ]));
        $tab = in_array($input['tab'] ?? null, $tabs, true) ? (string) $input['tab'] : 'overview';

        $can = [
            'edit' => $viewer->canDo('securityDevices.devices.update'),
            'watch' => $viewer->canDo('securityDevices.monitoring.manage'),
            'move' => $viewer->canDo('securityDevices.devices.assign'),
            'retire' => $viewer->canDo('securityDevices.devices.delete'),
            'assign' => $viewer->canDo('securityDevices.devices.assign'),
            'upload' => $viewer->canDo('securityDevices.devices.update') && $state !== DeviceState::Retired,
            'see_events' => $canSeeChecks,
        ];

        $trouble = $inTrouble ? $this->trouble->present($viewer, $device, $kind, $entry, $monitors, $custody, $canSeeChecks) : null;

        return [
            'device' => [
                'id' => (int) $device->id,
                'name' => (string) $device->name,
                'kind' => $kind->value,
                'kind_label' => $kind->label(),
                'group' => $group,
                'state' => $state->value,
                'badge' => ['label' => $state->label(), 'tone' => $state->tone()],
                'model' => $entry?->model() ?? trim(trim((string) $device->manufacturer).' '.trim((string) $device->model)),
                'site' => $siteName,
                'site_id' => $siteId,
                'address' => $device->ip_address ? (string) $device->ip_address : null,
                'watching' => $entry === null || $state === DeviceState::NotWatched ? 'Nothing' : Str::ucfirst(implode(', ', $entry->watching)),
                'subline' => $this->subline($viewer, $device, $kind, $entry, $monitors, $posture, $siteName),
                'back' => [
                    'href' => '/security-devices/devices'.($kind->tab() === 'all' ? '' : '?tab='.$kind->tab()),
                    'label' => 'Devices',
                ],
            ],
            'tab' => $tab,
            'tabs' => array_map(fn (string $key): array => ['key' => $key, 'label' => self::TAB_LABELS[$key]], $tabs),
            'range' => $range,
            'meters' => $inTrouble
                ? $this->troubleMeters($trouble)
                : $this->meters($kind, $group, $entry, $monitors, $latest, $posture, $backup, $backupHistory, $device, $viewer),
            'can' => $can,
            'actions' => [
                'ping' => $device->ip_address !== null && $device->ip_address !== '',
                'backup' => $backup,
                'remote_href' => $remote['start_href'] ?? null,
            ],
            'trouble' => $trouble,
            'overview' => $tab === 'overview'
                ? $this->overview($viewer, $device, $kind, $group, $entry, $monitors, $latest, $custody, $range, $now, $posture, $backupHistory, $canSeeChecks)
                : null,
            'health' => $tab === 'health' ? $this->health($device, $posture) : null,
            'remote' => $tab === 'remote' ? $remote : null,
            'checks' => $tab === 'checks'
                ? [
                    ...$this->checks->present($viewer, $device, $kind, $monitors, $siteId, $siteName, $custody, $now),
                    'backups' => $backupHistory,
                ]
                : null,
            'notes' => $tab === 'notes' ? $this->notes->present($viewer, $device, $now) : null,
        ];
    }

    /**
     * Model · Site · address · who checked it and when. Times are filled in on the page.
     *
     * @param  Collection<int, Monitor>  $monitors
     * @param  array<string, mixed>|null  $posture
     * @return list<array{text: string, at: ?string}>
     */
    private function subline(User $viewer, Device $device, DeviceKind $kind, ?DeviceEntry $entry, Collection $monitors, ?array $posture, ?string $siteName): array
    {
        $parts = [];
        $model = $entry?->model() ?? $kind->label();
        $parts[] = ['text' => $model, 'at' => null];
        if ($siteName !== null) {
            $room = trim((string) $device->location_description);
            $parts[] = ['text' => $room !== '' ? $siteName.' · '.$room : $siteName, 'at' => null];
        }
        if ($device->ip_address) {
            $parts[] = ['text' => (string) $device->ip_address, 'at' => null];
        }

        if ($kind === DeviceKind::Computer) {
            $seen = data_get($posture, 'agent.lastSeenAt');
            if (is_string($seen)) {
                $parts[] = ['text' => 'checked {ago} by Obli', 'at' => $seen];
            }

            return $parts;
        }

        $by = $this->checkedBy($viewer, $monitors);
        if ($entry?->state === DeviceState::Offline) {
            $since = $entry->offlineSince;
            if ($since !== null) {
                $parts[] = ['text' => 'offline since {time}', 'at' => $since->toIso8601String()];
            }
            if ($entry->lastCheckedAt !== null) {
                $parts[] = ['text' => $by.' tried again {ago}', 'at' => $entry->lastCheckedAt->toIso8601String()];
            }
        } elseif ($entry?->lastCheckedAt !== null) {
            $parts[] = ['text' => 'checked {ago} by '.$by, 'at' => $entry->lastCheckedAt->toIso8601String()];
        }

        return $parts;
    }

    /** @param Collection<int, Monitor> $monitors */
    private function checkedBy(User $viewer, Collection $monitors): string
    {
        $monitor = DeviceObservations::availabilityMonitors($monitors)->first() ?? $monitors->first();
        if (! $monitor?->collector instanceof MonitoringCollector) {
            return 'the Oblivion server';
        }

        return in_array((int) $monitor->collector->site_id, $this->access->accessibleSiteIds($viewer), true)
            ? CoveringHubResolver::label($monitor->collector)
            : 'its hub';
    }

    /**
     * The header numbers. Each says which tab it opens. value null = "Not available".
     *
     * @param  Collection<int, Monitor>  $monitors
     * @param  Collection<int, mixed>  $latest
     * @param  array<string, mixed>|null  $posture
     * @param  array<string, mixed>|null  $backup
     * @param  list<array<string, mixed>>  $backupHistory
     * @return list<array<string, mixed>>
     */
    private function meters(DeviceKind $kind, string $group, ?DeviceEntry $entry, Collection $monitors, Collection $latest, ?array $posture, ?array $backup, array $backupHistory, Device $device, User $viewer): array
    {
        $reply = function () use ($entry, $monitors, $latest): array {
            $observation = DeviceObservations::availabilityMonitors($monitors)
                ->map(fn (Monitor $monitor) => $latest->get($monitor->id))
                ->filter(fn ($row) => $row !== null && $row->latency_ms !== null && in_array($row->state?->value, ['healthy', 'degraded'], true))
                ->sortByDesc('observed_at')
                ->first();
            $online = $entry?->state === DeviceState::Online;

            return self::meter('reply', 'Reply time', $online && $observation ? self::ms((int) $observation->latency_ms) : null, $online && $observation
                ? ['text' => 'At the check {ago}', 'at' => $observation->observed_at?->toIso8601String()]
                : ['text' => $monitors->isEmpty() ? 'Not checked by Oblivion' : 'No reply to show', 'at' => null], $online && $observation ? 'success' : 'brand', 'checks');
        };
        $uptime = function () use ($latest): array {
            $up = $this->observations->uptime($latest);

            return self::meter('uptime', 'Up for', null, $up
                ? ['text' => 'Last restart {time}', 'at' => $up['since']]
                : ['text' => 'Not reported by this device', 'at' => null], 'brand', 'overview', from: $up['since'] ?? null);
        };
        $reading = function (string $key, string $label, string $tab = 'overview') use ($monitors, $latest): array {
            $value = $this->observations->reading($key, $monitors, $latest);

            return self::meter($key, $label, $value === null ? null : self::number($value['value']).self::unit($value['unit']), $value
                ? ['text' => 'Read {ago}', 'at' => $value['at']]
                : ['text' => 'Not read from this device', 'at' => null], 'brand', $tab);
        };

        if ($group === 'computer') {
            $inventory = is_array($posture['inventory'] ?? null) ? $posture['inventory'] : null;
            $disk = $this->computer->systemDisk($inventory);
            $restart = $this->computer->lastRestart($inventory, data_get($posture, 'agent.observedAt'));
            $agentState = (string) data_get($posture, 'agent.state', 'not_enrolled');
            $version = data_get($posture, 'agent.agentVersion');
            $seen = data_get($posture, 'agent.lastSeenAt');

            return [
                [
                    ...self::meter('disk', 'Disk used', $disk ? self::gb($disk['used']).' of '.self::gb($disk['total']) : null, $disk
                        ? ['text' => $disk['name'].' drive', 'at' => null]
                        : ['text' => $posture === null ? 'You can’t see this computer’s details' : 'Not sent by Obli yet', 'at' => null], ($disk['used_percent'] ?? 0) >= 90 ? 'warning' : 'brand', 'health'),
                    'bar' => $disk['used_percent'] ?? null,
                ],
                self::meter('restart', 'Last restart', null, $restart ? ['text' => '{time}', 'at' => $restart] : ['text' => 'Not sent by Obli yet', 'at' => null], 'brand', 'health', ago: $restart),
                self::meter('obli', 'Obli', is_string($version) ? $version : null, is_string($seen)
                    ? ['text' => 'Heard from {ago}', 'at' => $seen]
                    : ['text' => 'Obli isn’t installed on it', 'at' => null], $agentState === 'fresh' ? 'success' : ($agentState === 'stale' ? 'warning' : 'brand'), 'overview'),
            ];
        }

        return match ($kind) {
            DeviceKind::WifiPoint => [$reply(), $uptime(), $this->wiredTo($viewer, $device)],
            DeviceKind::Printer => [$reading('toner', 'Toner'), $reading('pages', 'Pages this month'), $reading('paper', 'Paper')],
            DeviceKind::Ups => [$reading('runtime', 'Runtime left'), $reading('load', 'Load'), $reading('battery', 'Battery'), $this->lastPowerCut($viewer, $device)],
            DeviceKind::Recorder => [$this->camerasOn($viewer, $device), $reply(), self::meter('disk', 'Disk space', null, ['text' => 'Not read from the recorder', 'at' => null], 'brand', 'overview')],
            DeviceKind::SensorGateway => [...$this->gatewayMeters($viewer, $device), $uptime()],
            DeviceKind::Other => [$reply(), $uptime()],
            default => array_values(array_filter([
                $reply(),
                $uptime(),
                $this->portsMeter($monitors, $latest),
                $this->settingsMeter($backup, $backupHistory),
            ])),
        };
    }

    /**
     * @param  array{text: string, at: ?string}  $caption
     * @return array<string, mixed>
     */
    private static function meter(string $key, string $label, ?string $value, array $caption, string $tone, string $tab, ?string $from = null, ?string $ago = null): array
    {
        return ['key' => $key, 'label' => $label, 'value' => $value, 'from' => $from, 'ago' => $ago, 'caption' => $caption, 'tone' => $tone, 'tab' => $tab, 'bar' => null];
    }

    /** @param array<string, mixed>|null $trouble */
    private function troubleMeters(?array $trouble): array
    {
        $lastReply = $trouble['last_reply'] ?? null;

        return [
            self::meter('offline', 'Offline for', null, ['text' => 'Since {time}', 'at' => $trouble['since'] ?? null], 'critical', 'overview', from: $trouble['since'] ?? null),
            self::meter('cant_check', 'Can’t check', (string) ($trouble['behind_count'] ?? 0), ['text' => 'Devices behind it', 'at' => null], 'brand', 'overview'),
            self::meter('last_reply', 'Last reply', null, $lastReply
                ? ['text' => '{time}'.($lastReply['latency_ms'] !== null ? ' · '.self::ms((int) $lastReply['latency_ms']) : ''), 'at' => $lastReply['at']]
                : ['text' => 'No reply recorded at this Site', 'at' => null], 'brand', 'checks', ago: $lastReply['at'] ?? null),
        ];
    }

    /** @param Collection<int, Monitor> $monitors */
    private function portsMeter(Collection $monitors, Collection $latest): ?array
    {
        $ports = $this->observations->ports($monitors, $latest);

        return self::meter('ports', 'Ports in use', $ports ? $ports['up'].' of '.$ports['total'] : null, $ports
            ? ['text' => 'Read {ago}', 'at' => $ports['read_at']]
            : ['text' => 'Ports aren’t read from it', 'at' => null], 'brand', 'checks');
    }

    /**
     * @param  array<string, mixed>|null  $backup
     * @param  list<array<string, mixed>>  $history
     */
    private function settingsMeter(?array $backup, array $history): ?array
    {
        $latest = DeviceSettingsBackups::latest($history);
        if ($latest === null && $backup === null) {
            return null;
        }
        if ($latest === null) {
            return self::meter('settings', 'Settings', null, ['text' => 'No copy kept yet', 'at' => null], 'brand', 'checks');
        }
        $changes = $latest['changes'];

        return self::meter('settings', 'Settings', match (true) {
            $changes === null => 'First copy',
            $changes === 0 => 'No changes',
            default => $changes.' '.($changes === 1 ? 'line' : 'lines').' changed',
        }, ['text' => 'Backed up {time}', 'at' => $latest['at']], $changes === 0 || $changes === null ? 'success' : 'warning', 'checks');
    }

    private function wiredTo(User $viewer, Device $device): array
    {
        $link = $this->upstream($viewer, $device)->first();

        return self::meter('wired', 'Wired to', $link ? $link['name'].($link['port'] ? ' · port '.$link['port'] : '') : null, $link
            ? ['text' => $link['type'], 'at' => null]
            : ['text' => 'Not recorded', 'at' => null], 'brand', 'overview');
    }

    private function lastPowerCut(User $viewer, Device $device): array
    {
        $event = $viewer->canDo('securityDevices.events.view')
            ? $this->access->applyTemporalEventCustodyScope(DeviceEvent::query()->where('device_id', $device->id), $viewer)
                ->where(fn ($query) => $query->where('event_type', 'like', '%power%')->orWhere('event_type', 'like', '%on_battery%'))
                ->latest('occurred_at')
                ->first(['occurred_at'])
            : null;

        return self::meter('power_cut', 'Last power cut', null, $event
            ? ['text' => '{time}', 'at' => $event->occurred_at?->toIso8601String()]
            : ['text' => 'None recorded', 'at' => null], 'brand', 'overview', ago: $event?->occurred_at?->toIso8601String());
    }

    private function camerasOn(User $viewer, Device $device): array
    {
        $count = count($this->recorderCameraIds($viewer, $device));

        return self::meter('cameras', 'Cameras on it', (string) $count, ['text' => $count === 0 ? 'None linked to it yet' : 'Linked to this recorder', 'at' => null], 'brand', 'overview');
    }

    /** @return list<array<string, mixed>> */
    private function gatewayMeters(User $viewer, Device $device): array
    {
        $sensors = $this->childEntries($viewer, $device);
        $reporting = $sensors->filter(fn (DeviceEntry $entry): bool => $entry->state === DeviceState::Online);
        $latestSeen = $sensors->map(fn (DeviceEntry $entry) => $entry->lastSeenAt())->filter()->max();
        $low = $sensors->filter(fn (DeviceEntry $entry): bool => $entry->batteryLow());

        return [
            self::meter('reporting', 'Sensors reporting', $sensors->isEmpty() ? null : $reporting->count().' of '.$sensors->count(), $latestSeen
                ? ['text' => 'Last one {ago}', 'at' => $latestSeen->toIso8601String()]
                : ['text' => $sensors->isEmpty() ? 'No sensors linked to it yet' : 'None heard from', 'at' => null], $sensors->isNotEmpty() && $reporting->count() === $sensors->count() ? 'success' : 'brand', 'overview'),
            self::meter('batteries', 'Batteries low', $sensors->isEmpty() ? null : (string) $low->count(), [
                'text' => $low->isEmpty() ? ($sensors->isEmpty() ? 'No sensors linked to it yet' : 'None under 20%') : $low->take(2)->map(fn (DeviceEntry $entry): string => $entry->name())->join(', '),
                'at' => null,
            ], $low->isNotEmpty() ? 'warning' : 'brand', 'overview'),
        ];
    }

    /**
     * @param  Collection<int, Monitor>  $monitors
     * @param  Collection<int, mixed>  $latest
     * @param  array<string, mixed>|null  $posture
     * @param  list<array<string, mixed>>  $backupHistory
     * @return array<string, mixed>
     */
    private function overview(User $viewer, Device $device, DeviceKind $kind, string $group, ?DeviceEntry $entry, Collection $monitors, Collection $latest, ?CarbonImmutable $custody, string $range, CarbonImmutable $now, ?array $posture, array $backupHistory, bool $canSeeEvents): array
    {
        $events = $canSeeEvents ? $this->recent($viewer, $device, $backupHistory) : [];
        $facts = $this->facts($viewer, $device, $kind, $posture);

        if ($group === 'computer') {
            $inventory = is_array($posture['inventory'] ?? null) ? $posture['inventory'] : null;
            $memoryTotal = $inventory['memoryTotalBytes'] ?? null;
            $memoryFree = $inventory['memoryAvailableBytes'] ?? null;
            $disk = $this->computer->systemDisk($inventory);

            return [
                'now' => $inventory === null ? null : [
                    'read_at' => data_get($posture, 'agent.observedAt'),
                    'cpu' => $inventory['cpuPercent'] ?? null,
                    'memory' => is_int($memoryTotal) && $memoryTotal > 0 && is_int($memoryFree) ? round(($memoryTotal - $memoryFree) / $memoryTotal * 100) : null,
                    'disk' => $disk['used_percent'] ?? null,
                ],
                'restricted' => $posture !== null && (bool) ($posture['diagnosticsRestricted'] ?? false),
                'safety' => $this->computer->safety($inventory),
                'events' => $events,
                'facts' => $facts,
            ];
        }

        $base = [
            'trend' => $this->observations->replyTimes($monitors, $custody, $range, $now),
            'availability' => $this->observations->availability($monitors, $custody, $now),
            'events' => $events,
            'facts' => $facts,
        ];

        return match ($kind) {
            DeviceKind::Printer => [...$base, 'reading_trend' => $this->readingTrend('toner', 'Toner left', $monitors, $latest, $custody, '30d', $now)],
            DeviceKind::Ups => [...$base, 'reading_trend' => $this->readingTrend('battery', 'Battery charge', $monitors, $latest, $custody, '7d', $now)],
            DeviceKind::Recorder => [...$base, 'cameras' => $this->recorderCameras($viewer, $device, $now)],
            DeviceKind::SensorGateway => [...$base, 'sensors' => $this->gatewaySensors($viewer, $device)],
            DeviceKind::Other => $base,
            default => [...$base, 'connected' => $this->connected($viewer, $device, $entry)],
        };
    }

    /**
     * Recent events and settings backups, newest first.
     *
     * @param  list<array<string, mixed>>  $backupHistory
     * @return list<array<string, mixed>>
     */
    private function recent(User $viewer, Device $device, array $backupHistory): array
    {
        $events = $this->access
            ->applyTemporalEventCustodyScope(DeviceEvent::query()->where('device_id', $device->id), $viewer)
            ->latest('occurred_at')
            ->limit(6)
            ->get(['id', 'event_type', 'severity', 'occurred_at', 'source'])
            ->map(fn (DeviceEvent $event): array => DevicePageWords::event($event));

        $backups = collect($backupHistory)->take(4)->map(fn (array $copy): array => [
            'key' => 'backup-'.$copy['id'],
            'at' => $copy['at'],
            'tone' => ($copy['changes'] ?? 0) > 0 ? 'info' : 'success',
            'title' => ($copy['changes'] ?? 0) > 0 ? 'Settings changed' : 'Settings backed up',
            'sub' => match (true) {
                $copy['first'] => 'The first copy kept',
                ($copy['changes'] ?? 0) > 0 => $copy['changes'].' '.($copy['changes'] === 1 ? 'line' : 'lines').' changed since the copy before',
                default => 'No changes since the copy before',
            },
            'compare_id' => ($copy['changes'] ?? 0) > 0 && $copy['compare'] ? $copy['id'] : null,
        ]);

        return $events->concat($backups)
            ->sortByDesc(fn (array $item): string => (string) $item['at'])
            ->take(6)
            ->values()
            ->all();
    }

    /**
     * "About this …": the plain details, then the IT ones.
     *
     * @param  array<string, mixed>|null  $posture
     * @return list<array{0: string, 1: string}>
     */
    private function facts(User $viewer, Device $device, DeviceKind $kind, ?array $posture): array
    {
        $rows = [];
        $model = trim(trim((string) $device->manufacturer).' '.trim((string) $device->model));
        $rows[] = ['Model', $model !== '' ? $model : '—'];
        if ($kind === DeviceKind::Computer) {
            $inventory = is_array($posture['inventory'] ?? null) ? $posture['inventory'] : null;
            $rows[] = ['Windows', is_string($inventory['osVersion'] ?? null) ? $inventory['osVersion'] : 'Not available'];
            if (is_int($inventory['memoryTotalBytes'] ?? null)) {
                $rows[] = ['Memory', self::gb((int) $inventory['memoryTotalBytes'])];
            }
            $rows[] = ['Address', $device->ip_address ?: '—'];
            $rows[] = ['Intune', data_get($posture, 'intune.linked') ? 'Connected' : 'Not connected'];

            return $rows;
        }
        $address = trim(implode(' · ', array_filter([(string) $device->ip_address, (string) $device->mac_address])));
        $rows[] = ['Address', $address !== '' ? $address : '—'];
        $rows[] = ['Software', $device->firmware_version ? (string) $device->firmware_version : 'Not available'];
        foreach ($this->upstream($viewer, $device) as $link) {
            $rows[] = [$link['type'], $link['name'].($link['port'] ? ' · port '.$link['port'] : '')];
        }
        $provider = strtolower(trim((string) $device->provider));
        if (! in_array($provider, ['', 'manual', 'native', 'oblivion', 'oblivion_native'], true)) {
            $rows[] = ['Read from', match ($provider) {
                'unifi', 'ubiquiti' => 'UniFi',
                'milesight' => 'Milesight',
                'hikvision' => 'Hikvision',
                default => 'A connected service',
            }];
        }

        return $rows;
    }

    /**
     * What it's plugged into or powered by: the devices in front of it, visible ones only.
     *
     * @return Collection<int, array{id: int, name: string, port: ?string, type: string, kind: string}>
     */
    private function upstream(User $viewer, Device $device): Collection
    {
        $links = $device->parentRelationships()->with('parent:id,name,domain,category,subcategory')->get();
        $visible = $links->isEmpty() ? collect() : $this->access->visibleDevices($viewer)->whereKey($links->pluck('parent_device_id')->all())->pluck('id')->map(fn (mixed $id): int => (int) $id);

        return $links
            ->filter(fn (DeviceRelationship $link): bool => $link->parent !== null && $visible->contains((int) $link->parent_device_id))
            ->map(fn (DeviceRelationship $link): array => [
                'id' => (int) $link->parent_device_id,
                'name' => (string) $link->parent->name,
                'port' => $link->port ? (string) $link->port : null,
                'type' => $link->relationship_type?->label() ?? 'Connected to',
                'kind' => DeviceKind::of($link->parent)->value,
            ])
            ->values();
    }

    /** @return Collection<int, DeviceEntry> */
    private function childEntries(User $viewer, Device $device): Collection
    {
        $ids = $device->childRelationships()->pluck('child_device_id')->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();

        return $ids === [] ? collect() : $this->directory->entries($viewer, null, $ids)->sortBy(fn (DeviceEntry $entry): string => mb_strtolower($entry->name()))->values();
    }

    /**
     * What it's connected to: the devices in front of it, this device, and those behind it.
     *
     * @return array<string, mixed>|null
     */
    private function connected(User $viewer, Device $device, ?DeviceEntry $entry): ?array
    {
        $up = $this->upstream($viewer, $device);
        $ports = $device->childRelationships()->pluck('port', 'child_device_id');
        $down = $this->childEntries($viewer, $device);
        if ($up->isEmpty() && $down->isEmpty()) {
            return null;
        }
        $upStates = $up->isEmpty() ? collect() : $this->directory->entries($viewer, null, $up->pluck('id')->all())->keyBy(fn (DeviceEntry $row): int => $row->id());

        return [
            'up' => $up->map(fn (array $link): array => [
                'id' => $link['id'],
                'name' => $link['name'],
                'kind' => $link['kind'],
                'sub' => $link['port'] ? 'Port '.$link['port'] : $link['type'],
                'state' => $upStates->get($link['id'])?->state->value ?? 'not_watched',
                'href' => '/security-devices/devices/'.$link['id'],
            ])->values()->all(),
            'self' => ['id' => (int) $device->id, 'name' => (string) $device->name, 'kind' => DeviceKind::of($device)->value, 'sub' => 'This device', 'state' => $entry?->state->value ?? 'retired', 'href' => null],
            'down' => $down->take(10)->map(fn (DeviceEntry $row): array => [
                'id' => $row->id(),
                'name' => $row->name(),
                'kind' => $row->kind->value,
                'sub' => ($ports[$row->id()] ?? null) ? 'Port '.$ports[$row->id()] : $row->model(),
                'state' => $row->state->value,
                'href' => '/security-devices/devices/'.$row->id(),
            ])->values()->all(),
            'more' => max(0, $down->count() - 10),
        ];
    }

    /**
     * A named reading over time, from the check that reports it.
     *
     * @param  Collection<int, Monitor>  $monitors
     * @param  Collection<int, mixed>  $latest
     * @return array<string, mixed>|null
     */
    private function readingTrend(string $key, string $title, Collection $monitors, Collection $latest, ?CarbonImmutable $custody, string $range, CarbonImmutable $now): ?array
    {
        $reading = $this->observations->reading($key, $monitors, $latest);
        if ($reading === null) {
            return null;
        }
        $series = $this->observations->values($reading['monitor_id'], $reading['unit'], $custody, $range, $now);

        return $series === null ? null : ['title' => $title, ...$series];
    }

    /** @return list<int> */
    private function recorderCameraIds(User $viewer, Device $device): array
    {
        $fromSources = CctvSource::query()->where('recorder_device_id', $device->id)->whereNotNull('camera_id')->pluck('camera_id');
        $fromLinks = $device->childRelationships()->pluck('child_device_id');
        $ids = $fromSources->merge($fromLinks)->map(fn (mixed $id): int => (int) $id)->unique()->values();

        return $ids->isEmpty() ? [] : $this->access->visibleDevices($viewer)->whereKey($ids->all())->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
    }

    /**
     * The cameras that record to it, as the Devices menu draws them (their last 24 hours).
     *
     * @return list<array<string, mixed>>
     */
    private function recorderCameras(User $viewer, Device $device, CarbonImmutable $now): array
    {
        $ids = $this->recorderCameraIds($viewer, $device);
        if ($ids === []) {
            return [];
        }
        $entries = $this->directory->entries($viewer, null, $ids)->sortBy(fn (DeviceEntry $entry): string => $entry->sortKey())->values();
        $last24 = $this->last24Hours->forEntries($entries, $now);

        return $entries->map(fn (DeviceEntry $entry): array => $this->cards->card($entry, $last24[$entry->id()] ?? null, collect()))->values()->all();
    }

    /**
     * The sensors that report through it, with their latest reading and battery.
     *
     * @return list<array<string, mixed>>
     */
    private function gatewaySensors(User $viewer, Device $device): array
    {
        return $this->childEntries($viewer, $device)->map(function (DeviceEntry $entry): array {
            $readings = collect($entry->readings)
                ->filter(fn (mixed $reading): bool => is_array($reading) && is_numeric($reading['value'] ?? null))
                ->map(fn (array $reading, string $key): string => self::sensorWords($key, (float) $reading['value']))
                ->take(3)
                ->values()
                ->all();

            return [
                'id' => $entry->id(),
                'name' => $entry->name(),
                'kind' => $entry->kind->value,
                'model' => $entry->model(),
                'state' => $entry->state->value,
                'where' => trim((string) $entry->device->location_description) ?: null,
                'reading' => $readings === [] ? null : implode(' · ', $readings),
                'battery' => $entry->battery(),
                'battery_low' => $entry->batteryLow(),
                'seen_at' => $entry->lastSeenAt()?->toIso8601String(),
                'href' => '/security-devices/devices/'.$entry->id(),
                'address' => $entry->device->ip_address,
                'site' => $entry->siteName,
                'site_id' => $entry->siteId,
            ];
        })->values()->all();
    }

    /**
     * The Health tab (computers), read from Obli.
     *
     * @param  array<string, mixed>|null  $posture
     * @return array<string, mixed>
     */
    private function health(Device $device, ?array $posture): array
    {
        $inventory = is_array($posture['inventory'] ?? null) ? $posture['inventory'] : null;
        $memoryTotal = $inventory['memoryTotalBytes'] ?? null;
        $memoryFree = $inventory['memoryAvailableBytes'] ?? null;

        return [
            'read_at' => data_get($posture, 'agent.observedAt'),
            'restricted' => (bool) ($posture['diagnosticsRestricted'] ?? false),
            'available' => $inventory !== null,
            'safety' => $this->computer->safetyCards($inventory),
            'disks' => collect($inventory['disks'] ?? [])->map(fn (array $disk): array => [
                'name' => (string) $disk['name'],
                'used' => self::gb((int) $disk['usedBytes']),
                'total' => self::gb((int) $disk['totalBytes']),
                'percent' => $disk['usedPercent'],
            ])->values()->all(),
            'services' => $this->computer->services($inventory),
            'facts' => array_values(array_filter([
                ['Model', trim(trim((string) $device->manufacturer).' '.trim((string) $device->model)) ?: '—'],
                is_int($memoryTotal) ? ['Memory', self::gb($memoryTotal).(is_int($memoryFree) && $memoryTotal > 0 ? ' · '.round(($memoryTotal - $memoryFree) / $memoryTotal * 100).'% in use now' : '')] : null,
                is_string($inventory['osVersion'] ?? null) ? ['Windows', $inventory['osVersion']] : null,
                is_string($inventory['architecture'] ?? null) ? ['Type', $inventory['architecture']] : null,
            ])),
            'warranty_until' => $device->warranty_expires_at?->toDateString(),
            'programs' => $inventory === null ? null : (int) ($inventory['softwareTotal'] ?? 0),
        ];
    }

    private static function ms(int $value): string
    {
        return $value < 1 ? 'under 1 ms' : $value.' ms';
    }

    private static function gb(int $bytes): string
    {
        $gb = $bytes / 1_073_741_824;

        return ($gb >= 100 ? (string) round($gb) : rtrim(rtrim(number_format($gb, 1, '.', ''), '0'), '.')).' GB';
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }

    private static function unit(?string $unit): string
    {
        return match ($unit) {
            null, '' => '',
            '%', 'percent' => '%',
            'min', 'minutes' => ' min',
            default => ' '.$unit,
        };
    }

    private static function sensorWords(string $key, float $value): string
    {
        $number = self::number($value);

        return match ($key) {
            'temperature' => $number.' °C',
            'humidity' => $number.'% humidity',
            'co2' => 'CO₂ '.$number.' ppm',
            'tvoc' => 'TVOC '.$number.' ppb',
            'pm25' => 'PM2.5 '.$number,
            'pressure' => $number.' hPa',
            'illuminance' => $number.' lx',
            default => DevicePageWords::sentence($key).' '.$number,
        };
    }
}
