<?php

namespace App\Domain\SecurityDevices\Screens\Devices\Pages;

use App\Domain\SecurityDevices\Alerts\DeviceAlertEngine;
use App\Domain\SecurityDevices\Alerts\Models\DeviceAlert;
use App\Domain\SecurityDevices\Iot\Mqtt\MqttSensorIntakeService;
use App\Domain\SecurityDevices\Iot\Readings\SensorReadingHistory;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Screens\DeviceKind;
use App\Domain\SecurityDevices\Screens\DeviceState;
use App\Domain\SecurityDevices\Screens\Devices\DeviceDirectory;
use App\Domain\SecurityDevices\Screens\Devices\DeviceEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * The sensor's page (S&D rebuild Phase E, drawing pg-sdr-7): Readings · Alerts. The latest readings
 * with their state, each reading over 24 hours, 7 days or 30 days from the readings history against
 * the limits that open an alert, the battery with its forecast, the sensor's alerts, where it is and
 * the gateway it reports through.
 *
 * Not here, on purpose: the History and Settings tabs (not drawn), and everything Phase F (a person
 * or care plan linked to the sensor, automations; drawings 7b and 7c). Everything is scoped by
 * SecurityDevicesAccessService before it gets here; a number with no data behind it is null, which
 * the page shows as "Not available".
 */
final class SensorPagePresenter
{
    public const array TAB_LABELS = [
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

        $tabs = array_values(array_filter(['readings', $canSeeAlerts ? 'alerts' : null]));
        $tab = in_array($input['tab'] ?? null, $tabs, true) ? (string) $input['tab'] : 'readings';

        $readings = $this->readings($device);
        $gateway = $this->gateway($viewer, $device);
        $lastReadingAt = $this->lastReadingAt($device, $readings);
        $battery = $device->battery_level === null ? null : (int) $device->battery_level;
        $daysLeft = $battery === null ? null : $this->history->batteryDaysLeft((int) $device->id);

        return [
            'device' => [
                'id' => (int) $device->id,
                'name' => (string) $device->name,
                'kind' => DeviceKind::Sensor->value,
                'kind_label' => DeviceKind::Sensor->label(),
                'group' => 'sensor',
                'state' => $state->value,
                'badge' => ['label' => $state->label(), 'tone' => $state->tone()],
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
            'meters' => $this->meters($readings, $battery, $daysLeft, $device),
            'can' => [
                'edit' => $viewer->canDo('securityDevices.devices.update') && ! $retired,
                'watch' => $viewer->canDo('securityDevices.monitoring.manage'),
                'move' => $viewer->canDo('securityDevices.devices.assign'),
                'assign' => $viewer->canDo('securityDevices.devices.assign'),
                'retire' => $viewer->canDo('securityDevices.devices.delete') && ! $retired,
                'upload' => false,
                'see_events' => $canSeeAlerts,
            ],
            'readings' => $tab === 'readings' ? [
                'series' => $this->series($device, $readings, $range, $now),
                'rules' => $this->rules($readings, $battery !== null, $siteName),
                'where' => $this->where($device, $entry, $siteName, $gateway, $readings, $lastReadingAt),
            ] : null,
            'alerts' => $tab === 'alerts' ? $this->alerts($device, $now) : null,
            'care' => $this->care($viewer, $device, $readings, $retired),
        ];
    }

    /**
     * S&D Phase F (7b, 7c, pop-up 65): who this sensor is linked to and their plan's rule, whether
     * the viewer may link or unlink it, and the hot-water scald watch for temperature probes.
     *
     * @param  array<string, array{value: float, state: string, at: ?string}>  $readings
     * @return array<string, mixed>
     */
    private function care(User $viewer, Device $device, array $readings, bool $retired): array
    {
        $link = app(\App\Domain\SecurityDevices\Care\DevicePersonLinks::class)->currentFor($device);
        $name = $link?->client ? \App\Domain\SecurityDevices\Care\DevicePersonLinks::name($link->client) : null;
        $rule = (array) ($link?->rule ?? []);
        $purpose = [
            'falls' => 'falls plan', 'wandering' => 'wandering plan', 'room' => 'room readings', 'other' => 'care',
        ][$link?->purpose ?? 'other'] ?? 'care';
        $canSeePerson = $link?->client !== null && $viewer->can('view', $link->client);

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
            'scald' => array_key_exists('temperature', $readings) || \App\Domain\SecurityDevices\Care\ScaldWatch::watches($device) ? [
                'on' => \App\Domain\SecurityDevices\Care\ScaldWatch::watches($device),
                'can_change' => $viewer->canDo('securityDevices.devices.update') && ! $retired,
            ] : null,
        ];
    }

