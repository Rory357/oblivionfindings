<?php

namespace App\Domain\SecurityDevices\Iot\Readings;

use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\SecurityDevices\Alerts\DeviceAlertEngine;
use App\Domain\SecurityDevices\Care\DevicePersonLinks;
use App\Domain\SecurityDevices\Care\ScaldWatch;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Screens\Devices\Pages\SensorPagePresenter;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Per-sensor alert limits (S&D E6, pg-sdr-7): replaces fixed constants with rules stored on each
 * sensor (`config.sensor_limits`), falling back to Oblivion's defaults when untouched.
 *
 * Changing a sensor's alert limits requires `securityDevices.automations.manage` at the sensor's
 * Site, re-checks the latest readings right away, and writes an audit log entry.
 */
final class SensorAlertRules
{
    public const string CONFIG_KEY = 'sensor_limits';

    /**
     * Default rules when a sensor has no stored overrides.
     *
     * @var array<string, array{enabled: bool, below?: float|null, above?: float|null, warning_at?: float|null, critical_at?: float|null}>
     */
    public const array DEFAULTS = [
        'co2' => ['enabled' => true, 'warning_at' => 1000.0, 'critical_at' => 1500.0],
        'temperature' => ['enabled' => true, 'below' => 12.0, 'above' => 32.0],
        'humidity' => ['enabled' => true, 'below' => 25.0, 'above' => 75.0],
        'tvoc' => ['enabled' => false, 'below' => null, 'above' => null],
        'pm25' => ['enabled' => false, 'below' => null, 'above' => null],
        'pressure' => ['enabled' => false, 'below' => null, 'above' => null],
        'illuminance' => ['enabled' => false, 'below' => null, 'above' => null],
        'people_count' => ['enabled' => false, 'below' => null, 'above' => null],
    ];

    public const int DEFAULT_BATTERY_BELOW = 20;

    /**
     * The values a limit may take for each reading (audit fix, 5 Nov): a limit outside what the
     * sensor can ever read would switch its alert off while the rule still says On.
     *
     * @var array<string, array{0: float, 1: float}>
     */
    public const array RANGES = [
        'temperature' => [-40.0, 80.0],
        'humidity' => [0.0, 100.0],
        'co2' => [400.0, 10_000.0],
        'tvoc' => [0.0, 60_000.0],
        'pm25' => [0.0, 1_000.0],
        'pressure' => [800.0, 1_100.0],
        'illuminance' => [0.0, 200_000.0],
        'people_count' => [0.0, 500.0],
    ];

    /** How many limit changes a sensor keeps, newest first, with who changed what. */
    public const int HISTORY_KEEP = 20;

