<?php

namespace App\Domain\SecurityDevices\Screens\Devices\Pages;

use App\Domain\SecurityDevices\Alerts\DeviceAlertEngine;
use App\Domain\SecurityDevices\Alerts\Models\DeviceAlert;
use App\Domain\SecurityDevices\Care\CareRules;
use App\Domain\SecurityDevices\Care\DevicePersonLinks;
use App\Domain\SecurityDevices\Care\Models\DevicePersonLink;
use App\Domain\SecurityDevices\Care\ScaldWatch;
use App\Domain\SecurityDevices\Iot\Readings\SensorAlertRules;
use App\Domain\SecurityDevices\Iot\Readings\SensorReadingHistory;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Screens\DeviceKind;
use App\Domain\SecurityDevices\Screens\DeviceState;
use App\Domain\SecurityDevices\Screens\Devices\DeviceDirectory;
use App\Domain\SecurityDevices\Screens\Devices\DeviceEntry;
use App\Domain\SecurityDevices\Screens\Sites\AlertWords;
use App\Domain\SecurityDevices\Screens\Sites\SiteStream;
use App\Models\SiteHazard;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * The sensor's page (S&D rebuild Phase E + E6, drawings pg-sdr-7, 7b, 7c): Nights · Readings · Alerts.
 *
 * - Standard sensors (7): latest readings with per-sensor configurable alert limits, 24h/7d/30d trends,
 *   battery forecast, where it is and the gateway it reports through.
 * - Bed sensors linked to a falls plan (7b): Nights view with last night's log, 7-night out-of-bed
 *   minutes, 4-check readiness ring, and privacy-gated resident details. Shown only to viewers who
 *   may open the linked resident's record while their consent to monitoring sensors is active.
 * - Hot-water scald probes (7c): At the tap now, Hottest today, H&S hazard and Battery meters, plus
 *   the open scald-risk hazard banner.
 */
final class SensorPagePresenter
{
    public const array TAB_LABELS = [
        'nights' => 'Nights',
        'readings' => 'Readings',
        'alerts' => 'Alerts',
    ];

    /** The readings the page draws, in the drawing's order: key => [label, unit on screen]. */
    public const array METRICS = [
        'temperature' => ['Temperature', '°C'],
        'humidity' => ['Humidity', '%'],
        'co2' => ['CO₂', 'ppm'],
        'tvoc' => ['TVOC', 'ppb'],
        'pm25' => ['PM2.5', 'µg/m³'],
        'pressure' => ['Air pressure', 'hPa'],
        'illuminance' => ['Light', 'lx'],
        'people_count' => ['People', 'people'],
    ];

    /** range => [seconds, points on the graph]: every 15 minutes, every 6 hours, every day. */
    public const array RANGES = [
        '24h' => [86_400, 96],
        '7d' => [604_800, 28],
        '30d' => [2_592_000, 30],
    ];

    public function __construct(
        private readonly DeviceDirectory $directory,
        private readonly SensorReadingHistory $history,
        private readonly DevicePersonLinks $links,
        private readonly SensorNightsPresenter $nights,
    ) {}

    public static function serves(Device $device): bool
    {
        return DeviceKind::of($device) === DeviceKind::Sensor;
    }