    /**
     * The latest numeric readings the drawing knows, in its order, with their state.
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
            $out[$key] = [
                'value' => (float) $reading['value'],
                'state' => in_array($reading['state'] ?? null, ['warning', 'critical'], true) ? (string) $reading['state'] : 'healthy',
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
            $meters[] = self::meter($key, $label, self::valueWords($key, $reading['value']), ['text' => self::stateWords($key, $reading['value'], $reading['state']), 'at' => null], match ($reading['state']) {
                'critical' => 'critical',
                'warning' => 'warning',
                default => 'success',
            });
        }

        $low = $battery !== null && $battery <= DeviceEntry::LOW_BATTERY;
        $meters[] = self::meter('battery', 'Battery', $battery === null ? null : $battery.'%', match (true) {
            $battery === null => ['text' => 'Not sent by this sensor', 'at' => null],
            $daysLeft !== null => ['text' => self::daysLeftWords($daysLeft), 'at' => null],
            default => ['text' => 'Time left: not available yet', 'at' => null],
        }, $battery === null ? 'brand' : ($low ? 'warning' : 'success'));

        return $meters;
    }

    /**
     * @param  array{text: string, at: ?string}  $caption
     * @return array<string, mixed>
     */
    private static function meter(string $key, string $label, ?string $value, array $caption, string $tone): array
    {
        return ['key' => $key, 'label' => $label, 'value' => $value, 'from' => null, 'ago' => null, 'caption' => $caption, 'tone' => $tone, 'tab' => 'readings', 'bar' => null];
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

        $out = [];
        foreach (array_keys($readings) as $key) {
            [$label, $unit] = self::METRICS[$key];
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
                'limits' => self::limitLines($key),
            ];
        }

        return $out;
    }

    /** @return list<array{value: float, label: string}> the lines where this reading opens an alert */
    private static function limitLines(string $key): array
    {
        $limits = MqttSensorIntakeService::READING_LIMITS[$key] ?? [];
        $lines = [];
        if (isset($limits['below'])) {
            $lines[] = ['value' => (float) $limits['below'], 'label' => 'Alert below '.self::valueWords($key, (float) $limits['below'])];
        }
        if (isset($limits['above'])) {
            $lines[] = ['value' => (float) $limits['above'], 'label' => 'Alert above '.self::valueWords($key, (float) $limits['above'])];
        }
        if (isset($limits['warning_at'])) {
            $lines[] = ['value' => (float) $limits['warning_at'], 'label' => 'Alert at '.self::valueWords($key, (float) $limits['warning_at'])];
        }
        if (isset($limits['critical_at'])) {
            $lines[] = ['value' => (float) $limits['critical_at'], 'label' => 'Urgent at '.self::valueWords($key, (float) $limits['critical_at'])];
        }

        return $lines;
    }

    /**
     * The limits that open an alert for this sensor and who hears (DeviceAlertEngine::onReading and
     * onBattery: staff on shift at its house, in the app). Built in, so always on.
     *
     * @param  array<string, array{value: float, state: string, at: ?string}>  $readings
     * @return list<array{label: string, hint: string}>
     */
    private function rules(array $readings, bool $hasBattery, ?string $siteName): array
    {
        $who = $siteName !== null ? 'Staff on shift at '.$siteName.' · in the app' : 'Staff on shift at its Site · in the app';
        $rows = [];
        foreach (array_keys($readings) as $key) {
            $limits = MqttSensorIntakeService::READING_LIMITS[$key] ?? null;
            if ($limits === null) {
                continue;
            }
            [$label] = self::METRICS[$key];
            $words = match (true) {
                isset($limits['below'], $limits['above']) => 'below '.self::valueWords($key, (float) $limits['below']).' or above '.self::valueWords($key, (float) $limits['above']),
                isset($limits['warning_at']) => 'at '.self::valueWords($key, (float) $limits['warning_at']).' or more',
                default => null,
            };
            if ($words !== null) {
                $rows[] = ['label' => $label.' '.$words, 'hint' => $who];
            }
        }
        if ($hasBattery) {
            $rows[] = ['label' => 'Battery under '.DeviceAlertEngine::BATTERY_LOW.'%', 'hint' => $who];
        }

        return $rows;
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
     *
     * @return list<array<string, mixed>>
     */
    private function alerts(Device $device, CarbonImmutable $now): array
    {
        return DeviceAlert::query()
            ->where('device_id', $device->id)
            ->where('opened_at', '>=', $now->subDays(90))
            ->latest('opened_at')
            ->latest('id')
            ->limit(25)
            ->get(['id', 'title', 'severity', 'state', 'opened_at', 'closed_at', 'acknowledged_at'])
            ->map(fn (DeviceAlert $alert): array => [
                'key' => 'alert-'.$alert->id,
                'at' => $alert->opened_at?->toIso8601String(),
                'tone' => $alert->isActive() ? ($alert->severity === 'critical' ? 'critical' : 'warning') : 'success',
                'title' => (string) $alert->title,
                'sub' => match (true) {
                    $alert->state === DeviceAlert::STATE_SNOOZED => 'Snoozed',
                    $alert->isActive() && $alert->acknowledged_at !== null => 'Still open · acknowledged',
                    $alert->isActive() => 'Still open',
                    default => 'Lasted '.DeviceAlertEngine::duration($alert->opened_at, $alert->closed_at ?? $now),
                },
                'href' => '/security-devices/alerts?alert='.$alert->id,
            ])
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

    private static function stateWords(string $key, float $value, string $state): string
    {
        if ($state === 'healthy') {
            return 'In the usual range';
        }
        $limits = MqttSensorIntakeService::READING_LIMITS[$key] ?? [];
        $low = isset($limits['below']) && $value < $limits['below'];

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