    private const array METRIC_UNITS = [
        'temperature' => 'C',
        'humidity' => '%',
        'co2' => 'ppm',
        'tvoc' => 'ppb',
        'pm25' => 'ug/m3',
        'pressure' => 'hPa',
        'illuminance' => 'lx',
        'people_count' => 'people',
    ];

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly CanonicalDeviceSiteResolver $sites,
        private readonly DeviceAlertEngine $alerts,
        private readonly DevicePersonLinks $links,
    ) {}

    public static function canManage(User $viewer, bool $retired = false): bool
    {
        return ! $retired
            && $viewer->canDo('securityDevices.devices.view')
            && $viewer->canDo('securityDevices.automations.manage');
    }

    /**
     * @return array{enabled: bool, below: float|null, above: float|null, warning_at: float|null, critical_at: float|null}
     */
    public static function metricRule(Device $device, string $metric): array
    {
        $default = self::DEFAULTS[$metric] ?? ['enabled' => false, 'below' => null, 'above' => null];
        $stored = data_get($device->config ?? [], self::CONFIG_KEY.'.metrics.'.$metric);
        $raw = is_array($stored) ? $stored : null;

        if ($metric === 'co2') {
            $enabled = $raw !== null && array_key_exists('enabled', $raw) ? (bool) $raw['enabled'] : (bool) $default['enabled'];
            $warning = $raw !== null && (array_key_exists('warning_at', $raw) || array_key_exists('above', $raw))
                ? self::nullableFloat($raw['warning_at'] ?? $raw['above'] ?? null)
                : self::nullableFloat($default['warning_at'] ?? null);
            $critical = $raw !== null && array_key_exists('critical_at', $raw)
                ? self::nullableFloat($raw['critical_at'])
                : ($raw !== null ? null : self::nullableFloat($default['critical_at'] ?? null));

            return [
                'enabled' => $enabled,
                'below' => null,
                'above' => null,
                'warning_at' => $warning,
                'critical_at' => $critical,
            ];
        }

        $enabled = $raw !== null && array_key_exists('enabled', $raw) ? (bool) $raw['enabled'] : (bool) $default['enabled'];
        $below = $raw !== null && array_key_exists('below', $raw)
            ? self::nullableFloat($raw['below'])
            : ($raw !== null ? null : self::nullableFloat($default['below'] ?? null));
        $above = $raw !== null && array_key_exists('above', $raw)
            ? self::nullableFloat($raw['above'])
            : ($raw !== null ? null : self::nullableFloat($default['above'] ?? null));

        return [
            'enabled' => $enabled,
            'below' => $below,
            'above' => $above,
            'warning_at' => null,
            'critical_at' => null,
        ];
    }

    /**
     * @return array{enabled: bool, below: int}
     */
    public static function batteryRule(Device $device): array
    {
        $stored = data_get($device->config ?? [], self::CONFIG_KEY.'.battery');
        if (! is_array($stored)) {
            return ['enabled' => true, 'below' => self::DEFAULT_BATTERY_BELOW];
        }
        $below = is_numeric($stored['below'] ?? null) ? (int) round((float) $stored['below']) : self::DEFAULT_BATTERY_BELOW;

        return [
            'enabled' => array_key_exists('enabled', $stored) ? (bool) $stored['enabled'] : true,
            'below' => max(5, min(90, $below)),
        ];
    }

    public static function evaluate(Device $device, string $metric, float $value): string
    {
        $rule = self::metricRule($device, $metric);
        if (! $rule['enabled']) {
            return 'healthy';
        }

        return match (true) {
            $rule['critical_at'] !== null && $value >= $rule['critical_at'] => 'critical',
            $rule['warning_at'] !== null && $value >= $rule['warning_at'] => 'warning',
            $rule['below'] !== null && $value < $rule['below'] => 'warning',
            $rule['above'] !== null && $value > $rule['above'] => 'warning',
            default => 'healthy',
        };
    }

    public static function isBelowLimit(Device $device, string $metric, float $value): bool
    {
        $rule = self::metricRule($device, $metric);

        return $rule['enabled'] && $rule['below'] !== null && $value < $rule['below'];
    }

    /**
     * Chart threshold lines for one metric on a sensor.
     *
     * @return list<array{value: float, label: string}>
     */
    public static function limitLines(Device $device, string $metric): array
    {
        $rule = self::metricRule($device, $metric);
        $lines = [];
        if ($rule['enabled']) {
            if ($rule['below'] !== null) {
                $lines[] = ['value' => $rule['below'], 'label' => 'Alert below '.self::valueWords($metric, $rule['below'])];
            }
            if ($rule['above'] !== null) {
                $lines[] = ['value' => $rule['above'], 'label' => 'Alert above '.self::valueWords($metric, $rule['above'])];
            }
            if ($rule['warning_at'] !== null) {
                $lines[] = ['value' => $rule['warning_at'], 'label' => 'Alert at '.self::valueWords($metric, $rule['warning_at'])];
            }
            if ($rule['critical_at'] !== null) {
                $lines[] = ['value' => $rule['critical_at'], 'label' => 'Urgent at '.self::valueWords($metric, $rule['critical_at'])];
            }
        }
        if ($metric === 'temperature' && ScaldWatch::watches($device)) {
            $lines[] = ['value' => ScaldWatch::LIMIT_C, 'label' => 'Scald risk above 45 °C'];
        }

        return $lines;
    }

    /**
     * Rows for the sensor page's "Alert rules" card.
     *
     * @param  array<string, array{value: float, state: string, at: ?string}>  $readings
     * @return list<array{key: string, label: string, hint: string, enabled: bool}>
     */
    public static function ruleRows(Device $device, array $readings, bool $hasBattery, ?string $siteName): array
    {
        $who = $siteName !== null ? 'Staff on shift at '.$siteName.' · in the app' : 'Staff on shift at its Site · in the app';
        $storedMetrics = data_get($device->config ?? [], self::CONFIG_KEY.'.metrics');
        $storedMetrics = is_array($storedMetrics) ? $storedMetrics : [];

        $keys = array_values(array_unique([
            ...array_keys($readings),
            ...array_values(array_intersect(array_keys(SensorPagePresenter::METRICS), array_keys($storedMetrics))),
        ]));

        $rows = [];
        foreach ($keys as $key) {
            if (! isset(SensorPagePresenter::METRICS[$key])) {
                continue;
            }
            $rule = self::metricRule($device, $key);
            $hasThreshold = $rule['below'] !== null || $rule['above'] !== null || $rule['warning_at'] !== null;
            if (! $hasThreshold) {
                continue;
            }
            $rows[] = [
                'key' => $key,
                'label' => self::ruleLabel($key, $rule),
                'hint' => $who,
                'enabled' => $rule['enabled'],
            ];
        }

        $storedBattery = data_get($device->config ?? [], self::CONFIG_KEY.'.battery');
        if ($hasBattery || is_array($storedBattery)) {
            $battery = self::batteryRule($device);
            // A sensor that keeps a resident safe can't lose its battery alert; the update method refuses it.
            $locked = app(DevicePersonLinks::class)->currentFor($device) !== null || ScaldWatch::watches($device);
            $rows[] = [
                'key' => 'battery',
                'locked' => $locked,
                'label' => 'Battery under '.$battery['below'].'%',
                // Safety and care sensors tell staff on shift at once (DeviceAlertEngine::onBattery).
                'hint' => in_array((string) $device->domain, ['security', 'iot_healthcare'], true)
                    ? $who.' at once, and in the 6:00 am summary'
                    : 'Included in the 6:00 am morning summary',
                'enabled' => $battery['enabled'],
            ];
        }

        return $rows;
    }

    /**
     * Editable form state for the sensor limits pop-up.
     *
     * @param  array<string, array{value: float, state: string, at: ?string}>  $readings
     * @return array{metrics: list<array<string, mixed>>, battery: array{enabled: bool, below: int}|null}
     */
    public static function editableRules(Device $device, array $readings, bool $hasBattery): array
    {
        $storedMetrics = data_get($device->config ?? [], self::CONFIG_KEY.'.metrics');
        $storedMetrics = is_array($storedMetrics) ? $storedMetrics : [];

        $keys = array_values(array_unique([
            ...array_keys($readings),
            ...array_values(array_intersect(array_keys(SensorPagePresenter::METRICS), array_keys($storedMetrics))),
        ]));
        if ($keys === []) {
            $keys = ['temperature', 'humidity', 'co2'];
        }

        $metrics = [];
        foreach ($keys as $key) {
            if (! isset(SensorPagePresenter::METRICS[$key])) {
                continue;
            }
            [$label, $unit] = SensorPagePresenter::METRICS[$key];
            $rule = self::metricRule($device, $key);
            $metrics[] = [
                'key' => $key,
                'label' => $label,
                'unit' => $unit,
                'mode' => $key === 'co2' ? 'threshold' : 'range',
                'enabled' => $rule['enabled'],
                'below' => $rule['below'],
                'above' => $rule['above'],
                'warning_at' => $rule['warning_at'],
                'critical_at' => $rule['critical_at'],
            ];
        }

        $storedBattery = data_get($device->config ?? [], self::CONFIG_KEY.'.battery');

        return [
            'metrics' => $metrics,
            'battery' => ($hasBattery || is_array($storedBattery)) ? self::batteryRule($device) : null,
        ];
    }

    /**
     * Validate and save per-sensor alert rules, re-checking current readings and logging the change.
     *
     * @param  array<string, mixed>  $input
     */
    public function update(User $actor, Device $device, array $input): void
    {
        abort_unless($actor->canDo('securityDevices.devices.view') && $actor->canDo('securityDevices.automations.manage'), 403);
        $this->access->assertCanViewDevice($actor, $device);
        abort_unless(SensorPagePresenter::serves($device), 404);
        abort_if($device->status === DeviceStatus::Decommissioned, 409, 'Retired sensors cannot be changed.');

        $submittedMetrics = is_array($input['metrics'] ?? null) ? $input['metrics'] : [];
        $submittedBattery = is_array($input['battery'] ?? null) ? $input['battery'] : null;

        if ($submittedMetrics === [] && $submittedBattery === null) {
            throw ValidationException::withMessages(['metrics' => 'Choose at least one alert rule to update.']);
        }

        $siteId = $this->siteOf($device);
        $now = CarbonImmutable::now('UTC');
        $readingChanges = [];
        $batteryToEvaluate = null;
        $batteryTurnedOff = false;
        // A sensor that keeps a resident safe (linked to their care, or watching a tap for scalds)
        // never loses its battery alert: a flat battery would silence it with no warning.
        $keepsSomeoneSafe = $this->links->currentFor($device) !== null || ScaldWatch::watches($device);

        DB::transaction(function () use (
            $actor,
            $device,
            $submittedMetrics,
            $submittedBattery,
            $siteId,
            $now,
            $keepsSomeoneSafe,
            &$readingChanges,
            &$batteryToEvaluate,
            &$batteryTurnedOff,
        ): void {
            /** @var Device $locked */
            $locked = Device::query()->whereKey($device->id)->lockForUpdate()->firstOrFail();
            $config = is_array($locked->config) ? $locked->config : [];
            $stored = is_array($config[self::CONFIG_KEY] ?? null) ? $config[self::CONFIG_KEY] : [];
            $metricsStore = is_array($stored['metrics'] ?? null) ? $stored['metrics'] : [];
            $changedFields = [];
            $changeWords = [];

            foreach ($submittedMetrics as $metric => $payload) {
                if (! is_string($metric) || ! isset(SensorPagePresenter::METRICS[$metric]) || ! is_array($payload)) {
                    throw ValidationException::withMessages(['metrics' => 'Unknown sensor reading rule.']);
                }
                $current = self::metricRule($locked, $metric);
                $next = $this->validatedMetric($metric, $current, $payload);
                if (self::ruleWords($metric, $current) !== self::ruleWords($metric, $next)) {
                    $changeWords[] = self::ruleWords($metric, $current).' → '.self::ruleWords($metric, $next);
                }
                $metricsStore[$metric] = $metric === 'co2'
                    ? [
                        'enabled' => $next['enabled'],
                        'warning_at' => $next['warning_at'],
                        'critical_at' => $next['critical_at'],
                    ]
                    : [
                        'enabled' => $next['enabled'],
                        'below' => $next['below'],
                        'above' => $next['above'],
                    ];
                $changedFields[] = 'sensor_limits.'.$metric;
            }

            $stored['metrics'] = $metricsStore;

            if ($submittedBattery !== null) {
                $currentBattery = self::batteryRule($locked);
                $enabled = array_key_exists('enabled', $submittedBattery) ? (bool) $submittedBattery['enabled'] : $currentBattery['enabled'];
                $below = array_key_exists('below', $submittedBattery)
                    ? $this->validatedBatteryBelow($submittedBattery['below'])
                    : $currentBattery['below'];
                if (! $enabled && $keepsSomeoneSafe) {
                    throw ValidationException::withMessages(['battery.enabled' => 'This sensor keeps a resident safe, so its battery alert stays on. You can change the percentage.']);
                }
                $batteryWords = fn (bool $on, int $percent): string => $on ? "Battery under {$percent}%" : 'Battery alert off';
                if ($batteryWords($currentBattery['enabled'], $currentBattery['below']) !== $batteryWords($enabled, $below)) {
                    $changeWords[] = $batteryWords($currentBattery['enabled'], $currentBattery['below']).' → '.$batteryWords($enabled, $below);
                }
                $batteryTurnedOff = $currentBattery['enabled'] && ! $enabled;
                $stored['battery'] = ['enabled' => $enabled, 'below' => $below];
                $changedFields[] = 'sensor_limits.battery';
            }

            // The audit log keeps who changed which rules; the sensor keeps what they changed, in words.
            if ($changeWords !== []) {
                $history = is_array($stored['history'] ?? null) ? $stored['history'] : [];
                array_unshift($history, [
                    'at' => $now->toIso8601String(),
                    'by_user_id' => (int) $actor->id,
                    'by' => (string) $actor->name,
                    'changes' => $changeWords,
                ]);
                $stored['history'] = array_slice($history, 0, self::HISTORY_KEEP);
            }

            $config[self::CONFIG_KEY] = $stored;
            $locked->config = $config;

            $meta = is_array($locked->meta) ? $locked->meta : [];
            $observed = is_array($meta['observed'] ?? null) ? $meta['observed'] : [];
            $sensorReadings = is_array($observed['sensor_readings'] ?? null) ? $observed['sensor_readings'] : [];

            foreach ($sensorReadings as $key => $reading) {
                if (! is_string($key) || ! isset(SensorPagePresenter::METRICS[$key]) || ! is_array($reading) || ! is_numeric($reading['value'] ?? null)) {
                    continue;
                }
                $before = in_array($reading['state'] ?? null, ['warning', 'critical'], true) ? (string) $reading['state'] : 'healthy';
                $after = self::evaluate($locked, $key, (float) $reading['value']);
                if ($before !== $after) {
                    $sensorReadings[$key]['state'] = $after;
                    $readingChanges[$key] = [
                        'label' => (string) ($reading['label'] ?? SensorPagePresenter::METRICS[$key][0]),
                        'state' => $after,
                        'value' => (float) $reading['value'],
                        'unit' => (string) ($reading['unit'] ?? (self::METRIC_UNITS[$key] ?? '')),
                    ];
                }
            }

            if ($readingChanges !== []) {
                $observed['sensor_readings'] = $sensorReadings;
                $meta['observed'] = $observed;
            }

            $locked->forceFill([
                'config' => $config,
                'meta' => $meta,
            ])->save();

            if ($submittedBattery !== null && $locked->battery_level !== null) {
                $batteryToEvaluate = (int) $locked->battery_level;
            }

            $anyEnabled = self::batteryRule($locked)['enabled']
                || collect(array_keys(SensorPagePresenter::METRICS))->contains(fn (string $key): bool => self::metricRule($locked, $key)['enabled']);

            AuditLogger::logOrFail('security_devices.sensor_limits.updated', $locked, [
                'fields' => array_values(array_unique($changedFields)),
                'after' => ['is_enabled' => $anyEnabled],
                ...($siteId !== null ? ['scope' => ['site_id' => $siteId]] : []),
            ]);
        });

        $fresh = $device->fresh() ?? $device;
        foreach ($readingChanges as $metric => $change) {
            if ($change['state'] === 'healthy') {
                // The reading didn't recover: a person changed the rule. Say so, and who.
                $this->alerts->closeForRuleChange($fresh, 'reading_'.$metric, $actor, $now, ucfirst($change['label']).' alert limits changed by '.$actor->name);

                continue;
            }
            $this->alerts->onReading(
                $fresh,
                (string) $metric,
                $change['label'],
                $change['state'],
                $change['value'],
                $change['unit'],
                $now,
            );
        }
        if ($batteryTurnedOff) {
            $this->alerts->closeForRuleChange($fresh, 'battery', $actor, $now, 'Battery alert turned off by '.$actor->name);
        } elseif ($batteryToEvaluate !== null) {
            $this->alerts->onBattery($fresh, $batteryToEvaluate, $now);
        }
    }

    /**
     * The latest limit changes on this sensor, newest first, for the Alert rules card.
     *
     * @return list<array{at: string, by: string, changes: list<string>}>
     */
    public static function history(Device $device, int $limit = 3): array
    {
        $history = data_get($device->config ?? [], self::CONFIG_KEY.'.history');

        return collect(is_array($history) ? $history : [])
            ->filter(fn (mixed $row): bool => is_array($row) && is_string($row['at'] ?? null) && is_array($row['changes'] ?? null))
            ->take($limit)
            ->map(fn (array $row): array => [
                'at' => (string) $row['at'],
                'by' => (string) ($row['by'] ?? 'Someone'),
                'changes' => array_values(array_filter($row['changes'], 'is_string')),
            ])
            ->values()
            ->all();
    }

    /** One rule in words, for the change history: "Too warm above 32 °C" or "Temperature alert off". */
    private static function ruleWords(string $metric, array $rule): string
    {
        [$label] = SensorPagePresenter::METRICS[$metric];
        $hasThreshold = $rule['below'] !== null || $rule['above'] !== null || $rule['warning_at'] !== null;

        return $rule['enabled'] && $hasThreshold ? self::ruleLabel($metric, $rule) : $label.' alert off';
    }

    /**
     * @param  array{enabled: bool, below: float|null, above: float|null, warning_at: float|null, critical_at: float|null}  $current
     * @param  array<string, mixed>  $payload
     * @return array{enabled: bool, below: float|null, above: float|null, warning_at: float|null, critical_at: float|null}
     */
    private function validatedMetric(string $metric, array $current, array $payload): array
    {
        $enabled = array_key_exists('enabled', $payload) ? (bool) $payload['enabled'] : $current['enabled'];
        [$label] = SensorPagePresenter::METRICS[$metric];

        if ($metric === 'co2') {
            $warning = array_key_exists('warning_at', $payload) ? $this->validatedFloat($metric, $payload['warning_at']) : $current['warning_at'];
            $critical = array_key_exists('critical_at', $payload) ? $this->validatedFloat($metric, $payload['critical_at']) : $current['critical_at'];
            if ($enabled && $warning === null) {
                throw ValidationException::withMessages(['metrics.'.$metric => "Enter a {$label} level before turning this alert on."]);
            }
            if ($warning !== null && $critical !== null && $critical <= $warning) {
                throw ValidationException::withMessages(['metrics.'.$metric => "The urgent {$label} level must be higher than the first alert level."]);
            }

            return [
                'enabled' => $enabled,
                'below' => null,
                'above' => null,
                'warning_at' => $warning,
                'critical_at' => $critical,
            ];
        }

        $below = array_key_exists('below', $payload) ? $this->validatedFloat($metric, $payload['below']) : $current['below'];
        $above = array_key_exists('above', $payload) ? $this->validatedFloat($metric, $payload['above']) : $current['above'];

        if ($enabled && $below === null && $above === null) {
            throw ValidationException::withMessages(['metrics.'.$metric => "Set at least a low or high limit for {$label}."]);
        }
        if ($below !== null && $above !== null && $below >= $above) {
            throw ValidationException::withMessages(['metrics.'.$metric => "For {$label}, the low limit must be under the high limit."]);
        }

        return [
            'enabled' => $enabled,
            'below' => $below,
            'above' => $above,
            'warning_at' => null,
            'critical_at' => null,
        ];
    }

    private function validatedFloat(string $metric, mixed $raw): ?float
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (! is_int($raw) && ! is_float($raw) && ! (is_string($raw) && is_numeric(trim($raw)))) {
            throw ValidationException::withMessages(['metrics.'.$metric => 'Enter a valid number.']);
        }
        $num = round((float) $raw, 1);
        [$min, $max] = self::RANGES[$metric] ?? [-100.0, 100_000.0];
        if (! is_finite($num) || $num < $min || $num > $max) {
            [$label] = SensorPagePresenter::METRICS[$metric] ?? [ucfirst($metric)];
            throw ValidationException::withMessages(['metrics.'.$metric => "For {$label}, choose a limit between ".self::valueWords($metric, $min).' and '.self::valueWords($metric, $max).'.']);
        }

        return $num;
    }

    private function validatedBatteryBelow(mixed $raw): int
    {
        if (! is_int($raw) && ! is_float($raw) && ! (is_string($raw) && is_numeric(trim($raw)))) {
            throw ValidationException::withMessages(['battery.below' => 'Choose a battery percentage between 5% and 90%.']);
        }
        $num = (int) round((float) $raw);
        if ($num < 5 || $num > 90) {
            throw ValidationException::withMessages(['battery.below' => 'Choose a battery percentage between 5% and 90%.']);
        }

        return $num;
    }

    /**
     * @param  array{enabled: bool, below: float|null, above: float|null, warning_at: float|null, critical_at: float|null}  $rule
     */
    private static function ruleLabel(string $metric, array $rule): string
    {
        [$label] = SensorPagePresenter::METRICS[$metric];
        if ($metric === 'co2') {
            $warn = $rule['warning_at'] ?? 1000.0;

            return $rule['critical_at'] !== null
                ? $label.' at '.self::valueWords($metric, $warn).' or more'
                : $label.' above '.self::valueWords($metric, $warn);
        }
        if ($rule['below'] !== null && $rule['above'] !== null) {
            return $label.' below '.self::valueWords($metric, $rule['below']).' or above '.self::valueWords($metric, $rule['above']);
        }
        if ($rule['below'] !== null) {
            return $metric === 'temperature'
                ? 'Too cold below '.self::valueWords($metric, $rule['below'])
                : $label.' below '.self::valueWords($metric, $rule['below']);
        }
        $above = $rule['above'] ?? 0.0;

        return $metric === 'temperature'
            ? 'Too warm above '.self::valueWords($metric, $above)
            : $label.' above '.self::valueWords($metric, $above);
    }

    private static function nullableFloat(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) ? round((float) $value, 1) : null;
    }

    private static function valueWords(string $metric, float $value): string
    {
        $unit = SensorPagePresenter::METRICS[$metric][1] ?? '';
        $number = $metric === 'co2' || abs($value) >= 1000 ? number_format($value, 0, '.', ',') : self::number($value);

        return match ($unit) {
            '%' => $number.'%',
            '' => $number,
            default => $number.' '.$unit,
        };
    }

    private static function number(float $value): string
    {
        $text = rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');

        return str_starts_with($text, '-') ? '−'.substr($text, 1) : $text;
    }

    private function siteOf(Device $device): ?int
    {
        try {
            $siteId = $this->sites->resolve((int) $device->id);
        } catch (Throwable) {
            return null;
        }

        return $siteId > 0 ? $siteId : null;
    }
}