    /**
     * @param  array{tab?: mixed, range?: mixed}  $input
     * @return array<string, mixed>
     */
    public function present(User $viewer, Device $device, array $input): array
    {
        $now = CarbonImmutable::now();
        $entry = $this->directory->entries($viewer, null, [(int) $device->id])->first();
        $state = $entry?->state ?? DeviceState::Retired;
        $retired = $state === DeviceState::Retired;
        $siteName = $entry?->siteName;
        $canSeeAlerts = $viewer->canDo('securityDevices.events.view');
        $range = is_string($input['range'] ?? null) && array_key_exists($input['range'], self::RANGES) ? $input['range'] : '24h';

        $link = $this->links->currentFor($device);
        // In or out of bed is about one resident: the Nights tab and the bed meters only for viewers
        // who may open their record, while their consent to monitoring sensors is active. Anyone
        // else sees a bed sensor as an ordinary sensor.
        $showNights = SensorNightsPresenter::isBedSensor($device, $link) && $this->nights->mayShow($viewer, $link);
        $isScaldProbe = ScaldWatch::watches($device);

        $tabs = array_values(array_filter([
            $showNights ? 'nights' : null,
            'readings',
            $canSeeAlerts ? 'alerts' : null,
        ]));
        $defaultTab = $tabs[0];
        $tab = in_array($input['tab'] ?? null, $tabs, true) ? (string) $input['tab'] : $defaultTab;

        $readings = $this->readings($device);
        $gateway = $this->gateway($viewer, $device);
        $lastReadingAt = $this->lastReadingAt($device, $readings);
        $battery = $device->battery_level === null ? null : (int) $device->battery_level;
        $daysLeft = $battery === null ? null : $this->history->batteryDaysLeft((int) $device->id);

        $tooHot = ! $retired && $isScaldProbe && isset($readings['temperature']) && $readings['temperature']['value'] > ScaldWatch::LIMIT_C;
        $badge = $tooHot
            ? ['label' => 'Too hot', 'tone' => 'critical']
            : ['label' => $state->label(), 'tone' => $state->tone()];

        // Loaded once for both the meters and the Nights tab.
        $nightHistory = $showNights ? $this->nights->history($device, $canSeeAlerts, $now) : null;

        $meters = match (true) {
            $nightHistory !== null => $this->nights->meters($device, $link, $nightHistory, $canSeeAlerts, $now),
            $isScaldProbe => $this->scaldMeters($device, $readings, $battery, $daysLeft, $canSeeAlerts, $now),
            default => $this->meters($readings, $battery, $daysLeft, $device),
        };

        $canManageRules = SensorAlertRules::canManage($viewer, $retired);

        return [
            'device' => [
                'id' => (int) $device->id,
                'name' => (string) $device->name,
                'kind' => DeviceKind::Sensor->value,
                'kind_label' => DeviceKind::Sensor->label(),
                'group' => 'sensor',
                'state' => $state->value,
                'badge' => $badge,
                'model' => $entry?->model() ?? trim(trim((string) $device->manufacturer).' '.trim((string) $device->model)),
                'site' => $siteName,
                'site_id' => $entry?->siteId,
                'address' => $device->ip_address ? (string) $device->ip_address : null,
                'watching' => $entry === null || $state === DeviceState::NotWatched ? 'Nothing' : Str::ucfirst(implode(', ', $entry->watching)),
                'subline' => $this->subline($device, $entry, $siteName, $gateway, $lastReadingAt),
                'back' => ['href' => '/security-devices/devices?tab=sensors', 'label' => 'Devices'],
            ],
            'tab' => $tab,
            'tabs' => array_map(fn (string $key): array => ['key' => $key, 'label' => self::TAB_LABELS[$key]], $tabs),
            'range' => $range,
            'meters' => $meters,
            'can' => [
                'edit' => $viewer->canDo('securityDevices.devices.update') && ! $retired,
                'watch' => $viewer->canDo('securityDevices.monitoring.manage'),
                'move' => $viewer->canDo('securityDevices.devices.assign'),
                'assign' => $viewer->canDo('securityDevices.devices.assign'),
                'retire' => $viewer->canDo('securityDevices.devices.delete') && ! $retired,
                'upload' => false,
                'see_events' => $canSeeAlerts,
                'manage_rules' => $canManageRules,
            ],
            'nights' => $tab === 'nights' && $nightHistory !== null
                ? $this->nights->present($viewer, $device, $link, $nightHistory, $entry?->siteId, $siteName, $gateway, $lastReadingAt, $canSeeAlerts, $retired, $now)
                : null,
            'readings' => $tab === 'readings' ? [
                'series' => $this->series($device, $readings, $range, $now),
                'rules' => SensorAlertRules::ruleRows($device, $readings, $battery !== null, $siteName),
                // Who changed this sensor's limits lately, and what (audit fix, 5 Nov).
                'rules_history' => $canSeeAlerts ? SensorAlertRules::history($device) : [],
                'editable_rules' => $canManageRules ? SensorAlertRules::editableRules($device, $readings, $battery !== null) : null,
                'where' => $this->where($device, $entry, $siteName, $gateway, $readings, $lastReadingAt),
            ] : null,
            'alerts' => $tab === 'alerts' ? $this->alerts($viewer, $device, $now) : null,
            'care' => $this->care($viewer, $device, $link, $readings, $canSeeAlerts, $retired),
        ];
    }

