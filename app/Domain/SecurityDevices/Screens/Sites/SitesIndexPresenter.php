<?php

namespace App\Domain\SecurityDevices\Screens\Sites;

use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Hubs\Services\CoveringHubResolver;
use App\Domain\SecurityDevices\Hubs\Services\HubActionRules;
use App\Domain\SecurityDevices\Screens\Devices\DeviceDirectory;
use App\Domain\SecurityDevices\Screens\Hubs\HubPanelPresenter;
use App\Domain\SecurityDevices\Screens\Devices\DeviceEntry;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\ControlRoomAlert;
use App\Models\Site;
use App\Models\User;
use App\Services\ControlRoom\ControlRoomAlertAccessService;
use Illuminate\Support\Collection;

/**
 * Sites (S&D rebuild drawing pg-sdr-11): one card per house and office the viewer may see, how its
 * devices are doing and which hub watches it. Scoped by SecurityDevicesAccessService; another Site
 * never shows.
 */
final class SitesIndexPresenter
{
    public const array TABS = ['all', 'attention', 'not_set_up'];

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly DeviceDirectory $directory,
        private readonly CoveringHubResolver $hubs,
        private readonly ControlRoomAlertAccessService $alertAccess,
    ) {}

    /**
     * @param  array{tab?: mixed, q?: mixed, region?: mixed}  $input
     * @return array<string, mixed>
     */
    public function present(User $viewer, array $input): array
    {
        $sites = $this->access->accessibleSites($viewer)
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('archived')->orWhere('archived', false))
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get(['id', 'name', 'address_line_1', 'suburb', 'city', 'region']);
        $entriesBySite = $this->directory->entries($viewer)->groupBy(fn (DeviceEntry $entry): int => (int) $entry->siteId);
        $alerts = $this->deviceAlerts($viewer, $entriesBySite);
        $hubsBySite = $sites->mapWithKeys(fn (Site $site): array => [(int) $site->id => $this->hubs->forSite((int) $site->id)]);
        // "Watched by" opens the hub panel (sdr-13) only for a hub whose own Site the viewer sees.
        $visibleSiteIds = HubPanelPresenter::mayView($viewer) ? $this->access->accessibleSiteIds($viewer) : [];

        $cards = $sites->map(function (Site $site) use ($entriesBySite, $alerts, $hubsBySite, $visibleSiteIds): array {
            $entries = $entriesBySite->get((int) $site->id, collect());
            $hub = $hubsBySite->get((int) $site->id);
            $siteAlerts = $alerts[(int) $site->id] ?? [];
            $flag = SiteFacts::flag($entries, $hub, $siteAlerts);

            return [
                'id' => (int) $site->id,
                'name' => (string) $site->name,
                'address' => SiteFacts::address($site),
                'region' => is_string($site->region) && trim($site->region) !== '' ? trim($site->region) : null,
                'device_count' => $entries->count(),
                'flag' => $flag,
                'facts' => SiteFacts::cardFacts($entries, $hub, (int) $site->id, $siteAlerts),
                'needs_attention' => in_array($flag['tone'], ['critical', 'warning'], true),
                'set_up' => $hub !== null,
                'hub' => $hub !== null && in_array((int) $hub->site_id, $visibleSiteIds, true)
                    ? ['id' => (int) $hub->id, 'label' => CoveringHubResolver::label($hub)]
                    : null,
                'href' => '/security-devices/sites/'.$site->id,
            ];
        })->values();

        $tab = in_array($input['tab'] ?? null, self::TABS, true) ? $input['tab'] : 'all';
        $search = is_string($input['q'] ?? null) ? mb_strtolower(trim($input['q'])) : '';
        $region = is_string($input['region'] ?? null) && trim($input['region']) !== '' ? trim($input['region']) : null;
        $filtered = $cards
            ->filter(fn (array $card): bool => $region === null || $card['region'] === $region)
            ->filter(fn (array $card): bool => $search === ''
                || str_contains(mb_strtolower($card['name'].' '.$card['address']), $search))
            ->values();

        return [
            'tab' => $tab,
            'filters' => ['q' => $search === '' ? null : $input['q'], 'region' => $region],
            'regions' => $cards->pluck('region')->filter()->unique()->sort()->values()->all(),
            'tabs' => [
                'all' => $filtered->count(),
                'attention' => $filtered->where('needs_attention', true)->count(),
                'not_set_up' => $filtered->where('set_up', false)->count(),
            ],
            'meters' => $this->meters($cards, $hubsBySite),
            'cards' => match ($tab) {
                'attention' => $filtered->where('needs_attention', true)->values()->all(),
                'not_set_up' => $filtered->where('set_up', false)->values()->all(),
                default => $filtered->all(),
            },
            'can' => [
                'add_device' => $viewer->canDo('securityDevices.devices.create'),
                'scan' => $viewer->canDo('securityDevices.integrations.manage'),
                // Add a hub (sdr-12) and its approval (sdr-79), by the same rule the hub routes use.
                'add_hub' => HubActionRules::allows($viewer, 'securityDevices.integrations.manage'),
                'approve_hub' => HubActionRules::allows($viewer, 'securityDevices.hubs.approve'),
            ],
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $cards
     * @param  Collection<int, MonitoringCollector|null>  $hubsBySite
     * @return array<string, mixed>
     */
    private function meters(Collection $cards, Collection $hubsBySite): array
    {
        $setUp = $cards->where('set_up', true)->count();
        $notSetUp = $cards->where('set_up', false)->values();
        $hubs = $hubsBySite->filter()->unique(fn (MonitoringCollector $hub): int => (int) $hub->id)->values();
        $hubsOnline = $hubs->filter(fn (MonitoringCollector $hub): bool => in_array((string) $hub->status, ['online', 'degraded'], true))->count();
        // The last update report each hub sent: one that rolled back or failed is worth saying.
        $notInstalled = $hubs->filter(fn (MonitoringCollector $hub): bool => in_array(
            is_array($hub->update_state) ? ($hub->update_state['outcome'] ?? null) : null,
            ['rolled_back', 'failed'],
            true,
        ))->count();
        $attention = $cards->where('needs_attention', true)->values();

        return [
            'set_up' => [
                'count' => $setUp,
                'of' => $cards->count(),
                'caption' => match (true) {
                    $cards->isEmpty() => 'No Sites yet',
                    $notSetUp->isEmpty() => 'Every Site has a hub',
                    $notSetUp->count() === 1 => $notSetUp->first()['name'].' has no hub yet',
                    default => $notSetUp->count().' Sites have no hub yet',
                },
            ],
            'hubs' => [
                'online' => $hubsOnline,
                'of' => $hubs->count(),
                'caption' => match (true) {
                    $hubs->isEmpty() => 'No hubs yet',
                    $hubsOnline < $hubs->count() => ($hubs->count() - $hubsOnline).' not answering',
                    $notInstalled > 0 => $notInstalled.' last update didn’t install',
                    default => $hubs->count() === 1 ? 'Answering' : 'All answering',
                },
            ],
            'attention' => [
                'count' => $attention->count(),
                'caption' => match (true) {
                    $attention->isEmpty() => 'Nothing needs attention',
                    $attention->count() <= 2 => $attention->pluck('name')->join(', '),
                    default => $attention->take(2)->pluck('name')->join(', ').' and '.($attention->count() - 2).' more',
                },
            ],
        ];
    }

    /**
     * Active control-room alerts about a Site's devices that the viewer may read, by Site, as their
     * titles. Other control-room alerts (shifts, H&S) aren't device alerts and stay out.
     *
     * @param  Collection<int, Collection<int, DeviceEntry>>  $entriesBySite
     * @return array<int, list<string>>
     */
    private function deviceAlerts(User $viewer, Collection $entriesBySite): array
    {
        if (! $viewer->canDo('controlRoom.viewAny') && ! $viewer->canDo('controlRoom.alerts.view')) {
            return [];
        }
        $siteByDevice = [];
        foreach ($entriesBySite as $siteId => $entries) {
            foreach ($entries as $entry) {
                $siteByDevice[$entry->id()] = (int) $siteId;
            }
        }
        if ($siteByDevice === []) {
            return [];
        }
        $alerts = ControlRoomAlert::query()
            ->whereIn('status', ControlRoomAlert::ACTIVE_STATUSES)
            ->whereHas('device', fn ($device) => $device->whereIn('canonical_device_id', array_keys($siteByDevice)))
            ->with('device:id,canonical_device_id')
            ->orderByDesc('id')
            ->limit(500)
            ->get(['id', 'alert_type', 'device_id', 'site_id', 'status']);
        $readable = $this->alertAccess->readableIds($alerts, $viewer)->flip();

        $bySite = [];
        foreach ($alerts as $alert) {
            $siteId = $siteByDevice[(int) $alert->device?->canonical_device_id] ?? null;
            if ($siteId !== null && $readable->has((int) $alert->id)) {
                $bySite[$siteId][] = trim((string) $alert->alert_type) !== '' ? trim((string) $alert->alert_type) : 'An alert is open';
            }
        }

        return array_map(fn (array $titles): array => array_values(array_unique($titles)), $bySite);
    }
}
