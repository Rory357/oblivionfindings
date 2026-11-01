<?php

namespace App\Domain\SecurityDevices\Screens\Devices\Pages;

use App\Domain\SecurityDevices\Drivers\Services\DeviceDriverRegistry;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Screens\DeviceKind;
use App\Domain\SecurityDevices\Screens\DeviceState;
use App\Domain\SecurityDevices\Screens\Devices\DeviceDirectory;
use App\Domain\SecurityDevices\Screens\Devices\DeviceEntry;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The alarm panel's page (S&D rebuild Phase E, drawings pg-sdr-5 Ajax view only, 5b Zones, 5c an Inner
 * Range Inception panel armed and disarmed from Oblivion, 5d Notes & documents): its armed state,
 * areas, zones and recent events in plain words, read from the panel's own reports.
 *
 * Arm and disarm are offered only where the maker allows it (a declared Inception or Gallagher
 * driver) and only to someone with the rebuild key; they are today's governed commands (step-up,
 * approval, read-back). Ajax and other report-only panels are view only, said once. The Events and
 * Settings tabs have no approved drawing yet, so they aren't built. Only the open tab's data is read.
 */
final class AlarmPagePresenter
{
    public const array TAB_LABELS = [
        'overview' => 'Overview',
        'zones' => 'Zones',
        'checks' => 'Checks & alerts',
        'notes' => 'Notes & documents',
    ];

    private const array ARMED = ['alarm_armed', 'alarm.armed', 'armed', 'armed_away', 'armed_stay'];

    private const array DISARMED = ['alarm_disarmed', 'alarm.disarmed', 'disarmed'];