    /**
     * S&D Phase F + E6 (7b, 7c, pop-up 65): who this sensor is linked to and their plan's rule,
     * whether the viewer may link or unlink it, and the hot-water scald watch + active hazard state.
     *
     * @param  array<string, array{value: float, state: string, at: ?string}>  $readings
     * @return array<string, mixed>
     */
    private function care(
        User $viewer,
        Device $device,
        ?DevicePersonLink $link,
        array $readings,
        bool $canSeeAlerts,
        bool $retired,
    ): array {
        $name = $link?->client ? DevicePersonLinks::name($link->client) : null;
        $rule = (array) ($link?->rule ?? []);
        $purpose = [
            'falls' => 'falls plan', 'wandering' => 'wandering plan', 'room' => 'room readings', 'other' => 'care',
        ][$link?->purpose ?? 'other'] ?? 'care';
        $canSeePerson = $link?->client !== null && Gate::forUser($viewer)->allows('view', $link->client);

        $watchesScald = ScaldWatch::watches($device);
        $scaldAlertPayload = null;
        if ($watchesScald && $canSeeAlerts) {
            $activeScald = DeviceAlert::query()
                ->active()
                ->where('device_id', $device->id)
                ->where('kind', 'scald_risk')
                ->latest('id')
                ->first();
            if ($activeScald instanceof DeviceAlert) {
                $hazard = $activeScald->hazard_id !== null ? SiteHazard::query()->find($activeScald->hazard_id) : null;
                $scaldAlertPayload = [
                    'id' => (int) $activeScald->id,
                    'title' => (string) $activeScald->title,
                    'opened_at' => $activeScald->opened_at?->toIso8601String(),
                    'hazard_raised' => $hazard instanceof SiteHazard,
                    'hazard_url' => $hazard instanceof SiteHazard ? '/hazards/'.$hazard->id : null,
                    'can_raise_hazard' => ! ($hazard instanceof SiteHazard) && $viewer->canDo('hazards.create'),
                    'alert_href' => '/security-devices/alerts?alert='.$activeScald->id,
                ];
            }
        }

        return [
            'link' => $link === null ? null : [
                'title' => 'Linked to '.($canSeePerson ? $name.'’s ' : 'a person’s ').$purpose,
                'rule' => match ($link->purpose) {
                    'falls' => 'Alert night staff if '.($canSeePerson ? $name : 'they are').' out of bed for over '.(int) ($rule['minutes'] ?? 10).' minutes between '.($rule['from'] ?? '22:00').' and '.($rule['to'] ?? '06:00'),
                    'wandering' => 'Alert staff on shift if the door opens between '.($rule['from'] ?? '22:00').' and '.($rule['to'] ?? '06:00'),
                    default => null,
                },
                'plan' => $canSeePerson && $link->carePlan ? (string) $link->carePlan->title : null,
                'person_href' => $canSeePerson ? '/clients/'.$link->client_id : null,
                'linked_at' => $link->linked_at?->toIso8601String(),
            ],
            'can_link' => $viewer->canDo('securityDevices.people.link') && ! $retired,
            'scald' => array_key_exists('temperature', $readings) || $watchesScald ? [
                'on' => $watchesScald,
                'can_change' => $viewer->canDo('securityDevices.devices.update') && ! $retired,
                'alert' => $scaldAlertPayload,
            ] : null,
        ];
    }

    /**
     * The latest numeric readings the drawing knows, in its order, with their state evaluated
     * against the sensor's stored alert limits.
     *
     * @return array<string, array{value: float, state: string, at: ?string}>
     */
    private function readings(Device $device): array
    {
        $stored = data_get($device->meta, 'observed.sensor_readings');
        $stored = is_array($stored) ? $stored : [];
        $out = [];
        foreach (array_keys(self::METRICS) as $key) {
            $reading = $stored[$key] ?? null;
            if (! is_array($reading) || ! is_numeric($reading['value'] ?? null)) {
                continue;
            }
            $value = (float) $reading['value'];
            $evaluated = SensorAlertRules::evaluate($device, $key, $value);
            $storedState = in_array($reading['state'] ?? null, ['warning', 'critical'], true) ? (string) $reading['state'] : 'healthy';
            $hasCustomRules = is_array(data_get($device->config ?? [], SensorAlertRules::CONFIG_KEY.'.metrics.'.$key));
            $out[$key] = [
                'value' => $value,
                'state' => $hasCustomRules ? $evaluated : ($storedState !== 'healthy' ? $storedState : $evaluated),
                'at' => is_string($reading['observed_at'] ?? null) ? $reading['observed_at'] : null,
            ];
        }

        return $out;
    }

