<?php

namespace App\Domain\SecurityDevices\Screens\Setup;

use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Hubs\Services\CoveringHubResolver;
use App\Domain\SecurityDevices\Presenters\SupportedDevicesPresenter;
use App\Domain\SecurityDevices\Screens\Alerts\AlertRulesPresenter;
use App\Domain\SecurityDevices\Screens\DeviceKind;
use App\Domain\SecurityDevices\Screens\Devices\DeviceDirectory;
use App\Domain\SecurityDevices\Screens\Devices\DeviceEntry;
use App\Domain\SecurityDevices\Screens\DeviceState;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Domain\SecurityDevices\Setup\SecurityDevicesSettings;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Setup (S&D rebuild drawing pg-sdr-15 and its sections), laid out like Fleet's settings: the
 * numbers that need attention in the header, seven sections across the top, an Overview of
 * review cards first, then sub-tabs.
 *
 * Sections whose screens aren't built yet are left out, never stubbed: Hubs (15b/15p wait for
 * approval), Alerts & checks (Phase D), Computers & Obli (Phase I) and Doors & access (Phase H).
 */
final class SetupPresenter
{
    /** @var array<string, array{label: string, tabs: array<string, string>}> */
    public const array SECTIONS = [
        'overview' => ['label' => 'Overview', 'tabs' => []],
        'hubs' => ['label' => 'Hubs & networks', 'tabs' => ['networks' => 'Networks & scans', 'broker' => 'Sensor broker']],
        'services' => ['label' => 'Services & logins', 'tabs' => ['connected' => 'Connected services', 'logins' => 'Saved logins', 'supported' => 'Supported devices']],
        'alerts' => ['label' => 'Alerts & checks', 'tabs' => ['rules' => 'Alert rules', 'checks' => 'Checks']],
        // S&D Phase H (pg-sdr-15m): each Site's UniFi Access console, through its hub.
        'access' => ['label' => 'Doors & access', 'tabs' => ['unifi' => 'UniFi Access', 'clockins' => 'Clock-ins from doors']],
        // S&D Phase G (pg-sdr-15k): Computers & Obli › Remote support; S&D Phase I (15i, 15j): › Obli and › Fixes Obli can make.
        'computers' => ['label' => 'Computers & Obli', 'tabs' => ['obli' => 'Obli', 'fixes' => 'Fixes Obli can make', 'remote' => 'Remote support']],
        'history' => ['label' => 'Change history', 'tabs' => []],
    ];

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly CoveringHubResolver $hubs,
        private readonly DeviceDirectory $directory,
        private readonly SecurityDevicesSettings $settings,
        private readonly SetupNetworks $networks,
        private readonly SetupServices $services,
        private readonly SetupLogins $logins,
        private readonly SetupHistory $history,
        private readonly SupportedDevicesPresenter $supported,
        private readonly AlertRulesPresenter $rules,
        private readonly SetupBroker $broker,
    ) {}

    public static function canOpen(User $viewer): bool
    {
        return self::canSeeServices($viewer) || self::canSeeAlerts($viewer) || self::canSeeAccess($viewer) || SetupRemoteSupport::canSee($viewer)
            || \App\Domain\Obli\Fixes\Screens\SetupObli::canSee($viewer);
    }

    public static function canSeeServices(User $viewer): bool
    {
        return $viewer->canDo('securityDevices.integrations.view')
            || $viewer->canDo('securityDevices.integrations.manage');
    }

    public static function canSeeAlerts(User $viewer): bool
    {
        return $viewer->canDo('securityDevices.automations.manage')
            || $viewer->canDo('securityDevices.monitoring.manage');
    }

    /** S&D Phase H: Doors & access, for people who look after services or door access. */
    public static function canSeeAccess(User $viewer): bool
    {
        return self::canSeeServices($viewer)
            || $viewer->canDo('securityDevices.accessControl.manage');
    }

    public static function canSeeHistory(User $viewer): bool
    {
        return $viewer->canDo('securityDevices.reports.view')
            || $viewer->canDo('securityDevices.integrations.manage')
            || $viewer->canDo('securityDevices.automations.manage');
    }

    /** @return array<string, array{label: string, tabs: array<string, string>}> */
    public static function sectionsFor(User $viewer): array
    {
        $sections = self::SECTIONS;
        if (! self::canSeeServices($viewer)) {
            unset($sections['hubs'], $sections['services']);
        }
        if (! self::canSeeAlerts($viewer)) {
            unset($sections['alerts']);
        }
        if (! self::canSeeHistory($viewer)) {
            unset($sections['history']);
        }
        if (! self::canSeeAccess($viewer)) {
            unset($sections['access']);
        }
        // S&D Phase I: each Computers & Obli tab for the people who may see it; the section goes when none is left.
        if (! SetupRemoteSupport::canSee($viewer)) {
            unset($sections['computers']['tabs']['remote']);
        }
        if (! \App\Domain\Obli\Fixes\Screens\SetupObli::canSee($viewer)) {
            unset($sections['computers']['tabs']['obli']);
        }
        if (! \App\Domain\Obli\Fixes\Screens\SetupObli::canSeeFixes($viewer)) {
            unset($sections['computers']['tabs']['fixes']);
        }
        if ($sections['computers']['tabs'] === []) {
            unset($sections['computers']);
        }

        return $sections;
    }

    /**
     * @param  array{page?: mixed}  $query
     * @return array<string, mixed>
     */
    public function present(User $viewer, string $section, ?string $tab, array $query = []): array
    {
        $sections = self::sectionsFor($viewer);
        abort_unless(array_key_exists($section, $sections), 404);
        $tabs = $sections[$section]['tabs'];
        $tab = $tabs === [] ? null : (array_key_exists((string) $tab, $tabs) ? (string) $tab : array_key_first($tabs));

        $entries = $this->directory->entries($viewer);
        $seesServices = array_key_exists('services', $sections);
        // Someone who only manages alerts sees nothing about hubs, services or saved logins.
        $hubs = $seesServices ? $this->hubsFor($viewer) : collect();
        $noHub = $seesServices ? $this->sitesWithoutHub($viewer, $entries) : [];
        $services = $seesServices ? $this->services->present($viewer, $entries) : ['cards' => [], 'connected' => 0, 'total' => 0, 'attention' => []];
        $logins = $seesServices ? $this->logins->present($viewer) : ['rows' => [], 'kept' => [], 'failed' => [], 'due' => 0];
        $failedScans = $seesServices ? $this->networks->failedScans($viewer) : [];
        $todo = $this->todo($viewer, $hubs, $noHub, $services, $logins, $failedScans);
        $canManage = $viewer->canDo('securityDevices.integrations.manage');
        $meters = array_values(array_filter(
            $this->meters($hubs, $todo, $services, $logins, $entries, $viewer->canDo('securityDevices.devices.view')),
            fn (array $meter): bool => $seesServices || ! in_array($meter['key'], ['hubs', 'services', 'logins'], true),
        ));

        return [
            'section' => $section,
            'tab' => $tab,
            'sections' => collect($sections)->map(fn (array $definition, string $key): array => [
                'key' => $key,
                'label' => $definition['label'],
                'tabs' => collect($definition['tabs'])->map(fn (string $label, string $tabKey): array => ['key' => $tabKey, 'label' => $label])->values()->all(),
            ])->values()->all(),
            'meters' => $meters,
            'can' => [
                'manage' => $canManage,
                'add_network' => $canManage,
                'add_hub' => $canManage,
                'approve_hubs' => $viewer->canDo('securityDevices.hubs.approve'),
                'export_history' => self::canSeeHistory($viewer),
                'open_devices' => $viewer->canDo('securityDevices.devices.view'),
            ],
            'overview' => $section === 'overview' ? [
                'todo' => $todo,
                'cards' => $this->cards($viewer, $hubs, $noHub, $services, $logins, $failedScans, $sections),
            ] : null,
            'settings' => in_array($section, ['hubs', 'alerts', 'access'], true) && $tab !== 'clockins' ? $this->settings->forViewer($viewer) : null,
            'alerts' => $section === 'alerts' && $tab === 'rules' ? ['rules' => $this->rules->count($viewer)] : null,
            'checks' => $section === 'alerts' && $tab === 'checks' ? $this->checks($entries) : null,
            'networks' => $section === 'hubs' && $tab === 'networks' ? $this->networks->present($viewer) : null,
            'broker' => $section === 'hubs' && $tab === 'broker' ? $this->broker->present($viewer) : null,
            'connected' => $section === 'services' && $tab === 'connected' ? ['cards' => $services['cards']] : null,
            'logins' => $section === 'services' && $tab === 'logins' ? ['rows' => $logins['rows'], 'kept' => $logins['kept']] : null,
            'supported' => $section === 'services' && $tab === 'supported' ? $this->supportedTables($viewer) : null,
            'history' => $section === 'history' ? $this->history->present($viewer, (int) ($query['page'] ?? 1)) : null,
            // S&D Phase H (pg-sdr-15m): the Sites' UniFi Access consoles and what Oblivion keeps in step.
            'access' => $section === 'access' && $tab === 'unifi' ? app(\App\Domain\SecurityDevices\Access\AccessSetup::class)->present($viewer) : null,
            // S&D Phase H (pg-sdr-15n, D15): clock-ins suggested from door badges, and pop-up 92.
            'clockins' => $section === 'access' && $tab === 'clockins' ? app(\App\Domain\SecurityDevices\DoorClockins\Screens\SetupClockinsPresenter::class)->present($viewer) : null,
            // S&D Phase G (pg-sdr-15k): remote support to staff computers.
            'remote' => $section === 'computers' && $tab === 'remote' ? app(SetupRemoteSupport::class)->present($viewer) : null,
            // S&D Phase I (pg-sdr-15i, 15j): Obli's switches and rules, and the fixes Obli can make (pop-up 93).
            'obli' => $section === 'computers' && $tab === 'obli' ? app(\App\Domain\Obli\Fixes\Screens\SetupObli::class)->present($viewer) : null,
            'fixes' => $section === 'computers' && $tab === 'fixes' ? app(\App\Domain\Obli\Fixes\ObliFixList::class)->presentSetup($viewer) : null,
        ];
    }

    /** @return Collection<int, MonitoringCollector> the hubs that cover any of the viewer's Sites */
    private function hubsFor(User $viewer): Collection
    {
        $siteIds = $this->access->accessibleSiteIds($viewer);
        if ($siteIds === []) {
            return collect();
        }

        return MonitoringCollector::query()
            ->whereNull('revoked_at')
            ->whereNotNull('enrolled_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (MonitoringCollector $hub): bool => array_intersect($hub->authorisedSiteIds(), $siteIds) !== [])
            ->values();
    }

    /**
     * Sites with devices but no approved hub to check them.
     *
     * @param  Collection<int, DeviceEntry>  $entries
     * @return list<array{id: int, name: string}>
     */
    private function sitesWithoutHub(User $viewer, Collection $entries): array
    {
        $siteIds = $entries->pluck('siteId')->filter()->unique()->values();
        if ($siteIds->isEmpty()) {
            return [];
        }

        return Site::query()
            ->whereIn('id', $siteIds)
            ->where('is_active', true)
            ->where('archived', false)
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->filter(fn (Site $site): bool => $this->hubs->forSite((int) $site->id) === null)
            ->map(fn (Site $site): array => ['id' => (int) $site->id, 'name' => (string) $site->name])
            ->values()
            ->all();
    }

    private static function working(MonitoringCollector $hub): bool
    {
        return in_array((string) $hub->status, ['online', 'degraded'], true);
    }

    /**
     * "Still to set up": only things the viewer can act on or should know, worst first.
     *
     * @param  Collection<int, MonitoringCollector>  $hubs
     * @param  list<array{id: int, name: string}>  $noHub
     * @param  array<string, mixed>  $services
     * @param  array<string, mixed>  $logins
     * @param  list<array<string, mixed>>  $failedScans
     * @return list<array<string, mixed>>
     */
    private function todo(User $viewer, Collection $hubs, array $noHub, array $services, array $logins, array $failedScans): array
    {
        $items = [];
        foreach ($hubs->filter(fn (MonitoringCollector $hub): bool => $hub->approved_at !== null && ! self::working($hub)) as $hub) {
            $items[] = [
                'key' => 'hub-down-'.$hub->id, 'tone' => 'critical',
                'text' => '**'.CoveringHubResolver::label($hub).' has stopped answering.** Its Sites’ devices show “Can’t check” until it’s back.',
                'action' => null,
            ];
        }
        foreach ($services['attention'] as $index => $item) {
            $items[] = [
                'key' => 'service-'.$index, 'tone' => $item['tone'],
                'text' => "**{$item['name']}:** ".lcfirst($item['text']).'.',
                'action' => ['kind' => 'section', 'label' => 'Review connected services', 'section' => 'services', 'tab' => 'connected'],
            ];
        }
        $failed = count($logins['failed']);
        if ($failed > 0) {
            $first = $logins['failed'][0];
            $items[] = [
                'key' => 'logins-failed', 'tone' => 'critical',
                'text' => $failed === 1
                    ? '**'.$first['used_for'].($first['site'] !== null ? ' at '.$first['site']['name'] : '').': login failed.** Checks that need it stop until it’s replaced.'
                    : "**{$failed} saved logins failed.** Checks that need them stop until they’re replaced.",
                'action' => ['kind' => 'section', 'label' => 'Review saved logins', 'section' => 'services', 'tab' => 'logins'],
            ];
        }
        foreach ($hubs->filter(fn (MonitoringCollector $hub): bool => $hub->approved_at === null) as $hub) {
            $items[] = [
                'key' => 'hub-waiting-'.$hub->id, 'tone' => 'warning',
                'text' => '**'.CoveringHubResolver::label($hub).' is waiting for approval.** It gets no work and no saved logins until an admin approves it.',
                'action' => $viewer->canDo('securityDevices.hubs.approve')
                    ? ['kind' => 'approve_hub', 'label' => 'Approve hub', 'hub_id' => (int) $hub->id]
                    : null,
            ];
        }
        foreach (array_slice($noHub, 0, 3) as $site) {
            $items[] = [
                'key' => 'no-hub-'.$site['id'], 'tone' => 'warning',
                'text' => "**{$site['name']} has no hub yet.** Its devices can’t be checked until one is set up.",
                'action' => $viewer->canDo('securityDevices.integrations.manage')
                    ? ['kind' => 'add_hub', 'label' => 'Add a hub', 'site_id' => $site['id']]
                    : null,
            ];
        }
        if (count($noHub) > 3) {
            $more = count($noHub) - 3;
            $items[] = [
                'key' => 'no-hub-more', 'tone' => 'warning',
                'text' => $more === 1 ? '**1 more Site has no hub yet.**' : "**{$more} more Sites have no hub yet.**",
                'action' => null,
            ];
        }
        foreach (array_slice($failedScans, 0, 2) as $index => $scan) {
            $items[] = [
                'key' => 'scan-failed-'.$index, 'tone' => 'warning',
                'text' => "**{$scan['site']} network couldn’t be scanned** at {time}. Found devices there may be out of date.",
                'at' => $scan['at'],
                'action' => ['kind' => 'section', 'label' => 'Review networks', 'section' => 'hubs', 'tab' => 'networks'],
            ];
        }

        return $items;
    }

    /**
     * The six header numbers ("fleet"). Each opens its section when that section is built.
     *
     * @param  Collection<int, MonitoringCollector>  $hubs
     * @param  list<array<string, mixed>>  $todo
     * @param  array<string, mixed>  $services
     * @param  array<string, mixed>  $logins
     * @param  Collection<int, DeviceEntry>  $entries
     * @return list<array<string, mixed>>
     */
    private function meters(Collection $hubs, array $todo, array $services, array $logins, Collection $entries, bool $canOpenDevices): array
    {
        $approved = $hubs->filter(fn (MonitoringCollector $hub): bool => $hub->approved_at !== null);
        $online = $approved->filter(fn (MonitoringCollector $hub): bool => self::working($hub));
        $versions = $approved->map(fn (MonitoringCollector $hub): ?string => $hub->softwareVersion())->filter()->unique()->values();
        $waiting = $hubs->count() - $approved->count();
        $computers = $entries->filter(fn (DeviceEntry $entry): bool => $entry->kind === DeviceKind::Computer && $entry->state !== DeviceState::Retired);
        $doors = $entries->filter(fn (DeviceEntry $entry): bool => $entry->kind === DeviceKind::Door && $entry->state !== DeviceState::Retired);
        $failedLogins = count($logins['failed']);
        $loginCount = count($logins['rows']);
        $attention = count($services['attention']);

        $hubCaption = match (true) {
            $approved->isEmpty() && $waiting > 0 => $waiting === 1 ? '1 waiting for approval' : "{$waiting} waiting for approval",
            $approved->isEmpty() => 'None set up yet',
            $online->count() < $approved->count() => ($approved->count() - $online->count()).' stopped answering',
            default => 'Online'.($versions->count() === 1 ? ' · '.($approved->count() === 1 ? 'on ' : 'all on ').$versions->first() : ''),
        };

        return [
            [
                'key' => 'todo', 'label' => 'Still to set up', 'value' => (string) count($todo),
                'caption' => $todo === [] ? 'Nothing waiting' : self::shortTodo($todo),
                'tone' => $todo === [] ? 'success' : 'warning', 'section' => 'overview', 'tab' => null,
            ],
            [
                'key' => 'hubs', 'label' => 'Hubs', 'value' => $approved->isEmpty() ? '0' : $online->count().' of '.$approved->count(),
                'caption' => $hubCaption,
                'tone' => $approved->isNotEmpty() && $online->count() === $approved->count() ? 'success' : ($approved->isEmpty() ? null : 'critical'),
                'section' => 'hubs', 'tab' => 'networks',
            ],
            [
                'key' => 'services', 'label' => 'Connected services', 'value' => (string) $services['connected'],
                'caption' => $attention === 0 ? 'Of '.$services['total'].' accounts' : ($attention === 1 ? '1 needs attention' : "{$attention} need attention"),
                'tone' => $attention === 0 ? null : 'warning', 'section' => 'services', 'tab' => 'connected',
            ],
            [
                'key' => 'logins', 'label' => 'Saved logins', 'value' => (string) $loginCount,
                'caption' => match (true) {
                    $failedLogins > 0 => $failedLogins === 1 ? '1 failed' : "{$failedLogins} failed",
                    $logins['due'] > 0 => $logins['due'] === 1 ? '1 due to be replaced' : "{$logins['due']} due to be replaced",
                    $loginCount === 0 => 'None saved yet',
                    default => 'All working or not tested yet',
                },
                'tone' => $failedLogins > 0 ? 'critical' : ($logins['due'] > 0 ? 'warning' : null), 'section' => 'services', 'tab' => 'logins',
            ],
            [
                'key' => 'computers', 'label' => 'Computers with Obli', 'value' => (string) $computers->count(),
                'caption' => $computers->isEmpty() ? 'None yet' : $computers->filter(fn (DeviceEntry $entry): bool => $entry->state === DeviceState::Online)->count().' online',
                'tone' => null, 'section' => $canOpenDevices ? null : 'overview', 'tab' => null, 'href' => $canOpenDevices ? '/security-devices/devices?tab=computers' : null,
            ],
            [
                'key' => 'doors', 'label' => 'Doors', 'value' => (string) $doors->count(),
                'caption' => $doors->isEmpty() ? 'None yet' : self::andList($doors->pluck('siteName')->filter()->unique()->values()->all(), 2),
                'tone' => null, 'section' => $canOpenDevices ? null : 'overview', 'tab' => null, 'href' => $canOpenDevices ? '/security-devices/devices?tab=alarms_doors' : null,
            ],
        ];
    }

    /** @param  list<array<string, mixed>>  $todo */
    private static function shortTodo(array $todo): string
    {
        $names = array_map(function (array $item): string {
            preg_match('/\*\*(.+?)\*\*/u', (string) $item['text'], $match);

            return rtrim(trim((string) ($match[1] ?? '')), '.:');
        }, array_slice($todo, 0, 2));

        return implode(' · ', array_filter($names)).(count($todo) > 2 ? ' · '.(count($todo) - 2).' more' : '');
    }

    /** @param  list<string>  $names */
    private static function andList(array $names, int $show): string
    {
        $more = count($names) - $show;

        return SetupNetworks::andList(array_slice($names, 0, $show)).($more > 0 ? " and {$more} more" : '');
    }

    /**
     * One review card per built section, plus who can do what.
     *
     * @param  Collection<int, MonitoringCollector>  $hubs
     * @param  list<array{id: int, name: string}>  $noHub
     * @param  array<string, mixed>  $services
     * @param  array<string, mixed>  $logins
     * @param  list<array<string, mixed>>  $failedScans
     * @param  array<string, mixed>  $sections
     * @return list<array<string, mixed>>
     */
    private function cards(User $viewer, Collection $hubs, array $noHub, array $services, array $logins, array $failedScans, array $sections): array
    {
        $approved = $hubs->filter(fn (MonitoringCollector $hub): bool => $hub->approved_at !== null);
        $down = $approved->reject(fn (MonitoringCollector $hub): bool => self::working($hub));
        $versions = $approved->map(fn (MonitoringCollector $hub): ?string => $hub->softwareVersion())->filter()->unique()->values();

        $hubLines = [];
        if ($approved->isEmpty()) {
            $hubLines[] = ['tone' => 'neutral', 'text' => 'No hubs set up yet', 'at' => null];
        } elseif ($down->isEmpty()) {
            $count = $approved->count();
            $hubLines[] = ['tone' => 'success', 'text' => ($count === 1 ? '**1 hub online**' : "**{$count} hubs online**").($versions->count() === 1 ? ($count === 1 ? ', on ' : ', all on ').$versions->first() : ''), 'at' => null];
        } else {
            foreach ($down->take(2) as $hub) {
                $hubLines[] = ['tone' => 'critical', 'text' => CoveringHubResolver::label($hub).' has stopped answering', 'at' => null];
            }
        }
        if ($noHub !== []) {
            $hubLines[] = ['tone' => 'warning', 'text' => count($noHub) === 1 ? "{$noHub[0]['name']} has no hub yet" : count($noHub).' Sites have no hub yet', 'at' => null];
        }
        $hubLines[] = $failedScans !== []
            ? ['tone' => 'critical', 'text' => "{$failedScans[0]['site']} network couldn’t be scanned at {time}", 'at' => $failedScans[0]['at']]
            : ['tone' => 'success', 'text' => 'No failed scans', 'at' => null];

        $serviceLines = [['tone' => $services['connected'] > 0 ? 'success' : 'neutral', 'text' => "**{$services['connected']} of {$services['total']} accounts connected**", 'at' => null]];
        foreach (array_slice($services['attention'], 0, 2) as $item) {
            $serviceLines[] = ['tone' => $item['tone'], 'text' => "{$item['name']}: ".lcfirst($item['text']), 'at' => null];
        }

        $loginLines = [];
        foreach (array_slice($logins['failed'], 0, 2) as $row) {
            $loginLines[] = ['tone' => 'critical', 'text' => $row['used_for'].($row['site'] !== null ? ' at '.$row['site']['name'] : '').': **login failed**', 'at' => null];
        }
        if ($logins['due'] > 0) {
            $loginLines[] = ['tone' => 'warning', 'text' => $logins['due'] === 1 ? '1 account key is due to be replaced' : "{$logins['due']} account keys are due to be replaced", 'at' => null];
        }
        if ($loginLines === []) {
            $loginLines[] = ['tone' => count($logins['rows']) > 0 ? 'success' : 'neutral', 'text' => count($logins['rows']) > 0 ? 'No failed logins' : 'No saved logins yet', 'at' => null];
        }
        $loginLines[] = ['tone' => 'success', 'text' => 'Encrypted · a hub gets only its own Sites’ logins', 'at' => null];

        $supported = $this->supported->present($viewer)['summary'];
        $cards = [
            [
                'key' => 'hubs', 'icon' => 'router', 'title' => 'Hubs & networks', 'lines' => $hubLines,
                'link' => ['label' => 'Review hubs & networks', 'section' => 'hubs', 'tab' => 'networks'],
            ],
            [
                'key' => 'services', 'icon' => 'plug', 'title' => 'Connected services', 'lines' => $serviceLines,
                'link' => ['label' => 'Review connected services', 'section' => 'services', 'tab' => 'connected'],
            ],
            [
                'key' => 'logins', 'icon' => 'key', 'title' => 'Saved logins', 'lines' => $loginLines,
                'link' => ['label' => 'Review saved logins', 'section' => 'services', 'tab' => 'logins'],
            ],
            [
                'key' => 'supported', 'icon' => 'list-checks', 'title' => 'Supported devices',
                'lines' => [
                    ['tone' => 'success', 'text' => "**{$supported['tested_on_hardware']}** tested on real hardware, {$supported['built_from_docs']} built from vendor docs", 'at' => null],
                    ['tone' => 'neutral', 'text' => 'Planned brands say “Planned”, never a button', 'at' => null],
                ],
                'link' => ['label' => 'Review supported devices', 'section' => 'services', 'tab' => 'supported'],
            ],
        ];
        if (! array_key_exists('services', $sections)) {
            $cards = [];
        }
        if (array_key_exists('alerts', $sections)) {
            $cards[] = [
                'key' => 'alerts', 'icon' => 'bell', 'title' => 'Alerts & checks', 'lines' => $this->alertLines($viewer),
                'link' => ['label' => 'Review alerts & checks', 'section' => 'alerts', 'tab' => 'rules'],
            ];
        }
        $cards[] = [
            'key' => 'roles', 'icon' => 'users', 'title' => 'Who can do what', 'lines' => $this->roleLines(),
            'link' => $viewer->canDo('settings.access.manage')
                ? ['label' => 'Open Settings › Roles', 'href' => '/settings/roles']
                : null,
        ];
        if (array_key_exists('history', $sections)) {
            $latest = $this->history->latest($viewer);
            $cards[] = [
                'key' => 'history', 'icon' => 'history', 'title' => 'Change history',
                'lines' => [
                    $latest === null
                        ? ['tone' => 'neutral', 'text' => 'No changes yet', 'at' => null]
                        : ['tone' => 'info', 'text' => 'Last change at **{time}** by '.$latest['who'], 'at' => $latest['at']],
                    ['tone' => 'info', 'text' => 'Kept as long as your audit policy says', 'at' => null],
                ],
                'link' => ['label' => 'Review change history', 'section' => 'history', 'tab' => null],
            ];
        }

        foreach ($cards as &$card) {
            $tones = array_column($card['lines'], 'tone');
            $card['tone'] = in_array('critical', $tones, true) ? 'critical' : (in_array('warning', $tones, true) ? 'warning' : 'info');
        }
        unset($card);

        return $cards;
    }

    /**
     * Setup › Alerts & checks › Checks (pg-sdr-15h): what each kind of device gets today, from
     * the default offline check (the same one "Watch devices as soon as they're added" uses).
     *
     * @param  Collection<int, DeviceEntry>  $entries
     * @return array{rows: list<array<string, mixed>>}
     */
    private function checks(Collection $entries): array
    {
        $profile = AppDomainMonitoringModelsMonitoringProfile::query()
            ->where('name', AppDomainMonitoringModelsMonitoringProfile::DEFAULT_AVAILABILITY_NAME)->where('is_active', true)->first();
        $interval = (int) ($profile?->interval_seconds ?? 60);
        $misses = max(1, (int) ($profile?->failure_confirmations ?? 3));
        $often = $interval % 60 === 0 ? ($interval === 60 ? 'Every minute' : 'Every '.intdiv($interval, 60).' minutes') : "Every {$interval} seconds";

        $rows = $entries
            ->reject(fn (DeviceEntry $entry): bool => $entry->state === DeviceState::Retired)
            ->groupBy(fn (DeviceEntry $entry): string => $entry->kind->label())
            ->map(function (Collection $group, string $label) use ($often, $misses): array {
                $watched = $group->filter(fn (DeviceEntry $entry): bool => $entry->watching !== [])->count();
                $words = $group->flatMap(fn (DeviceEntry $entry): array => $entry->watching)->unique()->values()->all();

                return [
                    'key' => IlluminateSupportStr::slug($label),
                    'kind' => $label,
                    'check' => $words === [] ? 'Not watched yet' : ucfirst(SetupNetworks::andList(array_slice($words, 0, 3))),
                    'often' => $watched === 0 ? null : $often,
                    'after' => $watched === 0 ? null : ($misses === 1 ? '1 missed check' : "{$misses} missed checks in a row"),
                    'devices' => $group->count(),
                    'watched' => $watched,
                ];
            })
            ->sortBy('kind')
            ->values()
            ->all();

        return ['rows' => $rows];
    }

    /** The Alerts & checks card: who is told by default, how, and how many rules there are. @return list<array{tone: string, text: string, at: null}> */
    private function alertLines(User $viewer): array
    {
        $how = fn (array $channels): string => $channels === []
            ? 'nobody is told'
            : SetupNetworks::andList(array_map(fn (string $channel): string => match ($channel) {
                'database' => 'app', 'mail' => 'email', default => 'push',
            }, $channels));
        $role = $this->settings->role(SecurityDevicesSettings::DEVICES_ROLE);
        $who = $role === null ? 'IT for that Site' : (string) (Role::query()->where('name', $role)->value('label') ?: $role);
        $devices = $this->settings->channels(SecurityDevicesSettings::DEVICES_APP, SecurityDevicesSettings::DEVICES_EMAIL, SecurityDevicesSettings::DEVICES_PUSH);
        $found = $this->settings->channels(SecurityDevicesSettings::NEW_DEVICES_APP, SecurityDevicesSettings::NEW_DEVICES_EMAIL, SecurityDevicesSettings::NEW_DEVICES_PUSH);
        $rules = $this->rules->count($viewer);

        return [
            ['tone' => $devices === [] ? 'warning' : 'success', 'text' => $devices === [] ? 'Nobody is told when a device stops answering' : "Device or hub stops answering: {$who} ({$how($devices)})", 'at' => null],
            ['tone' => 'info', 'text' => 'New device found by a scan: '.($found === [] ? 'no notice' : $how($found)), 'at' => null],
            ['tone' => 'info', 'text' => $rules === 0 ? 'No rules of your own yet: these defaults apply' : ($rules === 1 ? '**1 rule** of your own' : "**{$rules} rules** of your own"), 'at' => null],
        ];
    }

    /** @return list<array{tone: string, text: string, at: null}> */
    private function roleLines(): array
    {
        $roles = Role::query()->with('permissions:id,key')->orderBy('level')->get(['id', 'name', 'label', 'level']);
        $holders = function (string $key) use ($roles): string {
            $names = $roles
                ->filter(fn (Role $role): bool => $role->permissions->contains('key', $key))
                ->map(fn (Role $role): string => (string) ($role->label ?: ucfirst(str_replace('_', ' ', (string) $role->name))))
                ->values()
                ->all();

            return $names === [] ? 'nobody yet' : self::andList($names, 3);
        };

        return [
            ['tone' => 'info', 'text' => 'Watch cameras live, with a reason: '.$holders('securityDevices.cctv.live.watch'), 'at' => null],
            ['tone' => 'info', 'text' => 'Unlock doors: '.$holders('securityDevices.doors.unlock'), 'at' => null],
            ['tone' => 'info', 'text' => 'Approve and remove hubs: '.$holders('securityDevices.hubs.approve'), 'at' => null],
        ];
    }

    /** @return array{tables: list<array<string, mixed>>} */
    private function supportedTables(User $viewer): array
    {
        $rows = collect($this->supported->present($viewer)['drivers'])->map(fn (array $driver): array => [
            'key' => (string) $driver['key'],
            'brand' => trim((string) $driver['brand'].' '.((string) $driver['name'] !== (string) $driver['brand'] ? (string) $driver['name'] : '')),
            'models' => implode(', ', array_slice(array_values(array_filter((array) ($driver['models'] ?? []), 'is_string')), 0, 4)),
            'kind' => (string) $driver['device_class_label'],
            'class' => (string) $driver['device_class'],
            'works' => (string) ($driver['summary'] ?? ''),
            'view_only' => (bool) ($driver['view_only'] ?? false),
            'level' => ['text' => (string) $driver['support_level_label'], 'tone' => (string) $driver['support_badge_variant']],
            'level_key' => (string) $driver['support_level'],
            // How we know: the maker's or the standard's own documents the driver follows.
            'docs' => collect((array) ($driver['official_docs'] ?? []))
                ->filter(fn (mixed $doc): bool => is_array($doc) && is_string($doc['url'] ?? null) && str_starts_with($doc['url'], 'https://'))
                ->take(2)
                ->map(fn (array $doc): array => ['label' => (string) ($doc['label'] ?? $doc['title'] ?? 'Maker’s documents'), 'url' => (string) $doc['url']])
                ->values()
                ->all(),
        ]);
        $order = ['tested_on_hardware' => 0, 'built_from_docs' => 1, 'planned' => 2];
        $sort = fn (Collection $group): array => $group
            ->sortBy(fn (array $row): string => ($order[$row['level_key']] ?? 3).'-'.$row['brand'])
            ->values()
            ->all();

        return ['tables' => [
            [
                'key' => 'alarms', 'title' => 'Alarms and doors', 'sub' => 'Your home kit is tested first (D10)',
                'rows' => $sort($rows->filter(fn (array $row): bool => in_array($row['class'], ['alarm_panel', 'access_control'], true))),
            ],
            [
                'key' => 'other', 'title' => 'Network, cameras, sensors and computers', 'sub' => 'Common in NZ homes and offices',
                'rows' => $sort($rows->reject(fn (array $row): bool => in_array($row['class'], ['alarm_panel', 'access_control'], true))),
            ],
        ]];
    }
}
