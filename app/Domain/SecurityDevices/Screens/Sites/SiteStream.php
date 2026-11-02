<?php

namespace App\Domain\SecurityDevices\Screens\Sites;

use App\Domain\Monitoring\Discovery\Models\DiscoveryRun;
use App\Domain\Monitoring\Models\MonitoringMaintenanceWindow;
use App\Domain\SecurityDevices\Access\Models\AccessConsole;
use App\Domain\SecurityDevices\Access\Models\AccessEvent;
use App\Domain\SecurityDevices\Alerts\Models\DeviceAlert;
use App\Domain\SecurityDevices\Alerts\Models\DeviceAlertEvent;
use App\Domain\SecurityDevices\Care\DevicePersonLinks;
use App\Domain\SecurityDevices\Care\Models\DevicePersonLink;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Modes\Models\HouseModeEvent;
use App\Domain\SecurityDevices\Screens\Access\AccessNames;
use App\Domain\SecurityDevices\Screens\Access\AccessPagePresenter;
use App\Domain\SecurityDevices\Screens\Alerts\AlertsPresenter;
use App\Domain\SecurityDevices\Screens\Devices\DeviceEntry;
use App\Domain\SecurityDevices\Screens\Devices\Pages\DevicePageWords;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Client;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * What happened at the viewer's Sites, in plain sentences, newest first: the Site page's Logbook
 * (S&D drawing pg-sdr-12i) and the Overview's Activity tab (pg-sdr-18c).
 *
 * Who sees what:
 * - Only for people who may see device events (securityDevices.events.view), and only about the
 *   viewer's own devices at their own Sites. Device events follow the temporal custody rule, so a
 *   device moved here never brings another Site's history with it; an alert counts for the Site it
 *   was opened at.
 * - A care rule's alert names its resident only to someone who may open that resident's record;
 *   everyone else reads "a person". Sensor readings never show minute by minute: only the alert
 *   when the care rule acts.
 * - Doors worth a look show only to people who can see Access, and name staff by its one rule
 *   (AccessNames): matched staff, for 90 days; residents never.
 * - Camera viewing stays in Overview › Reports with each reason.
 */
final class SiteStream
{
    /** The kinds a line can be, in the order the filters list them. */
    public const array KINDS = ['alert', 'hs', 'alarm', 'mode', 'device', 'door', 'planned'];

    /** Alert kinds whose title names the resident a care rule is about. */
    private const array CARE_KINDS = ['care_out_of_bed', 'care_door_night'];

    /** Device events worth a line. Readings, reports and check results never show here. */
    private const array EVENT_KINDS = [
        'alarm_armed' => 'alarm', 'alarm_disarmed' => 'alarm', 'zone_bypassed' => 'alarm', 'zone_unbypassed' => 'alarm',
        'config_changed' => 'device', 'firmware_updated' => 'device', 'firmware.updated' => 'device',
        'hub_update_rolled_back' => 'device', 'reboot' => 'device', 'restarted' => 'device',
    ];

    /** People's actions on an alert that make a line of their own in the logbook. */
    private const array ALERT_ACTIONS = ['acknowledged', 'assigned', 'snoozed', 'incident', 'recovered'];

    private const int PER_SOURCE = 300;

    public function __construct(private readonly SecurityDevicesAccessService $access) {}

    public static function zone(): string
    {
        return (string) config('app.worker_timezone', 'Pacific/Auckland');
    }

    /** The stream is device events: only for people who may see them. */
    public static function mayView(User $viewer): bool
    {
        return $viewer->canDo('securityDevices.events.view');
    }

    /**
     * Every line between two instants, newest first.
     *
     * @param  Collection<int, DeviceEntry>  $entries  the viewer's devices at these Sites
     * @param  array<int, string>  $sites  the viewer's Sites in view, id => name
     * @param  bool  $logbook  people's actions on an alert get lines of their own (the Site logbook);
     *                         otherwise each alert is one line with what happened next (Activity)
     * @return list<array<string, mixed>>
     */
    public function between(User $viewer, Collection $entries, array $sites, CarbonImmutable $from, CarbonImmutable $to, bool $logbook, int $limit = 400): array
    {
        if (! self::mayView($viewer) || $sites === [] || $to->lte($from)) {
            return [];
        }
        $byId = $entries->keyBy(fn (DeviceEntry $entry): int => $entry->id());
        $now = CarbonImmutable::now('UTC');
        $lines = [
            ...$this->alerts($viewer, $byId, $sites, $from, $to, $now, $logbook),
            ...$this->deviceEvents($viewer, $byId, $sites, $from, $to),
            ...$this->modes($sites, $from, $to, count($sites) > 1),
            ...$this->scans($sites, $from, $to, count($sites) > 1),
            ...$this->quietTimes($byId, $sites, $from, $to, $now),
            ...($logbook ? [] : $this->doors($viewer, $sites, $from, $to, $now)),
        ];
        usort($lines, fn (array $a, array $b): int => [$b['at'], $b['key']] <=> [$a['at'], $a['key']]);

        return array_slice($lines, 0, $limit);
    }