    /** @param array<string, array{value: float, state: string, at: ?string}> $readings */
    private function lastReadingAt(Device $device, array $readings): ?string
    {
        $at = data_get($device->meta, 'observed.last_telemetry_at');
        if (is_string($at) && $at !== '') {
            return CarbonImmutable::parse($at)->toIso8601String();
        }
        $latest = collect($readings)->pluck('at')->filter()->max();

        return is_string($latest) ? CarbonImmutable::parse($latest)->toIso8601String() : $device->last_seen_at?->toIso8601String();
    }

    /**
     * The gateway it reports through, if the viewer may see it.
     *
     * @return array{id: int, name: string, state: string, state_label: string}|null
     */
    private function gateway(User $viewer, Device $device): ?array
    {
        $parentIds = $device->parentRelationships()->pluck('parent_device_id')->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();
        if ($parentIds === []) {
            return null;
        }
        $parents = $this->directory->entries($viewer, null, $parentIds);
        $parent = $parents->first(fn (DeviceEntry $row): bool => $row->kind === DeviceKind::SensorGateway) ?? $parents->first();

        return $parent === null ? null : [
            'id' => $parent->id(),
            'name' => $parent->name(),
            'state' => $parent->state->value,
            'state_label' => $parent->state->label(),
        ];
    }

    /**
     * Model · Site · room · through the gateway · reading N min ago.
     *
     * @param  array{id: int, name: string, state: string, state_label: string}|null  $gateway
     * @return list<array{text: string, at: ?string}>
     */
    private function subline(Device $device, ?DeviceEntry $entry, ?string $siteName, ?array $gateway, ?string $lastReadingAt): array
    {
        $parts = [['text' => $entry?->model() ?? DeviceKind::Sensor->label(), 'at' => null]];
        if ($siteName !== null) {
            $room = trim((string) $device->location_description);
            $parts[] = ['text' => $room !== '' ? $siteName.' · '.$room : $siteName, 'at' => null];
        }
        if ($gateway !== null) {
            $parts[] = ['text' => 'through '.$gateway['name'], 'at' => null];
        }
        $parts[] = $lastReadingAt !== null
            ? ['text' => 'reading {ago}', 'at' => $lastReadingAt]
            : ['text' => 'no reading yet', 'at' => null];

        return $parts;
    }

    /**
     * Up to three readings, then the battery. Each opens the Readings tab.
     *
     * @param  array<string, array{value: float, state: string, at: ?string}>  $readings
     * @return list<array<string, mixed>>
     */
    private function meters(array $readings, ?int $battery, ?int $daysLeft, Device $device): array
    {
        $meters = [];
        foreach (array_slice($readings, 0, 3, true) as $key => $reading) {
            [$label] = self::METRICS[$key];
            $meters[] = self::meter(
                $key,
                $label,
                self::valueWords($key, $reading['value']),
                ['text' => self::stateWords($device, $key, $reading['value'], $reading['state']), 'at' => null],
                match ($reading['state']) {
                    'critical' => 'critical',
                    'warning' => 'warning',
                    default => 'success',
                },
            );
        }

        $meters[] = $this->batteryMeter($device, $battery, $daysLeft);

        return $meters;
    }