    private const array ALARM = ['alarm_triggered', 'alarm_trigger', 'alarm.triggered', 'fire_alarm', 'medical_alarm', 'intrusion', 'panic', 'duress'];

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly DeviceDirectory $directory,
        private readonly DeviceChecksTab $checks,
        private readonly DeviceNotesTab $notes,
        private readonly SafetyKitCommands $commands,
        private readonly DeviceDriverRegistry $drivers,
    ) {}

    public static function serves(Device $device): bool
    {
        return DeviceKind::of($device) === DeviceKind::AlarmPanel;
    }

    /**
     * @param  array{tab?: mixed, step_up_at?: ?CarbonImmutable}  $input
     * @return array<string, mixed>
     */
    public function present(User $viewer, Device $device, array $input): array
    {
        $now = CarbonImmutable::now();
        $entry = $this->directory->entries($viewer, null, [(int) $device->id])->first();
        $state = $entry?->state ?? DeviceState::Retired;
        $siteId = $entry?->siteId;
        $siteName = $entry?->siteName;
        $canSeeEvents = $viewer->canDo('securityDevices.events.view');

        $tabs = array_values(array_filter(['overview', 'zones', $canSeeEvents ? 'checks' : null, 'notes']));
        $tab = in_array($input['tab'] ?? null, $tabs, true) ? (string) $input['tab'] : 'overview';

        $driver = $this->drivers->resolveForDevice($device)?->metadata();
        $brand = $this->brand($device, $driver?->brand);
        $stepUpAt = $input['step_up_at'] ?? null;
        $arm = $this->commands->action($viewer, $device, $siteId, 'alarm.arm', $stepUpAt);
        $disarm = $this->commands->action($viewer, $device, $siteId, 'alarm.disarm', $stepUpAt);
        $controllable = $this->commands->declares($device, 'alarm.arm') || $this->commands->declares($device, 'alarm.disarm');

        $events = $canSeeEvents
            ? $this->access->applyTemporalEventCustodyScope(DeviceEvent::query()->where('device_id', $device->id), $viewer)
                ->latest('occurred_at')
                ->latest('id')
                ->limit(300)
                ->get(['id', 'event_type', 'severity', 'payload', 'source', 'occurred_at'])
            : collect();
        $areas = $this->areas($device, $events);
        $zones = $this->zones($device, $events, $areas);
        $panel = $this->panelState($events, $areas, $canSeeEvents);
        $lastReport = $events->first()?->occurred_at;
        $commands = $this->commands->recent($viewer, $device, ['alarm.arm', 'alarm.disarm'], $now);

        $control = [
            'controllable' => $controllable,
            'view_only' => ! $controllable,
            'brand' => $brand,
            'arm' => $arm,
            'disarm' => $disarm,
            // A refusal in words (e.g. a hub covers the Site and alarm commands through a hub aren't built yet).
            'refusal' => collect([$disarm, $arm])->first(fn (?array $action): bool => $action !== null && ! $action['available'])['reason'] ?? null,
            'commands' => $commands,
            // Every zone's state, so Arm can name an open one before anything is sent.
            'zones' => array_values($zones),
            'confirm_identity_url' => route('security-devices.devices.safety.confirm-identity', ['device' => $device->id], false),
        ];

        return [
            'device' => [
                'id' => (int) $device->id,
                'name' => (string) $device->name,
                'kind' => DeviceKind::AlarmPanel->value,
                'kind_label' => DeviceKind::AlarmPanel->label(),
                'state' => $state->value,
                'badge' => ['label' => $state->label(), 'tone' => $state->tone()],
                'model' => $entry?->model() ?? trim(trim((string) $device->manufacturer).' '.trim((string) $device->model)),
                'site' => $siteName,
                'site_id' => $siteId,
                'address' => $device->ip_address ? (string) $device->ip_address : null,
                'watching' => $entry === null || $state === DeviceState::NotWatched ? 'Nothing' : Str::ucfirst(implode(', ', $entry->watching)),
                'subline' => $this->subline($device, $entry, $siteName, $controllable, $lastReport),
                'back' => ['href' => '/security-devices/devices?tab=alarms_doors', 'label' => 'Devices'],
            ],
            'tab' => $tab,
            'tabs' => array_map(fn (string $key): array => ['key' => $key, 'label' => self::TAB_LABELS[$key]], $tabs),
            'panel' => $panel,
            'meters' => $this->meters($events, $zones, $areas, $panel, $canSeeEvents, $now),
            'can' => [
                'edit' => $viewer->canDo('securityDevices.devices.update') && $state !== DeviceState::Retired,
                'watch' => $viewer->canDo('securityDevices.monitoring.manage'),
                'move' => $viewer->canDo('securityDevices.devices.assign'),
                'retire' => $viewer->canDo('securityDevices.devices.delete'),
                'assign' => $viewer->canDo('securityDevices.devices.assign'),
                'upload' => $viewer->canDo('securityDevices.devices.update') && $state !== DeviceState::Retired,
                'see_events' => $canSeeEvents,
                'arm' => $arm !== null && $arm['available'] && $areas !== [],
                'disarm' => $disarm !== null && $disarm['available'] && $areas !== [],
            ],
            'control' => $control,
            'areas' => array_values($areas),
            'overview' => $tab === 'overview' ? [
                'lines' => $this->lines($zones, $panel, $control, $areas, $lastReport, $brand),
                'zones' => array_slice(array_values($zones), 0, 6),
                'zone_count' => count($zones),
                'last_report' => $lastReport?->toIso8601String(),
                'events' => $canSeeEvents ? $this->recent($events->take(8), $areas, $zones) : [],
                'facts' => $this->forIt($events, $controllable, $arm ?? $disarm, $driver?->limitationsNote, $brand),
            ] : null,
            'zones' => $tab === 'zones' ? [
                'rows' => array_values($zones),
                'modes_help' => $brand === 'Ajax',
            ] : null,
            'checks' => $tab === 'checks'
                ? [
                    ...$this->checks->present($viewer, $device, DeviceKind::AlarmPanel, $this->monitors($device), $siteId, $siteName, DeviceObservations::custodyStart($device), $now),
                    'backups' => [],
                ]
                : null,
            'notes' => $tab === 'notes' ? $this->notes->present($viewer, $device, $now) : null,
        ];
    }

    /**
     * The panel's areas this viewer can know about, for checking an arm or disarm request.
     *
     * @return list<string>
     */
    public function areaIds(User $viewer, Device $device): array
    {
        $events = $viewer->canDo('securityDevices.events.view')
            ? $this->access->applyTemporalEventCustodyScope(DeviceEvent::query()->where('device_id', $device->id), $viewer)
                ->latest('occurred_at')
                ->limit(300)
                ->get(['id', 'event_type', 'payload', 'source', 'occurred_at'])
            : collect();

        return array_map('strval', array_keys($this->areas($device, $events)));
    }

    private function monitors(Device $device): Collection
    {
        return $device->monitors()
            ->where('is_enabled', true)
            ->with([
                'profile:id,name,interval_seconds,stale_after_seconds,failure_confirmations,failure_duration_seconds',
                'collector:id,name,site_id,status',
            ])
            ->orderBy('name')
            ->get();
    }

    /** "Ajax" for an Ajax hub, "Inner Range" for an Inception panel; the maker's name in general. */
    private function brand(Device $device, ?string $driverBrand): ?string
    {
        $brand = $driverBrand ?? trim((string) $device->manufacturer);
        if ($brand === '') {
            return null;
        }

        return str_starts_with(strtolower($brand), 'ajax') ? 'Ajax' : $brand;
    }

    /**
     * Areas (partitions): named in the panel's settings, plus any its reports mention.
     *
     * @param  Collection<int, DeviceEvent>  $events
     * @return array<string, array{id: string, name: string, armed: ?bool, mode: ?string, since: ?string, by: ?string}>
     */
    private function areas(Device $device, Collection $events): array
    {
        $areas = [];
        foreach ($this->configuredNames($device, 'areas') as $id => $name) {
            $areas[$id] = ['id' => $id, 'name' => $name, 'armed' => null, 'mode' => null, 'since' => null, 'by' => null];
        }
        foreach ($events as $event) {
            $type = strtolower((string) $event->event_type);
            if (! in_array($type, [...self::ARMED, ...self::DISARMED], true)) {
                continue;
            }
            $id = $this->areaOf($event) ?? 'main';
            $areas[$id] ??= ['id' => $id, 'name' => $id === 'main' ? 'Whole house' : 'Area '.ltrim($id, '0'), 'armed' => null, 'mode' => null, 'since' => null, 'by' => null];
            if ($areas[$id]['armed'] !== null) {
                continue;
            }
            $summary = strtolower((string) data_get($event->payload, 'summary', ''));
            $areas[$id]['armed'] = in_array($type, self::ARMED, true);
            $areas[$id]['mode'] = ! $areas[$id]['armed'] ? null : match (true) {
                str_contains($summary, 'stay') || str_contains($summary, 'partial') || $type === 'armed_stay' => 'at home',
                str_contains($summary, 'away') || $type === 'armed_away' => 'away',
                default => null,
            };
            $areas[$id]['since'] = $event->occurred_at?->toIso8601String();
            $areas[$id]['by'] = $this->byWords($event);
        }

        return $areas;
    }

    /**
     * Zones: named in the panel's settings, with each one's latest reported state.
     *
     * @param  Collection<int, DeviceEvent>  $events
     * @param  array<string, array<string, mixed>>  $areas
     * @return array<string, array{id: string, name: string, number: ?string, state: string, label: string, tone: string, area: ?string, changed_at: ?string}>
     */
    private function zones(Device $device, Collection $events, array $areas): array
    {
        $zones = [];
        $add = function (string $id, ?string $name) use (&$zones): void {
            $zones[$id] ??= [
                'id' => $id,
                'name' => $name ?? 'Zone '.ltrim($id, '0'),
                'number' => ltrim($id, '0') ?: $id,
                'state' => 'not_reported',
                'label' => 'No report yet',
                'tone' => 'neutral',
                'area' => null,
                'changed_at' => null,
            ];
        };
        foreach ($this->configuredNames($device, 'zones') as $id => $name) {
            $add($id, $name);
        }
        foreach ($events as $event) {
            $payload = is_array($event->payload) ? $event->payload : [];
            $id = $this->identifier($payload['zone'] ?? $payload['zone_id'] ?? null);
            if ($id === null) {
                continue;
            }
            $name = is_string($payload['zone_name'] ?? null) && trim($payload['zone_name']) !== '' ? mb_substr(trim($payload['zone_name']), 0, 48) : null;
            $add($id, $name);
            if ($zones[$id]['state'] !== 'not_reported') {
                continue;
            }
            $type = strtolower((string) $event->event_type);
            $raw = strtolower(trim((string) ($payload['zone_state'] ?? '')));
            $state = in_array($raw, ['normal', 'open', 'triggered', 'bypassed', 'tamper', 'fault'], true)
                ? $raw
                : (str_contains($type, 'bypass') ? 'bypassed' : (str_contains($type, 'trigger') || in_array($type, self::ALARM, true) ? 'triggered' : null));
            if ($state === null) {
                continue;
            }
            [$label, $tone] = match ($state) {
                'normal' => ['Ready', 'success'],
                'open' => ['Open', 'warning'],
                'triggered' => ['Alarm', 'critical'],
                'bypassed' => ['Left out', 'warning'],
                'tamper' => ['Tampered with', 'warning'],
                'fault' => ['Fault', 'warning'],
            };
            $areaId = $this->areaOf($event);
            $zones[$id] = [
                ...$zones[$id],
                'state' => $state,
                'label' => $label,
                'tone' => $tone,
                'area' => $areaId !== null ? ($areas[$areaId]['name'] ?? null) : null,
                'changed_at' => $event->occurred_at?->toIso8601String(),
            ];
        }

        uasort($zones, fn (array $a, array $b): int => strnatcasecmp((string) $a['number'], (string) $b['number']));

        return $zones;
    }

    /**
     * The panel as a whole: armed, partly armed, disarmed, an alarm going off, or not reported.
     *
     * @param  Collection<int, DeviceEvent>  $events
     * @param  array<string, array<string, mixed>>  $areas
     * @return array<string, mixed>
     */
    private function panelState(Collection $events, array $areas, bool $canSeeEvents): array
    {
        if (! $canSeeEvents) {
            return ['state' => 'hidden', 'label' => 'Not available', 'tone' => 'neutral', 'mode' => null, 'since' => null, 'armed' => null, 'total' => null];
        }
        $known = array_values(array_filter($areas, fn (array $area): bool => $area['armed'] !== null));
        $armed = array_values(array_filter($known, fn (array $area): bool => $area['armed'] === true));
        $lastDisarm = $events->first(fn (DeviceEvent $event): bool => in_array(strtolower((string) $event->event_type), self::DISARMED, true))?->occurred_at;
        $alarm = $events->first(fn (DeviceEvent $event): bool => in_array(strtolower((string) $event->event_type), self::ALARM, true));
        $alarmNow = $alarm !== null && ($lastDisarm === null || $alarm->occurred_at?->greaterThan($lastDisarm)) && $alarm->occurred_at?->greaterThan(now()->subDay());

        if ($alarmNow) {
            return ['state' => 'alarm', 'label' => 'Alarm', 'tone' => 'critical', 'mode' => null, 'since' => $alarm->occurred_at?->toIso8601String(), 'armed' => count($armed), 'total' => count($areas)];
        }
        if ($known === []) {
            return ['state' => 'not_reported', 'label' => 'No state yet', 'tone' => 'neutral', 'mode' => null, 'since' => null, 'armed' => null, 'total' => count($areas) ?: null];
        }
        if ($armed === []) {
            $since = collect($known)->pluck('since')->filter()->max();

            return ['state' => 'disarmed', 'label' => 'Disarmed', 'tone' => 'neutral', 'mode' => null, 'since' => $since, 'armed' => 0, 'total' => count($areas)];
        }
        $modes = collect($armed)->pluck('mode')->unique()->values();
        $mode = $modes->count() === 1 ? $modes->first() : null;
        $since = collect($armed)->pluck('since')->filter()->max();
        $partly = count($armed) < count($areas);

        return [
            'state' => $partly ? 'partly' : 'armed',
            'label' => $partly ? 'Partly armed' : ($mode !== null ? 'Armed ('.$mode.')' : 'Armed'),
            'tone' => 'info',
            'mode' => $mode,
            'since' => $since,
            'armed' => count($armed),
            'total' => count($areas),
        ];
    }

    /**
     * The header numbers. value null = "Not available".
     *
     * @param  Collection<int, DeviceEvent>  $events
     * @param  array<string, array<string, mixed>>  $zones
     * @param  array<string, array<string, mixed>>  $areas
     * @param  array<string, mixed>  $panel
     * @return list<array<string, mixed>>
     */
    private function meters(Collection $events, array $zones, array $areas, array $panel, bool $canSeeEvents, CarbonImmutable $now): array
    {
        $meters = [];
        if (count($areas) > 1) {
            $meters[] = self::meter('areas', 'Areas armed', $panel['armed'] !== null ? $panel['armed'].' of '.count($areas) : null, $panel['armed'] === null
                ? ($canSeeEvents ? 'No area has reported yet' : 'You can’t see this panel’s reports')
                : collect($areas)->firstWhere('armed', false)['name'] ?? 'Every area armed', 'brand', 'overview');
        } else {
            $reported = array_filter($zones, fn (array $zone): bool => $zone['state'] !== 'not_reported');
            $ready = array_filter($reported, fn (array $zone): bool => $zone['state'] === 'normal');
            $notReady = array_values(array_filter($reported, fn (array $zone): bool => $zone['state'] !== 'normal'));
            $meters[] = self::meter('zones', 'Zones ready', $reported === [] ? null : count($ready).' of '.count($zones), match (true) {
                ! $canSeeEvents => 'You can’t see this panel’s reports',
                $reported === [] => 'No zone has reported yet',
                $notReady === [] => 'Every zone ready',
                default => $notReady[0]['name'].' '.mb_strtolower($notReady[0]['label']),
            }, $reported !== [] && $notReady !== [] ? 'warning' : ($reported !== [] ? 'success' : 'brand'), 'zones');
        }

        $power = $events->first(fn (DeviceEvent $event): bool => preg_match('/(ac_|mains|power|on_battery)/', strtolower((string) $event->event_type)) === 1);
        $onBattery = $power !== null && preg_match('/(loss|lost|fail|on_battery)/', strtolower((string) $power->event_type)) === 1;
        $meters[] = self::meter('power', 'Power', $power === null ? null : ($onBattery ? 'On battery' : 'On mains'), $power === null
            ? 'Not reported by this panel'
            : ($onBattery ? 'Since {time}' : 'Last change {time}'), $power === null ? 'brand' : ($onBattery ? 'warning' : 'success'), 'overview', $power?->occurred_at?->toIso8601String());

        $alarms = $events->filter(fn (DeviceEvent $event): bool => in_array(strtolower((string) $event->event_type), self::ALARM, true)
            && $event->occurred_at?->greaterThanOrEqualTo($now->subDays(30)));
        $last = $alarms->first();
        $meters[] = self::meter('alarms', 'Alarms in 30 days', $canSeeEvents ? (string) $alarms->count() : null, match (true) {
            ! $canSeeEvents => 'You can’t see this panel’s reports',
            $last === null => 'None in the last 30 days',
            default => 'Last one {time}',
        }, $alarms->isNotEmpty() ? 'warning' : 'brand', 'overview', $last?->occurred_at?->toIso8601String());

        return $meters;
    }

    /** @return array<string, mixed> */
    private static function meter(string $key, string $label, ?string $value, string $caption, string $tone, string $tab, ?string $at = null): array
    {
        return ['key' => $key, 'label' => $label, 'value' => $value, 'caption' => ['text' => $caption, 'at' => $at], 'tone' => $tone, 'tab' => $tab];
    }

    /**
     * The lines under the armed state: view only, zones left out, whether Oblivion can arm it.
     *
     * @param  array<string, array<string, mixed>>  $zones
     * @param  array<string, mixed>  $panel
     * @param  array<string, mixed>  $control
     * @param  array<string, array<string, mixed>>  $areas
     * @return list<array{state: string, lead: ?string, text: string, at: ?string}>
     */
    private function lines(array $zones, array $panel, array $control, array $areas, ?CarbonInterface $lastReport, ?string $brand): array
    {
        $lines = [];
        if ($control['view_only']) {
            $lines[] = ['state' => 'info', 'lead' => 'View only.', 'text' => $brand === 'Ajax' ? 'Arm and disarm in the Ajax app.' : 'Arm and disarm at the keypad or in the maker’s app.', 'at' => null];
        }
        foreach ($zones as $zone) {
            if ($zone['state'] === 'bypassed') {
                $lines[] = ['state' => 'warning', 'lead' => $zone['name'], 'text' => 'is left out, so it isn’t watched while armed · since {time}', 'at' => $zone['changed_at']];
            } elseif ($zone['state'] === 'open' && in_array($panel['state'], ['armed', 'partly'], true)) {
                $lines[] = ['state' => 'warning', 'lead' => $zone['name'], 'text' => 'is open · since {time}', 'at' => $zone['changed_at']];
            }
        }
        if ($control['controllable']) {
            if ($control['refusal'] !== null) {
                $lines[] = ['state' => 'warning', 'lead' => null, 'text' => (string) $control['refusal'], 'at' => null];
            } elseif (($control['arm'] !== null || $control['disarm'] !== null) && $areas === []) {
                $lines[] = ['state' => 'warning', 'lead' => null, 'text' => 'Oblivion hasn’t heard which areas this panel has yet, so arm and disarm wait until it reports them', 'at' => null];
            } elseif ($control['arm'] !== null || $control['disarm'] !== null) {
                $lines[] = ['state' => 'success', 'lead' => null, 'text' => $lastReport !== null ? 'Arm and disarm here · the panel last reported {ago}' : 'Arm and disarm here', 'at' => $lastReport?->toIso8601String()];
                $lines[] = ['state' => 'success', 'lead' => null, 'text' => 'After each command Oblivion reads the state back from the panel; if it doesn’t change, you’re told', 'at' => null];
            } else {
                $lines[] = ['state' => 'none', 'lead' => null, 'text' => 'Oblivion can arm and disarm this panel, but your role can’t', 'at' => null];
            }
        }

        return $lines;
    }

    /**
     * The newest reports as log lines, in plain words.
     *
     * @param  Collection<int, DeviceEvent>  $events
     * @param  array<string, array<string, mixed>>  $areas
     * @param  array<string, array<string, mixed>>  $zones
     * @return list<array<string, mixed>>
     */
    private function recent(Collection $events, array $areas, array $zones): array
    {
        return $events
            ->filter(fn (DeviceEvent $event): bool => strtolower((string) $event->event_type) !== 'heartbeat')
            ->take(5)
            ->map(function (DeviceEvent $event) use ($areas, $zones): array {
                $type = strtolower((string) $event->event_type);
                $payload = is_array($event->payload) ? $event->payload : [];
                $zoneId = $this->identifier($payload['zone'] ?? $payload['zone_id'] ?? null);
                $zone = $zoneId !== null ? ($zones[$zoneId]['name'] ?? 'Zone '.ltrim($zoneId, '0')) : null;
                $areaId = $this->areaOf($event);
                $area = $areaId !== null && count($areas) > 1 ? ($areas[$areaId]['name'] ?? null) : null;
                $by = $this->byWords($event);
                $summary = strtolower((string) ($payload['summary'] ?? ''));
                $mode = match (true) {
                    str_contains($summary, 'stay') || str_contains($summary, 'partial') || $type === 'armed_stay' => ' (at home)',
                    str_contains($summary, 'away') || $type === 'armed_away' => ' (away)',
                    default => '',
                };
                [$tone, $title, $sub] = match (true) {
                    in_array($type, self::ARMED, true) => ['info', ($area !== null ? $area.' armed' : 'Armed').$mode, $by],
                    in_array($type, self::DISARMED, true) => ['success', ($area !== null ? $area.' disarmed' : 'Disarmed'), $by],
                    in_array($type, ['panic', 'duress'], true) => ['critical', 'Panic button'.($zone ? ': '.$zone : ''), $area],
                    in_array($type, self::ALARM, true) => ['critical', 'Alarm'.($zone ? ': '.$zone : ''), $area],
                    $type === 'zone_bypassed' => ['warning', ($zone ?? 'A zone').' left out', 'Not watched while armed'],
                    $type === 'zone_unbypassed' => ['success', ($zone ?? 'A zone').' watched again', null],
                    $type === 'zone_restored' => ['success', ($zone ?? 'A zone').' back to normal', null],
                    in_array($type, ['tamper', 'tamper_restored'], true) => [$type === 'tamper' ? 'warning' : 'success', ($type === 'tamper' ? 'Tampered with' : 'Tamper cleared').($zone ? ': '.$zone : ''), null],
                    in_array($type, ['alarm_fault', 'fault'], true) => ['warning', 'Fault reported', $zone],
                    $type === 'fault_restored' => ['success', 'Fault cleared', $zone],
                    $type === 'periodic_test' => ['success', 'Test message', 'All fine'],
                    default => [DevicePageWords::tone((string) $event->severity), DevicePageWords::sentence($type), null],
                };

                return ['key' => 'event-'.$event->id, 'at' => $event->occurred_at?->toIso8601String(), 'tone' => $tone, 'title' => $title, 'sub' => $sub];
            })
            ->values()
            ->all();
    }

    /** "From Oblivion by Ana Ruha · reason" for Oblivion's own commands, "At the panel" otherwise. */
    private function byWords(DeviceEvent $event): ?string
    {
        $uuid = data_get($event->payload, 'command_uuid');
        if (is_string($uuid) && $uuid !== '') {
            $request = DeviceCommandRequest::query()->with('requestedBy:id,name')->where('command_uuid', $uuid)->first(['id', 'requested_by_user_id', 'reason']);
            if ($request !== null) {
                return 'From Oblivion by '.($request->requestedBy?->name ?? 'a staff member').' · '.Str::limit((string) $request->reason, 80);
            }

            return 'From Oblivion';
        }

        return $event->source === 'sia_dc09' ? 'At the panel' : null;
    }

    /**
     * For IT: how the panel reports and whether Oblivion can arm it.
     *
     * @param  Collection<int, DeviceEvent>  $events
     * @param  array<string, mixed>|null  $action
     * @return list<array{0: string, 1: array{text: string, at: ?string}}>
     */
    private function forIt(Collection $events, bool $controllable, ?array $action, ?string $limitations, ?string $brand): array
    {
        $rows = [];
        $text = fn (string $words, ?string $at = null): array => ['text' => $words, 'at' => $at];
        if ($events->contains(fn (DeviceEvent $event): bool => $event->source === 'sia_dc09')) {
            $rows[] = ['Reports by', $text('SIA DC-09 to Oblivion’s alarm receiver')];
        }
        if ($controllable) {
            $rows[] = ['Connects by', $text(($brand ?? 'The maker').'’s own interface, built from the maker’s documents')];
        }
        if ($action !== null && $action['via'] !== null) {
            $rows[] = ['Reached through', $text($action['via'])];
        }
        $test = $events->first(fn (DeviceEvent $event): bool => in_array(strtolower((string) $event->event_type), ['heartbeat', 'periodic_test'], true));
        if ($test !== null) {
            $rows[] = ['Test message', $text('Last one {time}', $test->occurred_at?->toIso8601String())];
        }
        $rows[] = ['Arm and disarm from Oblivion', $text($controllable
            ? 'Yes, by a person with a confirm; automations never disarm'
            : 'Not available'.($limitations !== null ? ' · '.rtrim($limitations, '.') : ''))];

        return $rows;
    }

    /** @return list<array{text: string, at: ?string}> "Model · Site · where · last report …"; times are filled in on the page. */
    private function subline(Device $device, ?DeviceEntry $entry, ?string $siteName, bool $controllable, ?CarbonInterface $lastReport): array
    {
        $parts = [['text' => $entry?->model() ?? DeviceKind::AlarmPanel->label(), 'at' => null]];
        if ($siteName !== null) {
            $room = trim((string) $device->location_description);
            $parts[] = ['text' => $room !== '' ? $siteName.' · '.$room : $siteName, 'at' => null];
        }
        if ($controllable) {
            $parts[] = ['text' => 'armed and disarmed from Oblivion', 'at' => null];
        }
        if ($lastReport !== null) {
            $parts[] = ['text' => 'last report {ago}', 'at' => $lastReport->toIso8601String()];
        }

        return $parts;
    }

    /** @return array<string, string> id => name, from the panel's saved settings (a map or a list of {id, name}). */
    private function configuredNames(Device $device, string $key): array
    {
        $raw = data_get($device->config ?? [], $key, []);
        if (! is_array($raw)) {
            return [];
        }
        $names = [];
        foreach ($raw as $index => $value) {
            $id = is_array($value) ? $this->identifier($value['id'] ?? null) : $this->identifier($index);
            $name = is_array($value) ? ($value['name'] ?? null) : $value;
            if ($id !== null && is_string($name) && trim($name) !== '') {
                $names[$id] = mb_substr(trim($name), 0, 48);
            }
        }

        return $names;
    }

    private function areaOf(DeviceEvent $event): ?string
    {
        $payload = is_array($event->payload) ? $event->payload : [];

        return $this->identifier($payload['area'] ?? $payload['partition'] ?? null);
    }

    private function identifier(mixed $value): ?string
    {
        if (is_int($value) && $value >= 0) {
            return (string) $value;
        }
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value !== '' && preg_match('/^[A-Za-z0-9._:-]{1,80}$/', $value) === 1 ? $value : null;
    }
}
