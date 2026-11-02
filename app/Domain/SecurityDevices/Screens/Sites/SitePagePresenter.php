<?php

namespace App\Domain\SecurityDevices\Screens\Sites;

use App\Domain\Monitoring\Discovery\Models\DiscoveryRun;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Alerts\Models\DeviceAlert;
use App\Domain\SecurityDevices\Hubs\Services\CoveringHubResolver;
use App\Domain\SecurityDevices\Hubs\Services\HubActionRules;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Models\DeviceRelationship;
use App\Domain\SecurityDevices\Screens\Alerts\AlertRulesPresenter;
use App\Domain\SecurityDevices\Screens\Alerts\AlertsPresenter;
use App\Domain\SecurityDevices\Screens\DeviceKind;
use App\Domain\SecurityDevices\Screens\DeviceState;
use App\Domain\SecurityDevices\Screens\Devices\BulkActionOptions;
use App\Domain\SecurityDevices\Screens\Devices\DeviceDirectory;
use App\Domain\SecurityDevices\Screens\Devices\DeviceEntry;
use App\Domain\SecurityDevices\Screens\Devices\DevicesIndexPresenter;
use App\Domain\SecurityDevices\Screens\Hubs\HubPanelPresenter;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Domain\SecurityDevices\Services\SiteTopologyMapService;
use App\Models\ControlRoomAlert;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Models\User;
use App\Services\ControlRoom\ControlRoomAlertAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * One Site (S&D rebuild drawing pg-sdr-12, with its Network map 12b and how each link was found 12j,
 * Internet 12e, Rooms 12d, Modes 12c, Devices 12g, Alerts 12h, Logbook 12i and "hub stopped
 * answering" 12f views). Only the open tab's data is worked out.
 *
 * The Alerts tab is for people who may see alerts; the Logbook for people who may see device events.
 * Anyone else asking for either gets the Overview.
 */
final class SitePagePresenter
{
    public const array TABS = ['overview', 'network', 'internet', 'rooms', 'modes', 'devices', 'alerts', 'logbook'];

    /** The Logbook's "What" choices (12i). */
    public const array LOGBOOK_WHAT = ['everything', 'alerts', 'alarm', 'modes', 'devices', 'people'];

    /** The Alerts tab keeps closed alerts this long; Alerts › Done has the same 30 days. */
    public const int ALERT_DAYS = 30;

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly DeviceDirectory $directory,
        private readonly CoveringHubResolver $hubs,
        private readonly ControlRoomAlertAccessService $alertAccess,
        private readonly SiteNetworkWeek $week,
        private readonly SiteTopologyMapService $topology,
        private readonly SiteRoomsPresenter $rooms,
        private readonly SiteModesPresenter $modes,
        private readonly DevicesIndexPresenter $cards,
        private readonly SiteStream $stream,
        private readonly SiteLinksFound $links,
        private readonly AlertRulesPresenter $rules,
    ) {}

    public function site(User $viewer, int $siteId): Site
    {
        $this->access->assertCanViewSite($viewer, $siteId);
        // type: Sites & locations' own policy reads it before the Rooms tab links there.
        $site = Site::query()->whereKey($siteId)->firstOrFail(['id', 'name', 'type', 'address_line_1', 'suburb', 'city', 'region', 'is_active', 'archived', 'archived_at']);
        abort_unless($site->is_active && ! $site->archived && $site->archived_at === null, 404);

        return $site;
    }

    /**
     * @param  array{tab?: mixed, what?: mixed, day?: mixed}  $input
     * @return array<string, mixed>
     */
    public function present(User $viewer, Site $site, array $input): array
    {
        $canAlerts = AlertsPresenter::canOpen($viewer);
        $canLogbook = SiteStream::mayView($viewer);
        $tab = in_array($input['tab'] ?? null, self::TABS, true) ? $input['tab'] : 'overview';
        if (($tab === 'alerts' && ! $canAlerts) || ($tab === 'logbook' && ! $canLogbook)) {
            $tab = 'overview';
        }
        $entries = $this->directory->entries($viewer, (int) $site->id)
            ->sortBy(fn (DeviceEntry $entry): string => $entry->sortKey())
            ->values();
        $hub = $this->hubs->forSite((int) $site->id);
        $alerts = $this->alerts($viewer, $entries);
        $flag = SiteFacts::flag($entries, $hub, array_column($alerts, 'title'));
        $hubDown = $hub !== null && ! in_array((string) $hub->status, ['online', 'degraded'], true);

        return [
            'site' => [
                'id' => (int) $site->id,
                'name' => (string) $site->name,
                'address' => SiteFacts::address($site),
            ],
            'tab' => $tab,
            // The rail: the Devices count, and the tabs this viewer may open.
            'tabs' => ['devices' => $entries->count(), 'alerts' => $canAlerts, 'logbook' => $canLogbook],
            'flag' => $flag,
            'hub' => $hub === null ? null : [
                // The hub panel (sdr-13) opens only for a hub whose own Site the viewer sees.
                'id' => HubPanelPresenter::mayView($viewer) && in_array((int) $hub->site_id, $this->access->accessibleSiteIds($viewer), true)
                    ? (int) $hub->id
                    : null,
                'label' => CoveringHubResolver::label($hub),
                'own' => (int) $hub->site_id === (int) $site->id,
                'answering' => ! $hubDown,
                'version' => $hub->softwareVersion(),
                'seen_at' => $hub->last_seen_at?->toIso8601String(),
            ],
            'meters' => $this->meters($entries, $alerts, $site),
            // S&D Phase F: the header's House mode block and Change mode (pop-up 42), on every tab.
            'house' => $this->modes->house($site, $entries),
            'overview' => $tab === 'overview' ? $this->overview($entries, $hub, $hubDown) : null,
            'network' => $tab === 'network' ? $this->network($viewer, $site, $entries, $hub) : null,
            'internet' => $tab === 'internet' ? $this->internet($viewer, $entries) : null,
            'rooms' => $tab === 'rooms' ? $this->rooms->present($viewer, $site, $entries) : null,
            'modes' => $tab === 'modes' ? $this->modes->present($site, $entries, $viewer->canDo('securityDevices.automations.manage')) : null,
            'devices' => $tab === 'devices' ? $this->devices($viewer, $site, $entries, $hub) : null,
            'alerts' => $tab === 'alerts' ? $this->alertsTab($viewer, $site, $entries) : null,
            'logbook' => $tab === 'logbook' ? $this->logbook($viewer, $site, $entries, $input) : null,
            'can' => [
                'add_device' => $viewer->canDo('securityDevices.devices.create'),
                'assign' => $viewer->canDo('securityDevices.devices.assign'),
                'scan' => $viewer->canDo('securityDevices.integrations.manage'),
                'add_hub' => HubActionRules::allows($viewer, 'securityDevices.integrations.manage'),
                'approve_hub' => HubActionRules::allows($viewer, 'securityDevices.hubs.approve'),
                // S&D Phase F: switch a house mode by hand (pop-up 42), and add or edit one (pop-up 43).
                'switch_mode' => $viewer->canDo('securityDevices.modes.switch'),
                'edit_modes' => $viewer->canDo('securityDevices.automations.manage'),
                // S&D Phase I (pop-up 97): ask Obli to check the network through the hub (IT, as Scan now).
                'ask_obli' => \App\Domain\SecurityDevices\NetworkChecks\NetworkChecks::mayAsk($viewer),
            ],
        ];
    }

    /**
     * @param  Collection<int, DeviceEntry>  $entries
     * @param  list<array<string, mixed>>  $alerts
     * @return array<string, mixed>
     */
    private function meters(Collection $entries, array $alerts, Site $site): array
    {
        $watched = $entries->filter(fn (DeviceEntry $entry): bool => $entry->state !== DeviceState::NotWatched);
        $online = $watched->filter(fn (DeviceEntry $entry): bool => $entry->state === DeviceState::Online)->count();
        $checked = $watched->map(fn (DeviceEntry $entry) => $entry->lastCheckedAt)->filter()->max();
        $scan = DiscoveryRun::query()
            ->whereHas('scope', fn ($scope) => $scope->where('site_id', $site->id))
            ->whereNotNull('completed_at')
            ->orderByDesc('completed_at')
            ->first(['id', 'completed_at', 'found_count', 'proposed_count']);

        return [
            'online' => [
                'count' => $online,
                'of' => $watched->count(),
                'caption' => $watched->isEmpty()
                    ? ['text' => 'Nothing watched yet', 'at' => null]
                    : ['text' => $checked !== null ? 'Checked {ago}' : 'Not checked yet', 'at' => $checked?->toIso8601String()],
            ],
            'alerts' => [
                'count' => count($alerts),
                'caption' => $alerts === [] ? 'None open' : $alerts[0]['title'].(count($alerts) > 1 ? ' and '.(count($alerts) - 1).' more' : ''),
            ],
            'scan' => $scan === null ? null : [
                'at' => $scan->completed_at?->toIso8601String(),
                'caption' => (int) $scan->found_count.' found'.((int) $scan->proposed_count > 0 ? ' · '.(int) $scan->proposed_count.' new' : ''),
                'href' => '/security-devices/scans/'.$scan->id,
            ],
        ];
    }

    /**
     * @param  Collection<int, DeviceEntry>  $entries
     * @return array<string, mixed>
     */
    private function overview(Collection $entries, ?MonitoringCollector $hub, bool $hubDown): array
    {
        $cameras = $entries->filter(fn (DeviceEntry $entry): bool => $entry->kind === DeviceKind::Camera)->values();

        return [
            'states' => SiteFacts::stateCounts($entries),
            'cameras' => $cameras->take(8)->map(fn (DeviceEntry $entry): array => [
                'id' => $entry->id(),
                'name' => $entry->name(),
                'state' => $entry->state->value,
                'href' => '/security-devices/devices/'.$entry->id(),
            ])->all(),
            'camera_count' => $cameras->count(),
            'week' => $this->week->forEntries($entries),
            'hub_down' => $hubDown && $hub !== null ? [
                'hub' => CoveringHubResolver::label($hub),
                'seen_at' => $hub->last_seen_at?->toIso8601String(),
                'cant_check' => $entries->filter(fn (DeviceEntry $entry): bool => $entry->state === DeviceState::CantCheck)->count(),
                // Devices a connected service still reports on, which don't need the hub.
                'still_seen' => $entries
                    ->filter(fn (DeviceEntry $entry): bool => $entry->state === DeviceState::Online && ! $entry->behindHub)
                    ->take(6)
                    ->map(fn (DeviceEntry $entry): array => ['name' => $entry->name(), 'href' => '/security-devices/devices/'.$entry->id()])
                    ->values()
                    ->all(),
            ] : null,
        ];
    }

    /**
     * The map shows only the links people added or confirmed (12j); what the hub and connected
     * services found waits in "Links found" below it until someone adds it.
     *
     * @param  Collection<int, DeviceEntry>  $entries
     * @return array<string, mixed>
     */
    private function network(User $viewer, Site $site, Collection $entries, ?MonitoringCollector $hub): array
    {
        $devices = $entries->map(fn (DeviceEntry $entry) => $entry->device)->values();
        $ids = $devices->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $relationships = $ids === [] ? collect() : DeviceRelationship::query()
            ->active()
            ->with(['parent:id,name', 'child:id,name'])
            ->whereIn('parent_device_id', $ids)
            ->whereIn('child_device_id', $ids)
            ->get();
        $rows = $entries->map(fn (DeviceEntry $entry): array => [
            'id' => $entry->id(),
            'status' => $entry->state->value,
            'health' => $entry->state->value,
        ])->values();

        return [
            'tree' => $this->topology->buildSiteTree($site, $devices, $rows, $relationships, confirmedOnly: true),
            'links' => $this->links->present($viewer, $site, $entries, $hub),
        ];
    }

    /**
     * What Oblivion knows about the Site's internet: its router or gateway and when it dropped out.
     *
     * @param  Collection<int, DeviceEntry>  $entries
     * @return array<string, mixed>
     */
    private function internet(User $viewer, Collection $entries): array
    {
        $gateways = $entries->filter(fn (DeviceEntry $entry): bool => $entry->kind === DeviceKind::Router
            || ($entry->kind === DeviceKind::NetworkDevice && preg_match('/router|gateway|firewall|wan/i', implode(' ', [
                (string) $entry->device->subcategory, (string) $entry->device->category, $entry->name(),
            ])) === 1))->values();
        // Drop-outs are device events: only for people who may see them, and only from while the
        // router was somewhere they can see (a router moved here doesn't bring another Site's history).
        $outages = $gateways->isEmpty() || ! $viewer->canDo('securityDevices.events.view') ? collect() : $this->access
            ->applyTemporalEventCustodyScope(DeviceEvent::query()
                ->whereIn('device_events.device_id', $gateways->map(fn (DeviceEntry $entry): int => $entry->id())->all()), $viewer)
            ->where(fn ($query) => $query->where('device_events.event_type', 'like', '%offline%')->orWhere('device_events.event_type', 'like', '%online%')
                ->orWhere('device_events.event_type', 'like', '%wan%')->orWhere('device_events.event_type', 'like', '%failover%'))
            ->orderByDesc('device_events.occurred_at')
            ->limit(12)
            ->get(['device_events.id', 'device_events.device_id', 'device_events.event_type', 'device_events.severity', 'device_events.occurred_at']);
        $names = $gateways->mapWithKeys(fn (DeviceEntry $entry): array => [$entry->id() => $entry->name()]);

        return [
            'gateways' => $gateways->map(fn (DeviceEntry $entry): array => $this->deviceRow($entry))->all(),
            'outages' => $outages->map(fn (DeviceEvent $event): array => [
                'id' => (int) $event->id,
                'at' => $event->occurred_at?->toIso8601String(),
                'tone' => str_contains((string) $event->event_type, 'offline') ? 'critical' : 'success',
                'title' => self::eventWords((string) $event->event_type),
                'device' => (string) ($names[(int) $event->device_id] ?? ''),
            ])->all(),
        ];
    }

    /**
     * The Site's own device list (12g): problems first, worst at the top, then by name; devices
     * that aren't watched come last. The room replaces the Site column.
     *
     * @param  Collection<int, DeviceEntry>  $entries  already in that order (sortKey)
     * @return array<string, mixed>
     */
    private function devices(User $viewer, Site $site, Collection $entries, ?MonitoringCollector $hub): array
    {
        $rooms = SiteRoom::query()->where('site_id', $site->id)->pluck('name', 'id');
        $behindCounts = $entries->pluck('behind')->filter()->countBy();
        $rows = $entries->map(function (DeviceEntry $entry) use ($rooms, $behindCounts): array {
            $card = $this->cards->card($entry, null, $behindCounts);
            $roomId = $entry->device->assignments
                ->first(fn (DeviceAssignment $assignment): bool => $assignment->assignable_type === DeviceAssignment::TARGET_ROOM)
                ?->assignable_id;
            $where = trim((string) $entry->device->location_description);

            return [
                ...array_intersect_key($card, array_flip(['id', 'name', 'sub', 'kind', 'kind_label', 'state', 'site', 'site_id', 'address', 'model', 'battery', 'watching', 'href', 'badge', 'note'])),
                'tab' => $entry->tab(),
                'room' => $roomId !== null && $rooms->has((int) $roomId) ? (string) $rooms[(int) $roomId] : ($where !== '' ? $where : null),
                'seen_at' => $entry->lastSeenAt()?->toIso8601String(),
            ];
        })->values();
        $notWatched = $entries->filter(fn (DeviceEntry $entry): bool => $entry->state === DeviceState::NotWatched)->values();
        // Remove soft-deletes a device: count it by its last place, as Devices › Upkeep › Retired does.
        $retired = app(\App\Domain\SecurityDevices\Screens\Devices\RetiredDevices::class)->atSite($viewer, (int) $site->id);
        $retiredCount = $retired['count'];

        return [
            'rows' => $rows->all(),
            'by_kind' => $entries->countBy(fn (DeviceEntry $entry): string => $entry->tab())->all(),
            'checked' => $this->howChecked($entries, $hub, (int) $site->id),
            'not_watched' => $notWatched->take(3)->map(fn (DeviceEntry $entry): string => $entry->name())->all(),
            'not_watched_count' => $notWatched->count(),
            'retired' => $retired['names'],
            'retired_count' => $retiredCount,
            // The same keys as the Devices list's row menu and bulk bar.
            'can' => [
                'edit' => $viewer->canDo('securityDevices.devices.update'),
                'watch' => $viewer->canDo('securityDevices.monitoring.manage'),
                'move' => $viewer->canDo('securityDevices.devices.assign'),
                'retire' => $viewer->canDo('securityDevices.devices.delete'),
                'export' => $viewer->canDo('securityDevices.reports.view'),
                'commands' => BulkActionOptions::allowed($viewer),
            ],
        ];
    }

    /**
     * How the Site's devices are checked, in words, from what actually watches them.
     *
     * @param  Collection<int, DeviceEntry>  $entries
     * @return list<array{tone: string, text: string}>
     */
    private function howChecked(Collection $entries, ?MonitoringCollector $hub, int $siteId): array
    {
        $lines = [];
        $checked = $entries->filter(fn (DeviceEntry $entry): bool => $entry->monitorIds !== [])->count();
        if ($checked > 0) {
            $who = $hub === null ? 'The Oblivion server' : '**'.CoveringHubResolver::label($hub).'**';
            $where = $hub === null ? 'from outside the Site' : ((int) $hub->site_id === $siteId ? 'from here' : 'over the link to its own Site');
            $lines[] = ['tone' => 'info', 'text' => $who.' checks **'.$checked.'** of them '.$where];
        }
        $reported = $entries
            ->filter(fn (DeviceEntry $entry): bool => $entry->monitorIds === [] && $entry->state !== DeviceState::NotWatched)
            ->countBy(fn (DeviceEntry $entry): string => (string) ($entry->watching[0] ?? 'reports from a connected service'));
        foreach ($reported as $how => $count) {
            // DeviceDirectory's words: "reports from UniFi", "reports from its sensor feed", …
            $lines[] = ['tone' => 'info', 'text' => '**'.$count.'** '.($count === 1 ? 'device reports' : 'devices report').' through '.Str::after((string) $how, 'reports from ')];
        }
        $lines[] = ['tone' => 'none', 'text' => 'A device behind one that’s offline shows **Can’t check** in grey, not red'];

        return $lines;
    }

    /**
     * The Site's alerts (12h): open and snoozed first, then the last 30 days, with who was told and
     * how each ended, from the alerts' own timelines. Only alerts opened while the device was here.
     *
     * @param  Collection<int, DeviceEntry>  $entries
     * @return array<string, mixed>
     */
    private function alertsTab(User $viewer, Site $site, Collection $entries): array
    {
        $now = CarbonImmutable::now('UTC');
        $byId = $entries->keyBy(fn (DeviceEntry $entry): int => $entry->id());
        $alerts = $byId->isEmpty() ? collect() : DeviceAlert::query()
            ->with(['events' => fn ($events) => $events->with('actor:id,name')])
            ->where('site_id', $site->id)
            ->whereIn('device_id', $byId->keys()->all())
            ->where(fn ($window) => $window->whereIn('state', DeviceAlert::ACTIVE_STATES)->orWhere('closed_at', '>=', $now->subDays(self::ALERT_DAYS)))
            ->orderByRaw("CASE WHEN state = 'open' THEN 0 WHEN state = 'snoozed' THEN 1 ELSE 2 END")
            ->orderByDesc('opened_at')
            ->limit(100)
            ->get();
        $people = SiteStream::carePeople($viewer, $alerts);
        // On now and still to come, with one-check and repeating quiet times, as Alerts › Quiet times shows them.
        $windows = collect(app(\App\Domain\SecurityDevices\Screens\Alerts\QuietTimesPresenter::class)->present($viewer, (int) $site->id, $now, false)['rows'])
            ->filter(fn (array $row): bool => $row['live'])
            ->sortBy(fn (array $row): string => $row['when']['starts_at'])
            ->take(10)
            ->values();

        return [
            'rows' => $alerts->map(function (DeviceAlert $alert) use ($byId, $people, $now): array {
                $words = new AlertWords($alert, $people[(int) $alert->id] ?? false);
                $entry = $byId->get((int) $alert->device_id);

                return [
                    'id' => (int) $alert->id,
                    'title' => $words->title(),
                    'sub' => self::alertKind($alert).' · '.$words->what((string) ($entry?->name() ?? '')),
                    'device_id' => (int) $alert->device_id,
                    'device_kind' => $entry?->kind->value,
                    'device' => $entry?->name(),
                    'state' => $alert->state,
                    'at' => $alert->opened_at->toIso8601String(),
                    'told' => $words->told(),
                    'ended' => $words->ended($now),
                    'href' => '/security-devices/alerts?alert='.$alert->id,
                ];
            })->values()->all(),
            'days' => self::ALERT_DAYS,
            // From the rules that cover this Site, and the safety rules that are always on.
            'told' => [
                ['when' => 'Safety alerts: panic button, alarm, water leak, door forced', 'then' => 'Staff on shift here, in the app · Control Room has them too'],
                ['when' => 'Care rules, hot water and sensor readings', 'then' => 'Staff on shift here, in the app'],
                ...collect($this->rules->present($viewer, (int) $site->id)['rows'])
                    ->filter(fn (array $rule): bool => $rule['on'])
                    ->map(fn (array $rule): array => [
                        'when' => $rule['when'].' ('.Str::after($rule['sub'], 'Rule · ').')',
                        'then' => $rule['then'],
                    ])
                    ->values()
                    ->all(),
            ],
            'quiet' => $windows->map(fn (array $row): array => [
                'id' => (int) $row['id'],
                'at' => $row['when']['starts_at'],
                'title' => ($row['scope']['device_id'] === null && $row['scope']['monitor_id'] === null ? 'The whole Site' : $row['paused']['name'])
                    .' · until '.SiteStream::clock(CarbonImmutable::parse($row['when']['ends_at']), $now, CarbonImmutable::parse($row['when']['starts_at']))
                    .($row['when']['repeats'] !== null ? ($row['when']['repeats'] === 'daily' ? ' · every day' : ' · every week') : ''),
                'sub' => implode(' · ', array_filter([filled($row['why']) ? Str::ucfirst(Str::limit((string) $row['why'], 80)) : null, $row['set_by']])),
            ])->all(),
        ];
    }

    /** The alert's kind in a word or two, for the line under its title. */
    private static function alertKind(DeviceAlert $alert): string
    {
        return match (true) {
            $alert->kind === 'offline' => 'Stopped answering',
            $alert->kind === 'hub_offline' => 'Hub',
            in_array($alert->kind, ['panic', 'alarm', 'leak', 'forced', 'tamper'], true) => 'Safety',
            $alert->kind === 'battery' => 'Battery',
            str_starts_with($alert->kind, 'reading_') => 'Reading',
            in_array($alert->kind, ['care_out_of_bed', 'care_door_night'], true) => 'Care rule',
            $alert->kind === 'scald_risk' => 'Scald risk',
            in_array($alert->kind, ['mode_door_check', 'mode_alarm_check'], true) => 'House mode check',
            $alert->kind === 'automation' => 'Automation',
            str_starts_with($alert->kind, 'access_') => 'Doors',
            in_array($alert->kind, ['mqtt_logins', 'mqtt_exposed'], true) => 'Sensor relay',
            default => 'Check',
        };
    }

    /**
     * The Site's logbook (12i), a day at a time: since the night ended, the night, and the rest of
     * the day before. One "What" choice narrows it.
     *
     * @param  Collection<int, DeviceEntry>  $entries
     * @param  array{what?: mixed, day?: mixed}  $input
     * @return array<string, mixed>
     */
    private function logbook(User $viewer, Site $site, Collection $entries, array $input): array
    {
        $zone = SiteStream::zone();
        $now = CarbonImmutable::now('UTC');
        $today = $now->setTimezone($zone)->startOfDay();
        $day = $today;
        if (is_string($input['day'] ?? null) && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $input['day']) === 1) {
            try {
                $picked = CarbonImmutable::createFromFormat('!Y-m-d', $input['day'], $zone);
                if ($picked !== false && $picked->lte($today) && $picked->gte($today->subYear())) {
                    $day = $picked;
                }
            } catch (\Throwable) {
                // Not a real date: today.
            }
        }
        $what = in_array($input['what'] ?? null, self::LOGBOOK_WHAT, true) ? (string) $input['what'] : 'everything';
        $isToday = $day->isSameDay($today);
        $night = $this->stream->night([(int) $site->id => (string) $site->name], $day);
        $end = $isToday ? $now : $day->addDay()->utc();
        $start = $day->subDay()->utc();
        $lines = collect($this->stream->between($viewer, $entries, [(int) $site->id => (string) $site->name], $start, $end, true, 600))
            ->filter(fn (array $line): bool => match ($what) {
                'alerts' => in_array($line['kind'], ['alert', 'hs'], true),
                'alarm' => $line['kind'] === 'alarm',
                'modes' => $line['kind'] === 'mode',
                'devices' => in_array($line['kind'], ['device', 'planned'], true),
                'people' => $line['by'] !== null,
                default => true,
            })
            ->values();
        $in = fn (CarbonImmutable $from, CarbonImmutable $to): array => $lines
            ->filter(fn (array $line): bool => $line['at'] !== null && CarbonImmutable::parse($line['at'])->gte($from) && CarbonImmutable::parse($line['at'])->lt($to))
            ->values()->all();
        $before = $day->subDay();

        return [
            'day' => $day->format('Y-m-d'),
            'today' => $isToday,
            'what' => $what,
            'sections' => [
                [
                    'key' => 'day',
                    'title' => $isToday ? ($now->setTimezone($zone)->hour < 12 ? 'This morning' : 'Today') : $day->format('D j M'),
                    'items' => $in($night['to'], $end),
                ],
                ['key' => 'night', 'title' => 'Overnight and early morning', 'items' => $in($night['from'], $night['to'])],
                [
                    'key' => 'before',
                    'title' => ($isToday ? 'Yesterday · ' : '').$before->format('D j M'),
                    'items' => $in($start, $night['from']),
                ],
            ],
        ];
    }

    /**
     * Open alerts about this Site's devices that the viewer may read. Snoozed alerts aren't open:
     * they wait in Alerts › Snoozed until their time is up.
     *
     * @param  Collection<int, DeviceEntry>  $entries
     * @return list<array{id: int, title: string, at: ?string, device: string, href: string}>
     */
    private function alerts(User $viewer, Collection $entries): array
    {
        if ($entries->isEmpty()) {
            return [];
        }
        $names = $entries->mapWithKeys(fn (DeviceEntry $entry): array => [$entry->id() => $entry->name()]);
        // S&D Phase D: the device alerts people work in Alerts come first.
        $open = ! AlertsPresenter::canOpen($viewer) ? collect() : DeviceAlert::query()
            ->where('state', DeviceAlert::STATE_OPEN)
            ->whereIn('device_id', $names->keys()->all())
            ->orderByRaw("CASE WHEN severity = 'critical' THEN 0 ELSE 1 END")->orderByDesc('opened_at')->limit(100)
            ->get(['id', 'device_id', 'kind', 'title', 'opened_at']);
        $people = SiteStream::carePeople($viewer, $open);
        $deviceAlerts = $open
            ->map(fn (DeviceAlert $alert): array => [
                'id' => (int) $alert->id,
                // A care rule's alert names its resident only to someone who may see them.
                'title' => (new AlertWords($alert, $people[(int) $alert->id] ?? false))->title(),
                'at' => $alert->opened_at->toIso8601String(),
                'device' => (string) ($names[(int) $alert->device_id] ?? ''),
                'href' => '/security-devices/alerts?alert='.$alert->id,
            ])->values()->all();
        if (! $viewer->canDo('controlRoom.viewAny') && ! $viewer->canDo('controlRoom.alerts.view')) {
            return $deviceAlerts;
        }
        $alerts = ControlRoomAlert::query()
            ->whereIn('status', ControlRoomAlert::ACTIVE_STATUSES)
            ->whereHas('device', fn ($device) => $device->whereIn('canonical_device_id', $names->keys()->all()))
            ->with('device:id,canonical_device_id')
            ->orderByDesc('id')
            ->limit(100)
            ->get(['id', 'alert_type', 'device_id', 'status', 'created_at']);
        $readable = $this->alertAccess->readableIds($alerts, $viewer)->flip();

        return [...$deviceAlerts, ...$alerts
            ->filter(fn (ControlRoomAlert $alert): bool => $readable->has((int) $alert->id))
            ->map(fn (ControlRoomAlert $alert): array => [
                'id' => (int) $alert->id,
                'title' => trim((string) $alert->alert_type) !== '' ? trim((string) $alert->alert_type) : 'An alert is open',
                'at' => $alert->created_at?->toIso8601String(),
                'device' => (string) ($names[(int) $alert->device?->canonical_device_id] ?? ''),
                'href' => '/control-room/alerts/'.$alert->id,
            ])
            ->values()
            ->all()];
    }

    /** @return array<string, mixed> */
    private function deviceRow(DeviceEntry $entry): array
    {
        return [
            'id' => $entry->id(),
            'name' => $entry->name(),
            'kind' => $entry->kind->label(),
            'kind_key' => $entry->kind->value,
            'state' => $entry->state->value,
            'subline' => $entry->subline(),
            'seen_at' => $entry->lastSeenAt()?->toIso8601String(),
            'behind' => $entry->behind,
            'href' => '/security-devices/devices/'.$entry->id(),
        ];
    }

    /** "device_offline" → "Device offline". */
    private static function eventWords(string $type): string
    {
        $words = Str::of($type)->replace(['.', '_', '-'], ' ')->squish()->lower()->toString();

        return $words === '' ? 'Something changed' : Str::ucfirst($words);
    }
}