    /**
     * Drawing 7c meters for a hot-water temperature probe:
     * At the tap now · Hottest today · H&S hazard · Battery.
     *
     * @param  array<string, array{value: float, state: string, at: ?string}>  $readings
     * @return list<array<string, mixed>>
     */
    private function scaldMeters(
        Device $device,
        array $readings,
        ?int $battery,
        ?int $daysLeft,
        bool $canSeeAlerts,
        CarbonImmutable $now,
    ): array {
        $temp = $readings['temperature']['value'] ?? null;
        $activeScald = DeviceAlert::query()
            ->active()
            ->where('device_id', $device->id)
            ->where('kind', 'scald_risk')
            ->latest('id')
            ->first();

        // On a hot-water tap the tone follows the 45 °C scald rule only, not the room-temperature
        // limits (normal hot water is well above those).
        $tapNowTone = match (true) {
            $temp === null => 'brand',
            $temp > ScaldWatch::LIMIT_C => 'critical',
            $activeScald !== null => 'warning',
            default => 'success',
        };
        $tapNowCaption = match (true) {
            $temp === null => ['text' => 'No reading yet', 'at' => null],
            $activeScald?->opened_at !== null && $temp > ScaldWatch::LIMIT_C => [
                'text' => 'Over 45 °C since {time}',
                'at' => $activeScald->opened_at->toIso8601String(),
            ],
            $temp > ScaldWatch::LIMIT_C => ['text' => 'Over 45 °C · scald risk', 'at' => null],
            $activeScald !== null => ['text' => 'Back under 45 °C · watching for 24 hours', 'at' => null],
            default => ['text' => 'At or under 45 °C · safe', 'at' => null],
        };

        // Hottest today: today's readings only (the NZ day). An older reading is never "today".
        $todayStartUtc = $now->setTimezone(CareRules::ZONE)->startOfDay()->utc();
        $hottestRow = DB::table('security_device_sensor_readings')
            ->where('device_id', $device->id)
            ->where('metric', 'temperature')
            ->where('observed_at', '>=', $todayStartUtc->format('Y-m-d H:i:s'))
            ->orderByDesc('value')
            ->orderByDesc('observed_at')
            ->first(['value', 'observed_at']);

        $storedAt = is_string($readings['temperature']['at'] ?? null) ? CarbonImmutable::parse($readings['temperature']['at']) : null;
        $storedIsToday = $temp !== null && $storedAt !== null && $storedAt->gte($todayStartUtc);
        [$hottestVal, $hottestAt] = match (true) {
            $hottestRow !== null => [
                (float) $hottestRow->value,
                CarbonImmutable::parse((string) $hottestRow->observed_at, 'UTC')->toIso8601String(),
            ],
            $storedIsToday => [$temp, $storedAt->toIso8601String()],
            default => [null, null],
        };

        $hazard = $activeScald?->hazard_id !== null ? SiteHazard::query()->find($activeScald->hazard_id) : null;
        if ($hazard === null) {
            $recentWithHazard = DeviceAlert::query()
                ->where('device_id', $device->id)
                ->where('kind', 'scald_risk')
                ->whereNotNull('hazard_id')
                ->latest('id')
                ->first();
            $candidate = $recentWithHazard?->hazard_id !== null ? SiteHazard::query()->find($recentWithHazard->hazard_id) : null;
            if ($candidate instanceof SiteHazard && in_array($candidate->status, ['open', 'in_progress'], true)) {
                $hazard = $candidate;
            }
        }

        $hazardMeter = match (true) {
            $hazard instanceof SiteHazard => self::meter(
                'hazard',
                'H&S hazard',
                'Raised',
                [
                    'text' => 'Scald risk · {time}',
                    'at' => ($hazard->created_at ? CarbonImmutable::instance($hazard->created_at) : $activeScald?->opened_at)?->toIso8601String(),
                ],
                'critical',
                $canSeeAlerts ? 'alerts' : 'readings',
            ),
            $activeScald instanceof DeviceAlert => self::meter(
                'hazard',
                'H&S hazard',
                'Ready to raise',
                ['text' => 'Scald risk · {time}', 'at' => $activeScald->opened_at?->toIso8601String()],
                'critical',
                $canSeeAlerts ? 'alerts' : 'readings',
            ),
            default => self::meter(
                'hazard',
                'H&S hazard',
                'None',
                ['text' => match (true) {
                    $temp === null => 'No reading yet',
                    $temp > ScaldWatch::LIMIT_C => 'Waiting for three hot readings in a row',
                    default => 'Tap at or under 45 °C',
                }, 'at' => null],
                $temp !== null && $temp <= ScaldWatch::LIMIT_C ? 'success' : 'brand',
            ),
        };

        return [
            self::meter(
                'tap_now',
                'At the tap now',
                $temp !== null ? self::valueWords('temperature', $temp) : null,
                $tapNowCaption,
                $tapNowTone,
            ),
            self::meter(
                'hottest_today',
                'Hottest today',
                $hottestVal !== null ? self::valueWords('temperature', $hottestVal) : null,
                $hottestVal !== null && $hottestAt !== null
                    ? ['text' => 'At {time}', 'at' => $hottestAt]
                    : ['text' => 'No reading today', 'at' => null],
                $hottestVal !== null && $hottestVal > ScaldWatch::LIMIT_C ? 'warning' : 'brand',
            ),
            $hazardMeter,
            $this->batteryMeter($device, $battery, $daysLeft),
        ];
    }