    /**
     * The night before a NZ day: from the evening switch into a Night house mode to the morning
     * switch out of it, when the viewer's Sites have one; otherwise 9:00 pm to 7:00 am.
     *
     * @param  array<int, string>  $sites
     * @return array{from: CarbonImmutable, to: CarbonImmutable, sites: list<string>}
     */
    public function night(array $sites, CarbonImmutable $day): array
    {
        $zone = self::zone();
        $local = $day->setTimezone($zone)->startOfDay();
        $fallback = [
            'from' => $local->subDay()->setTime(21, 0)->utc(),
            'to' => $local->setTime(7, 0)->utc(),
            'sites' => [],
        ];
        if ($sites === []) {
            return $fallback;
        }
        $switches = HouseModeEvent::query()->with('mode:id,name')->done()
            ->where('kind', HouseModeEvent::KIND_SWITCH)
            ->whereIn('site_id', array_keys($sites))
            ->where('at', '>=', $local->subDay()->setTime(15, 0)->utc())
            ->where('at', '<', $local->setTime(12, 0)->utc())
            ->orderBy('at')
            ->limit(200)
            ->get(['id', 'site_id', 'mode_id', 'at']);
        $isNight = fn (HouseModeEvent $event): bool => preg_match('/night/i', (string) ($event->mode?->name ?? '')) === 1;
        $into = $switches->filter(fn (HouseModeEvent $event): bool => $isNight($event) && $event->at->lt($local->setTime(3, 0)->utc()));
        $start = $into->first();
        if ($start === null) {
            return $fallback;
        }
        $end = $switches->first(fn (HouseModeEvent $event): bool => ! $isNight($event) && $event->at->gt($start->at));
        if ($end === null) {
            return $fallback;
        }

        return [
            'from' => $start->at,
            'to' => $end->at,
            'sites' => $into->pluck('site_id')->unique()->map(fn (mixed $id): string => (string) ($sites[(int) $id] ?? ''))->filter()->values()->all(),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Alerts                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * @param  Collection<int, DeviceEntry>  $byId
     * @param  array<int, string>  $sites
     * @return list<array<string, mixed>>
     */
    private function alerts(User $viewer, Collection $byId, array $sites, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $now, bool $logbook): array
    {
        if ($byId->isEmpty() || ! AlertsPresenter::canOpen($viewer)) {
            return [];
        }
        $alerts = DeviceAlert::query()
            ->with(['events' => fn ($events) => $events->with('actor:id,name')])
            ->whereIn('device_id', $byId->keys()->all())
            ->whereIn('site_id', array_keys($sites))
            ->where(fn ($window) => $window
                ->where(fn ($opened) => $opened->where('opened_at', '>=', $from)->where('opened_at', '<', $to))
                ->when($logbook, fn ($also) => $also->orWhereHas('events', fn ($events) => $events
                    ->whereIn('kind', self::ALERT_ACTIONS)->where('at', '>=', $from)->where('at', '<', $to))))
            ->orderByDesc('opened_at')
            ->limit(self::PER_SOURCE)
            ->get();
        $people = self::carePeople($viewer, $alerts);
        $lines = [];
        foreach ($alerts as $alert) {
            $words = new AlertWords($alert, $people[(int) $alert->id] ?? false);
            $entry = $byId->get((int) $alert->device_id);
            $base = [
                'kind' => $alert->kind === 'scald_risk' ? 'hs' : 'alert',
                'site_id' => (int) $alert->site_id,
                'site' => (string) ($sites[(int) $alert->site_id] ?? ''),
                'device_id' => (int) $alert->device_id,
                'device_kind' => $entry?->kind->value,
                'href' => '/security-devices/alerts?alert='.$alert->id,
            ];
            $opened = $alert->opened_at;
            if ($opened->gte($from) && $opened->lt($to)) {
                $lines[] = [
                    ...$base,
                    'key' => 'alert-'.$alert->id,
                    'at' => $opened->toIso8601String(),
                    'tone' => $alert->severity === 'critical' ? 'critical' : 'warning',
                    'title' => $words->title(),
                    'sub' => $logbook ? $words->openedLine() : $words->what((string) ($entry?->name() ?? '')),
                    'next' => $logbook ? null : $words->next($now),
                    'by' => null,
                    'action' => ['label' => 'Open alert', 'href' => $base['href']],
                ];
            }
            if (! $logbook) {
                continue;
            }
            foreach ($alert->events as $event) {
                if (! in_array($event->kind, self::ALERT_ACTIONS, true) || $event->at->lt($from) || $event->at->gte($to)) {
                    continue;
                }
                $lines[] = [
                    ...$base,
                    'key' => 'alert-'.$alert->id.'-'.$event->id,
                    'at' => $event->at->toIso8601String(),
                    'tone' => $event->kind === 'recovered' ? 'success' : 'info',
                    'title' => $words->action($event),
                    'sub' => implode(' · ', array_filter([$words->title(), $words->text($event->detail)])),
                    'next' => null,
                    'by' => $event->actor?->name,
                    'action' => null,
                ];
            }
        }

        return $lines;
    }

    /**
     * For each care alert, whether its resident's name must be hidden from this viewer: false when
     * they may see it (or the alert names nobody), the name to hide, or true when the resident can't
     * be worked out (then nothing that could name them is shown).
     *
     * @param  Collection<int, DeviceAlert>  $alerts
     * @return array<int, string|bool>
     */
    public static function carePeople(User $viewer, Collection $alerts): array
    {
        $care = $alerts->filter(fn (DeviceAlert $alert): bool => in_array($alert->kind, self::CARE_KINDS, true));
        if ($care->isEmpty()) {
            return [];
        }
        $links = DevicePersonLink::query()->with('client')
            ->whereIn('device_id', $care->pluck('device_id')->unique()->all())
            ->whereIn('purpose', ['falls', 'wandering'])
            ->orderByDesc('id')
            ->get();
        $out = [];
        foreach ($care as $alert) {
            $purpose = $alert->kind === 'care_out_of_bed' ? 'falls' : 'wandering';
            $link = $links->first(fn (DevicePersonLink $link): bool => (int) $link->device_id === (int) $alert->device_id
                && $link->purpose === $purpose
                && ($link->linked_at === null || $link->linked_at->lte($alert->opened_at)));
            $client = $link?->client;
            if (! $client instanceof Client) {
                $out[(int) $alert->id] = true;

                continue;
            }
            $out[(int) $alert->id] = Gate::forUser($viewer)->allows('view', $client) ? false : DevicePersonLinks::name($client);
        }

        return $out;
    }

    /* ------------------------------------------------------------------ */
    /*  Device events, house modes, scans, quiet times and doors           */
    /* ------------------------------------------------------------------ */

    /**
     * @param  Collection<int, DeviceEntry>  $byId
     * @param  array<int, string>  $sites
     * @return list<array<string, mixed>>
     */
    private function deviceEvents(User $viewer, Collection $byId, array $sites, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($byId->isEmpty()) {
            return [];
        }
        $query = DeviceEvent::query()
            ->whereIn('device_events.device_id', $byId->keys()->all())
            ->whereIn('device_events.event_type', array_keys(self::EVENT_KINDS))
            ->where('device_events.occurred_at', '>=', $from)
            ->where('device_events.occurred_at', '<', $to);
        $this->access->applyTemporalEventCustodyScope($query, $viewer);

        return $query->orderByDesc('device_events.occurred_at')->orderByDesc('device_events.id')->limit(self::PER_SOURCE)
            ->get(['device_events.id', 'device_events.device_id', 'device_events.event_type', 'device_events.severity', 'device_events.payload', 'device_events.source', 'device_events.occurred_at'])
            ->map(function (DeviceEvent $event) use ($byId, $sites): array {
                /** @var DeviceEntry $entry */
                $entry = $byId->get((int) $event->device_id);
                $type = strtolower((string) $event->event_type);
                $payload = is_array($event->payload) ? $event->payload : [];
                $name = $entry->name();
                $summary = trim(rtrim(trim((string) ($payload['summary'] ?? '')), '.'));
                $zone = trim((string) ($payload['zone_name'] ?? ''));
                $mode = preg_match('/\((away|stay\/partial)\)/', $summary, $match) === 1 ? ($match[1] === 'away' ? ' (away)' : ' (stay)') : '';
                $changes = (int) ($payload['changed_count'] ?? 0);

                return [
                    'key' => 'event-'.$event->id,
                    'at' => $event->occurred_at?->toIso8601String(),
                    'tone' => match ($type) {
                        'alarm_disarmed', 'config_changed', 'zone_bypassed' => 'info',
                        'hub_update_rolled_back' => 'warning',
                        default => DevicePageWords::tone((string) $event->severity) === 'critical' ? 'critical' : 'success',
                    },
                    'kind' => self::EVENT_KINDS[$type],
                    'title' => match ($type) {
                        'alarm_armed' => $name.' armed'.$mode,
                        'alarm_disarmed' => $name.' disarmed',
                        'zone_bypassed' => ($zone !== '' ? $zone : 'A zone').' bypassed on '.$name,
                        'zone_unbypassed' => ($zone !== '' ? $zone : 'A zone').' back in use on '.$name,
                        'config_changed' => 'Settings changed on '.$name,
                        'hub_update_rolled_back' => $name.': its update didn’t install, so it went back',
                        'reboot', 'restarted' => $name.' restarted',
                        default => $name.' software updated',
                    },
                    'sub' => match (true) {
                        $type === 'config_changed' => $changes > 0
                            ? 'Its settings backup found '.$changes.' '.($changes === 1 ? 'change' : 'changes')
                            : 'Found in its settings backup',
                        $summary !== '' && str_starts_with($type, 'alarm_') => $summary,
                        default => DevicePageWords::event($event)['sub'],
                    },
                    'site_id' => $entry->siteId,
                    'site' => (string) ($sites[(int) $entry->siteId] ?? ($entry->siteName ?? '')),
                    'device_id' => $entry->id(),
                    'device_kind' => $entry->kind->value,
                    'href' => '/security-devices/devices/'.$entry->id(),
                    'next' => null,
                    'by' => null,
                    'action' => null,
                ];
            })
            ->all();
    }

    /**
     * Switches and the checks after them. A switch by schedule at the same minute at several Sites
     * is one line ("Night → Day at Aurora House and Kauri House").
     *
     * @param  array<int, string>  $sites
     * @return list<array<string, mixed>>
     */
    private function modes(array $sites, CarbonImmutable $from, CarbonImmutable $to, bool $named): array
    {
        $events = HouseModeEvent::query()->with('actor:id,name')->done()
            ->whereIn('site_id', array_keys($sites))
            ->where('at', '>=', $from)->where('at', '<', $to)
            ->orderByDesc('at')->orderByDesc('id')
            ->limit(self::PER_SOURCE)
            ->get();
        $lines = [];
        foreach ($events as $event) {
            $byHand = $event->kind === HouseModeEvent::KIND_SWITCH && $event->source === 'hand';
            $schedule = $event->kind === HouseModeEvent::KIND_SWITCH && ! $byHand;
            $group = $schedule ? 'mode-'.$event->at->format('YmdHi').'-'.$event->title : 'mode-'.$event->id;
            $site = (string) ($sites[(int) $event->site_id] ?? '');
            if (isset($lines[$group])) {
                $lines[$group]['sites'][] = $site;

                continue;
            }
            $notes = array_values(array_filter(array_map(fn (mixed $line): string => is_array($line) ? trim((string) ($line['text'] ?? '')) : '', (array) $event->lines)));
            $warn = collect((array) $event->lines)->contains(fn (mixed $line): bool => is_array($line) && ($line['tone'] ?? null) === 'warning');
            $lines[$group] = [
                'key' => 'mode-'.$event->id,
                'at' => $event->at->toIso8601String(),
                'tone' => $event->kind === HouseModeEvent::KIND_CHECK ? ($warn ? 'warning' : 'success') : ($warn ? 'warning' : 'info'),
                'kind' => 'mode',
                'title' => $event->kind === HouseModeEvent::KIND_SWITCH ? 'House mode '.$event->title : (string) $event->title,
                'sub' => implode(' · ', array_filter([
                    $event->kind === HouseModeEvent::KIND_SWITCH ? ($byHand ? 'By hand' : 'By schedule') : null,
                    $byHand ? $event->actor?->name : null,
                    ...$notes,
                ])),
                'site_id' => (int) $event->site_id,
                'site' => $site,
                'sites' => [$site],
                'device_id' => null,
                'device_kind' => null,
                'href' => '/security-devices/sites/'.$event->site_id.'?tab=modes',
                'next' => null,
                'by' => $byHand ? $event->actor?->name : null,
                'action' => null,
            ];
        }

        return array_values(array_map(function (array $line) use ($named): array {
            $places = array_values(array_unique(array_filter($line['sites'])));
            if ($named && $places !== []) {
                $line['title'] .= ' at '.self::list($places);
            }
            if (count($places) > 1) {
                $line['site'] = self::list($places);
            }
            unset($line['sites']);

            return $line;
        }, $lines));
    }

    /**
     * @param  array<int, string>  $sites
     * @return list<array<string, mixed>>
     */
    private function scans(array $sites, CarbonImmutable $from, CarbonImmutable $to, bool $named): array
    {
        return DiscoveryRun::query()->with('scope:id,site_id')
            ->whereHas('scope', fn ($scope) => $scope->whereIn('site_id', array_keys($sites)))
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', $from)->where('completed_at', '<', $to)
            ->orderByDesc('completed_at')
            ->limit(self::PER_SOURCE)
            ->get(['id', 'discovery_scope_id', 'status', 'found_count', 'proposed_count', 'completed_at'])
            ->map(function (DiscoveryRun $run) use ($sites, $named): array {
                $siteId = (int) ($run->scope?->site_id ?? 0);
                $site = (string) ($sites[$siteId] ?? '');
                $new = (int) $run->proposed_count;

                return [
                    'key' => 'scan-'.$run->id,
                    'at' => $run->completed_at?->toIso8601String(),
                    'tone' => 'success',
                    'kind' => 'device',
                    'title' => $named && $site !== '' ? 'Scan of '.$site.' finished' : 'Scan finished',
                    'sub' => (int) $run->found_count.' found'.($new > 0 ? ' · '.$new.' new' : ''),
                    'site_id' => $siteId,
                    'site' => $site,
                    'device_id' => null,
                    'device_kind' => null,
                    'href' => '/security-devices/scans/'.$run->id,
                    'next' => null,
                    'by' => null,
                    'action' => ['label' => 'Review', 'href' => '/security-devices/scans/'.$run->id],
                ];
            })
            ->all();
    }

    /**
     * Quiet times someone planned, when they planned them. Only for the viewer's devices, or a
     * whole Site of theirs.
     *
     * @param  Collection<int, DeviceEntry>  $byId
     * @param  array<int, string>  $sites
     * @return list<array<string, mixed>>
     */
    private function quietTimes(Collection $byId, array $sites, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $now): array
    {
        $visible = $byId->keys()->all() ?: [0];

        return MonitoringMaintenanceWindow::query()->with(['creator:id,name', 'monitor:id,device_id'])
            ->whereIn('site_id', array_keys($sites))
            ->where(fn ($scope) => $scope->where(fn ($site) => $site->whereNull('device_id')->whereNull('monitor_id'))
                ->orWhereIn('device_id', $visible)
                ->orWhereIn('monitor_id', \App\Domain\Monitoring\Models\Monitor::query()->whereIn('device_id', $visible)->select('id')))
            ->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->orderByDesc('created_at')
            ->limit(self::PER_SOURCE)
            ->get(['id', 'site_id', 'device_id', 'monitor_id', 'name', 'starts_at', 'ends_at', 'reason', 'status', 'created_by_user_id', 'created_at'])
            ->map(function (MonitoringMaintenanceWindow $window) use ($byId, $sites, $now): array {
                $deviceId = $window->device_id ?? $window->monitor?->device_id;
                $entry = $deviceId !== null ? $byId->get((int) $deviceId) : null;
                $site = (string) ($sites[(int) $window->site_id] ?? '');
                $creator = $window->creator?->name;

                return [
                    'key' => 'quiet-'.$window->id,
                    'at' => CarbonImmutable::parse($window->created_at)->toIso8601String(),
                    'tone' => 'info',
                    'kind' => 'planned',
                    'title' => 'Quiet time planned for '.($entry !== null ? $entry->name() : ($site !== '' ? $site : 'the Site')),
                    'sub' => implode(' · ', array_filter([
                        self::clock($window->starts_at, $now).' – '.self::clock($window->ends_at, $now, $window->starts_at),
                        filled($window->reason) ? Str::lower(Str::limit((string) $window->reason, 80)) : null,
                        $creator,
                    ])),
                    'site_id' => (int) $window->site_id,
                    'site' => $site,
                    'device_id' => $entry?->id(),
                    'device_kind' => $entry?->kind->value,
                    'href' => $entry !== null ? '/security-devices/devices/'.$entry->id() : null,
                    'next' => $window->status === 'cancelled' ? 'Cancelled' : null,
                    'by' => $creator,
                    'action' => null,
                ];
            })
            ->all();
    }

    /**
     * Doors worth a look (forced, held open, refused, unlocked from Oblivion), for people who can see
     * Access. Every door coming and going stays in Access › Activity.
     *
     * @param  array<int, string>  $sites
     * @return list<array<string, mixed>>
     */
    private function doors(User $viewer, array $sites, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $now): array
    {
        if (! AccessPagePresenter::canSeeDoors($viewer)) {
            return [];
        }
        $consoles = AccessConsole::query()->whereIn('site_id', array_keys($sites))->pluck('site_id', 'id');
        if ($consoles->isEmpty()) {
            return [];
        }

        return AccessEvent::query()
            ->with(['door:id,name', 'person:id,kind,client_id,match_state,user_id', 'person.user:id,name'])
            ->whereIn('console_id', $consoles->keys()->all())
            ->whereIn('kind', ['forced', 'held_open', 'denied', 'unlocked'])
            ->where('occurred_at', '>=', $from)->where('occurred_at', '<', $to)
            ->orderByDesc('occurred_at')
            ->limit(self::PER_SOURCE)
            ->get()
            ->map(function (AccessEvent $event) use ($consoles, $sites, $now): array {
                $who = AccessNames::who($event, $event->person, $now);
                $door = (string) ($event->door?->name ?? 'A door');
                $siteId = (int) ($consoles[$event->console_id] ?? 0);
                $named = $who['named'] ? $who['text'] : null;

                return [
                    'key' => 'door-'.$event->id,
                    'at' => $event->occurred_at->toIso8601String(),
                    'tone' => match ($event->kind) {
                        'forced', 'denied' => 'critical',
                        'held_open' => 'warning',
                        default => 'info',
                    },
                    'kind' => 'door',
                    'title' => match ($event->kind) {
                        'forced' => $door.' forced open',
                        'held_open' => $door.' held open',
                        'denied' => 'Refused at '.$door,
                        default => $door.' unlocked from Oblivion',
                    },
                    'sub' => $named ?? ($event->kind === 'denied' && ! in_array($who['text'], ['—', 'Resident fob'], true) ? $who['text'] : null),
                    'site_id' => $siteId,
                    'site' => (string) ($sites[$siteId] ?? ''),
                    'device_id' => null,
                    'device_kind' => null,
                    'href' => '/security-devices/access?tab=activity&site='.$siteId,
                    'next' => null,
                    'by' => $named,
                    'action' => null,
                ];
            })
            ->all();
    }

    /* ------------------------------------------------------------------ */
    /*  Words                                                              */
    /* ------------------------------------------------------------------ */

    /** An instant in NZ time: "9:33 am" on the same day as `$same` (or today), else "Thu 29 Oct, 9:33 am". */
    public static function clock(?CarbonImmutable $at, CarbonImmutable $now, ?CarbonImmutable $same = null): string
    {
        if ($at === null) {
            return '—';
        }
        $zone = self::zone();
        $local = $at->setTimezone($zone);

        return $local->isSameDay(($same ?? $now)->setTimezone($zone)) ? $local->format('g:i a') : $local->format('D j M, g:i a');
    }

    /** @param list<string> $parts "a", "a and b", "a, b and c". */
    public static function list(array $parts): string
    {
        $parts = array_values($parts);
        if (count($parts) <= 1) {
            return $parts[0] ?? '';
        }
        $last = array_pop($parts);

        return implode(', ', $parts).' and '.$last;
    }
}
