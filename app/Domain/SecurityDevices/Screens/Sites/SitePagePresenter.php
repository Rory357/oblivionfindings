<?php

namespace App\Domain\SecurityDevices\Screens\Sites;

use App\Domain\Monitoring\Discovery\Models\DiscoveryRun;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Hubs\Services\CoveringHubResolver;
use App\Domain\SecurityDevices\Hubs\Services\HubActionRules;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Models\DeviceRelationship;
use App\Domain\SecurityDevices\Screens\DeviceKind;
use App\Domain\SecurityDevices\Screens\DeviceState;
use App\Domain\SecurityDevices\Screens\Devices\DeviceDirectory;
use App\Domain\SecurityDevices\Screens\Devices\DeviceEntry;
use App\Domain\SecurityDevices\Screens\Hubs\HubPanelPresenter;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Domain\SecurityDevices\Services\SiteTopologyMapService;
use App\Domain\SecurityDevices\Alerts\Models\DeviceAlert;
use App\Domain\SecurityDevices\Screens\Alerts\AlertsPresenter;
use App\Models\ControlRoomAlert;
use App\Models\Site;
use App\Models\User;
use App\Services\ControlRoom\ControlRoomAlertAccessService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * One Site (S&D rebuild drawing pg-sdr-12, with its Network map 12b, Internet 12e, Rooms 12d, Modes
 * 12c and "hub stopped answering" 12f views). Only the open tab's data is worked out. The Devices,
 * Alerts and Logbook tabs (12g–12i) wait for their drawings' approval.
 */
final class SitePagePresenter
{
    public const array TABS = ['overview', 'network', 'internet', 'rooms', 'modes'];

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly DeviceDirectory $directory,
        private readonly CoveringHubResolver $hubs,
        private readonly ControlRoomAlertAccessService $alertAccess,
        private readonly SiteNetworkWeek $week,
        private readonly SiteTopologyMapService $topology,
        private readonly SiteRoomsPresenter $rooms,
        private readonly SiteModesPresenter $modes,
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
     * @param  array{tab?: mixed}  $input
     * @return array<string, mixed>
     */
    public function present(User $viewer, Site $site, array $input): array
    {
        $tab = in_array($input['tab'] ?? null, self::TABS, true) ? $input['tab'] : 'overview';
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
            'network' => $tab === 'network' ? $this->network($site, $entries) : null,
            'internet' => $tab === 'internet' ? $this->internet($viewer, $entries) : null,
            'rooms' => $tab === 'rooms' ? $this->rooms->present($viewer, $site, $entries) : null,
            'modes' => $tab === 'modes' ? $this->modes->present($site, $entries, $viewer->canDo('securityDevices.automations.manage')) : null,
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
     * @param  Collection<int, DeviceEntry>  $entries
     * @return array<string, mixed>
     */
    private function network(Site $site, Collection $entries): array
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

        return ['tree' => $this->topology->buildSiteTree($site, $devices, $rows, $relationships)];
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
     * Open alerts about this Site's devices that the viewer may read.
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
        $deviceAlerts = ! AlertsPresenter::canOpen($viewer) ? [] : DeviceAlert::query()->active()
            ->whereIn('device_id', $names->keys()->all())
            ->orderByRaw("CASE WHEN severity = 'critical' THEN 0 ELSE 1 END")->orderByDesc('opened_at')->limit(100)
            ->get(['id', 'device_id', 'title', 'opened_at'])
            ->map(fn (DeviceAlert $alert): array => [
                'id' => (int) $alert->id,
                'title' => $alert->title,
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