    /** @return array<string, mixed> */
    private function batteryMeter(Device $device, ?int $battery, ?int $daysLeft): array
    {
        $batteryRule = SensorAlertRules::batteryRule($device);
        $threshold = $batteryRule['enabled'] ? $batteryRule['below'] : DeviceEntry::LOW_BATTERY;
        $low = $battery !== null && $battery <= $threshold;

        return self::meter(
            'battery',
            'Battery',
            $battery === null ? null : $battery.'%',
            match (true) {
                $battery === null => ['text' => 'Not sent by this sensor', 'at' => null],
                $daysLeft !== null => ['text' => self::daysLeftWords($daysLeft), 'at' => null],
                default => ['text' => 'Time left: not available yet', 'at' => null],
            },
            $battery === null ? 'brand' : ($low ? 'warning' : 'success'),
        );
    }

    /**
     * @param  array{text: string, at: ?string}  $caption
     * @return array<string, mixed>
     */
    private static function meter(string $key, string $label, ?string $value, array $caption, string $tone, string $tab = 'readings'): array
    {
        return ['key' => $key, 'label' => $label, 'value' => $value, 'from' => null, 'ago' => null, 'caption' => $caption, 'tone' => $tone, 'tab' => $tab, 'bar' => null];
    }

    /**
     * Each reading over the chosen range, thinned to the graph's points, with its alert lines.
     *
     * @param  array<string, array{value: float, state: string, at: ?string}>  $readings
     * @return list<array<string, mixed>>
     */
    private function series(Device $device, array $readings, string $range, CarbonImmutable $now): array
    {
        [$span, $points] = self::RANGES[$range];
        $to = $now->utc();
        $from = $to->subSeconds($span);
        $step = intdiv($span, $points);
        $isScaldProbe = ScaldWatch::watches($device);

        $out = [];
        foreach (array_keys($readings) as $key) {
            [$label, $unit] = self::METRICS[$key];
            if ($key === 'temperature' && $isScaldProbe) {
                $label = 'Water temperature at the tap';
            }
            $values = array_fill(0, $points, null);
            foreach ($this->history->series((int) $device->id, $key, $from, $to, $points) as $row) {
                $index = intdiv(CarbonImmutable::parse($row['at'])->getTimestamp() - $from->getTimestamp(), $step);
                $values[max(0, min($points - 1, $index))] = $row['value'];
            }
            $out[] = [
                'key' => $key,
                'title' => $label,
                'unit' => $unit,
                'start' => $from->addSeconds(intdiv($step, 2))->toIso8601String(),
                'step_seconds' => $step,
                'points' => $values,
                'limits' => SensorAlertRules::limitLines($device, $key),
            ];
        }

        return $out;
    }

