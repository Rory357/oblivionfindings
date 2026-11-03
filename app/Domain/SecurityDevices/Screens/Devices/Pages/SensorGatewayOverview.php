<?php

namespace App\Domain\SecurityDevices\Screens\Devices\Pages;

use App\Domain\SecurityDevices\Alerts\Models\DeviceAlert;
use App\Domain\SecurityDevices\Iot\Relay\Models\SensorGatewayLogin;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Screens\Setup\SetupBroker;
use App\Domain\SecurityDevices\Screens\Setup\SetupPresenter;
use App\Domain\SecurityDevices\Screens\Sites\AlertWords;
use App\Domain\SecurityDevices\Screens\Sites\SiteStream;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The sensor gateway's page parts that drawing pg-sdr-22 adds to C2's gateway page: each sensor's
 * signal and readings, how the gateway reaches Oblivion (its own login on the sensor broker and the
 * Site's hub, D21/D22, worded as Setup › Sensor broker words them), what happens if it goes quiet,
 * and the recent alerts on it and its sensors. DevicePagePresenter asks for these for the
 * SensorGateway kind only.
 *
 * Not here, on purpose: its web page and Restart (Phase G), Add a sensor and Replace login (no
 * pop-up drawn for them yet), and a saved login on the gateway (no device-to-login link yet).
 */
final class SensorGatewayOverview
{
    /** @var array<string, array<string, mixed>> read once per request */
    private array $routes = [];

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly SetupBroker $broker,
    ) {}

    /**
     * The header numbers in the drawing's order: sensors reporting, how it reaches Oblivion,
     * batteries low, up for.
     *
     * @param  list<array<string, mixed>>  $gatewayMeters  C2's sensors-reporting and batteries-low meters
     * @param  array<string, mixed>  $uptime
     * @return list<array<string, mixed>>
     */
    public function meters(User $viewer, Device $device, array $gatewayMeters, array $uptime): array
    {
        $route = $this->route($viewer, $device);
        $reaches = [
            'key' => 'reaches',
            'label' => 'Reaches Oblivion',
            'value' => $route['logins'] === [] ? null : $route['hub'],
            'from' => null,
            'ago' => null,
            'caption' => ['text' => $route['logins'] === [] ? 'No sensor broker login linked to it' : $route['connection']['text'], 'at' => null],
            'tone' => $route['logins'] === [] ? 'brand' : match ($route['connection']['tone']) {
                'success' => 'success',
                'warning' => 'warning',
                default => 'brand',
            },
            'tab' => 'overview',
            'bar' => null,
        ];

        return [...array_slice($gatewayMeters, 0, 1), $reaches, ...array_slice($gatewayMeters, 1), $uptime];
    }

    /**
     * @param  list<array<string, mixed>>  $sensors  C2's sensors on this gateway
     * @return array{sensors: list<array<string, mixed>>, gateway: array<string, mixed>}
     */
    public function overview(User $viewer, Device $device, array $sensors): array
    {
        $ids = array_map(fn (array $sensor): int => (int) $sensor['id'], $sensors);
        $meta = $ids === [] ? collect() : Device::query()->whereKey($ids)->get(['id', 'meta'])->mapWithKeys(fn (Device $sensor): array => [(int) $sensor->id => $sensor->meta]);
        $route = $this->route($viewer, $device);

        $enriched = array_map(function (array $sensor) use ($meta): array {
            $stored = data_get($meta->get($sensor['id']), 'observed.sensor_readings');
            $stored = is_array($stored) ? $stored : [];
            $words = [];
            $tone = null;
            foreach (array_keys(SensorPagePresenter::METRICS) as $key) {
                $reading = $stored[$key] ?? null;
                if (! is_array($reading) || ! is_numeric($reading['value'] ?? null)) {
                    continue;
                }
                $words[] = self::reading($key, (float) $reading['value']);
                if (in_array($reading['state'] ?? null, ['warning', 'critical'], true)) {
                    $tone = $tone === 'critical' || $reading['state'] === 'critical' ? 'critical' : 'warning';
                }
            }
            $rssi = data_get($stored, 'rssi.value');

            return [
                ...$sensor,
                'reading' => $words === [] ? null : implode(' · ', array_slice($words, 0, 3)),
                'reading_tone' => $tone,
                'signal' => is_numeric($rssi) ? self::number((float) $rssi).' dBm' : null,
            ];
        }, $sensors);

        return [
            'sensors' => $enriched,
            'gateway' => [
                'route' => $route,
                'quiet' => $this->quiet($device, count($sensors)),
                'recent' => $viewer->canDo('securityDevices.events.view') ? $this->recent($viewer, $device, $ids, $route) : null,
            ],
        ];
    }

    /**
     * How the gateway reaches Oblivion: the Site's hub, the login made for it on the sensor broker,
     * and whether its readings are sent today. A login's name and topic only for those who may see
     * Setup › Services & logins.
     *
     * @return array<string, mixed>
     */
    private function route(User $viewer, Device $device): array
    {
        return $this->routes[$viewer->id.':'.$device->id] ??= $this->readRoute($viewer, $device);
    }

    /** @return array<string, mixed> */
    private function readRoute(User $viewer, Device $device): array
    {
        $logins = SensorGatewayLogin::query()->active()
            ->where('device_id', $device->id)
            ->whereIn('site_id', $this->access->accessibleSiteIds($viewer) ?: [0])
            ->orderBy('id')
            ->get(['id', 'site_id', 'label', 'username', 'topic_prefix', 'created_at', 'created_by_user_id', 'last_reading_at']);
        $details = SetupPresenter::canSeeServices($viewer);
        $rows = $logins->isEmpty() ? collect() : collect($this->broker->present($viewer)['rows'])->keyBy('id');
        $first = $logins->first();
        $row = $first !== null ? $rows->get((int) $first->id) : null;

        return [
            'hub' => $row !== null ? (string) preg_replace('/^Through /', '', (string) $row['reaches']) : null,
            'reaches' => $row['reaches'] ?? null,
            'connection' => $row['connection'] ?? ['tone' => 'neutral', 'text' => 'Not available'],
            'logins' => $logins->map(fn (SensorGatewayLogin $login): array => [
                'id' => (int) $login->id,
                'username' => $details ? (string) $login->username : null,
                'topic' => $details ? (string) $login->topic_prefix.'…' : null,
                'made_at' => $login->created_at?->toIso8601String(),
                'last_reading_at' => $login->last_reading_at?->toIso8601String(),
            ])->values()->all(),
            'broker_href' => $details ? '/security-devices/setup/hubs?tab=broker' : null,
        ];
    }

    /**
     * What happens if the gateway goes quiet, as Oblivion actually does it.
     *
     * @return array{watched: bool, text: string}
     */
    private function quiet(Device $device, int $sensors): array
    {
        $watched = $device->monitors()->where('is_enabled', true)->where('affects_availability', true)->exists();
        $noun = $sensors === 1 ? 'its sensor' : 'its '.$sensors.' sensors';

        return $watched
            ? ['watched' => true, 'text' => 'Oblivion opens one alert, “'.$device->name.' is offline”, and tells who the alert rule names. Readings from '.($sensors === 0 ? 'its sensors' : $noun).' stop arriving until it’s back.']
            : ['watched' => false, 'text' => 'Oblivion doesn’t check this gateway yet, so nobody is told if it goes quiet.'];
    }

    /**
     * Alerts on the gateway and its sensors, and its broker login being made, newest first.
     *
     * @param  list<int>  $sensorIds
     * @param  array<string, mixed>  $route
     * @return list<array<string, mixed>>
     */
    private function recent(User $viewer, Device $device, array $sensorIds, array $route): array
    {
        $now = CarbonImmutable::now();
        $siteIds = $this->access->accessibleSiteIds($viewer);
        $alertModels = $siteIds === []
            ? collect()
            : DeviceAlert::query()
                ->whereIn('device_id', [(int) $device->id, ...$sensorIds])
                ->whereIn('site_id', $siteIds)
                ->where('opened_at', '>=', $now->subDays(90))
                ->latest('opened_at')
                ->limit(6)
                ->get(['id', 'device_id', 'site_id', 'kind', 'title', 'severity', 'state', 'opened_at', 'closed_at']);
        $carePeople = SiteStream::carePeople($viewer, $alertModels);
        $alerts = $alertModels->map(function (DeviceAlert $alert) use ($carePeople, $now): array {
            $words = new AlertWords($alert, $carePeople[(int) $alert->id] ?? false);

            return [
                'key' => 'alert-'.$alert->id,
                'at' => $alert->opened_at?->toIso8601String(),
                'tone' => $alert->isActive() ? ($alert->severity === 'critical' ? 'critical' : 'warning') : 'success',
                'title' => $words->title(),
                'sub' => $alert->isActive() ? 'Still open' : 'Lasted '.\App\Domain\SecurityDevices\Alerts\DeviceAlertEngine::duration($alert->opened_at, $alert->closed_at ?? $now),
                'href' => '/security-devices/alerts?alert='.$alert->id,
            ];
        });
        $logins = collect($route['logins'])->map(fn (array $login): array => [
            'key' => 'login-'.$login['id'],
            'at' => $login['made_at'],
            'tone' => 'info',
            'title' => 'Login made on the sensor broker',
            'sub' => $login['topic'] !== null ? 'May send only to '.$login['topic'] : 'Its own login, for this gateway only',
            'href' => null,
        ]);

        return $alerts->concat($logins)
            ->sortByDesc(fn (array $item): string => (string) $item['at'])
            ->take(6)
            ->values()
            ->all();
    }

    private static function reading(string $key, float $value): string
    {
        $unit = SensorPagePresenter::METRICS[$key][1];
        $number = $key === 'co2' ? number_format($value, 0, '.', ',') : self::number($value);

        return match ($unit) {
            '%' => $number.'%',
            'ppm', 'ppb', 'lx', 'hPa', 'µg/m³', '°C' => $number.' '.$unit,
            default => SensorPagePresenter::METRICS[$key][0].' '.$number,
        };
    }

    private static function number(float $value): string
    {
        $text = rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');

        return str_starts_with($text, '-') ? '−'.substr($text, 1) : $text;
    }
}