    /**
     * Where it is and what it's linked to. Times are filled in on the page.
     *
     * @param  array{id: int, name: string, state: string, state_label: string}|null  $gateway
     * @param  array<string, array{value: float, state: string, at: ?string}>  $readings
     * @return array{rows: list<array{label: string, text: string, at: ?string}>, gateway_href: ?string}
     */
    private function where(Device $device, ?DeviceEntry $entry, ?string $siteName, ?array $gateway, array $readings, ?string $lastReadingAt): array
    {
        $stored = data_get($device->meta, 'observed.sensor_readings');
        $rssi = is_numeric(data_get($stored, 'rssi.value')) ? (float) data_get($stored, 'rssi.value') : null;
        $snr = is_numeric(data_get($stored, 'snr.value')) ? (float) data_get($stored, 'snr.value') : null;
        $room = trim((string) $device->location_description);
        $protocol = strtolower((string) data_get($device->meta, 'observed.last_telemetry_protocol', ''));

        $rows = [
            ['label' => 'Site', 'text' => $siteName ?? 'Not set', 'at' => null],
            ['label' => 'Room', 'text' => $room !== '' ? $room : '—', 'at' => null],
            ['label' => 'Gateway', 'text' => $gateway !== null ? $gateway['name'].' · '.Str::lower($gateway['state_label']) : 'Not linked to a gateway', 'at' => null],
        ];
        if ($rssi !== null || $snr !== null) {
            $rows[] = ['label' => 'Signal', 'text' => implode(' · ', array_filter([
                $rssi !== null ? self::number($rssi).' dBm' : null,
                $snr !== null ? 'SNR '.self::number($snr).' dB' : null,
            ])), 'at' => null];
        }
        if ($protocol !== '') {
            $rows[] = ['label' => 'Sends by', 'text' => match ($protocol) {
                'mqtt' => 'MQTT, through the Site’s hub',
                'milesight', 'lorawan' => 'LoRaWAN, through its gateway',
                default => 'A connected service',
            }, 'at' => null];
        }
        $rows[] = ['label' => 'Last reading', 'text' => $lastReadingAt !== null ? '{time}' : 'None yet', 'at' => $lastReadingAt];

        return ['rows' => $rows, 'gateway_href' => $gateway !== null ? '/security-devices/devices/'.$gateway['id'] : null];
    }

    /**
     * Its alerts in the last 90 days, newest first; each opens in Alerts.
     * Care-rule alert titles hide the resident's name unless the viewer may open their record.
     *
     * @return list<array<string, mixed>>
     */
    private function alerts(User $viewer, Device $device, CarbonImmutable $now): array
    {
        $alerts = DeviceAlert::query()
            ->where('device_id', $device->id)
            ->where('opened_at', '>=', $now->subDays(90))
            ->latest('opened_at')
            ->latest('id')
            ->limit(25)
            ->get(['id', 'device_id', 'kind', 'title', 'severity', 'state', 'opened_at', 'closed_at', 'acknowledged_at']);

        $people = SiteStream::carePeople($viewer, $alerts);

        return $alerts
            ->map(function (DeviceAlert $alert) use ($people, $now): array {
                $words = new AlertWords($alert, $people[(int) $alert->id] ?? false);

                return [
                    'key' => 'alert-'.$alert->id,
                    'at' => $alert->opened_at?->toIso8601String(),
                    'tone' => $alert->isActive() ? ($alert->severity === 'critical' ? 'critical' : 'warning') : 'success',
                    'title' => $words->title(),
                    'sub' => match (true) {
                        $alert->state === DeviceAlert::STATE_SNOOZED => 'Snoozed',
                        $alert->isActive() && $alert->acknowledged_at !== null => 'Still open · acknowledged',
                        $alert->isActive() => 'Still open',
                        default => 'Lasted '.DeviceAlertEngine::duration($alert->opened_at, $alert->closed_at ?? $now),
                    },
                    'href' => '/security-devices/alerts?alert='.$alert->id,
                ];
            })
            ->values()
            ->all();
    }

    private static function valueWords(string $key, float $value): string
    {
        $unit = self::METRICS[$key][1] ?? '';
        $number = $key === 'co2' || abs($value) >= 1000 ? number_format($value, 0, '.', ',') : self::number($value);

        return match ($unit) {
            '%' => $number.'%',
            '' => $number,
            default => $number.' '.$unit,
        };
    }

    private static function stateWords(Device $device, string $key, float $value, string $state): string
    {
        if ($state === 'healthy') {
            return 'In the usual range';
        }
        $low = SensorAlertRules::isBelowLimit($device, $key, $value);

        return match (true) {
            $state === 'critical' => 'Well above the usual range',
            $low => 'Below the usual range',
            default => 'Above the usual range',
        };
    }

    private static function daysLeftWords(int $days): string
    {
        return match (true) {
            $days < 1 => 'Less than a day left',
            $days < 14 => 'About '.$days.' '.($days === 1 ? 'day' : 'days').' left',
            $days < 60 => 'About '.(int) round($days / 7).' weeks left',
            $days < 730 => 'About '.(int) round($days / 30).' months left',
            default => 'About '.(int) round($days / 365).' years left',
        };
    }

    private static function number(float $value): string
    {
        $text = rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');

        return str_starts_with($text, '-') ? '−'.substr($text, 1) : $text;
    }
}
