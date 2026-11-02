<?php

namespace App\Domain\SecurityDevices\Screens\Devices;

use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Models\MonitoringProfile;
use App\Domain\SecurityDevices\Drivers\Enums\DriverDeviceClass;
use App\Domain\SecurityDevices\Drivers\Enums\DriverSupportLevel;
use App\Domain\SecurityDevices\Drivers\Services\DeviceDriverRegistry;
use App\Domain\SecurityDevices\Hubs\Services\CoveringHubResolver;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Screens\DeviceKind;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Site;
use App\Models\User;

/**
 * What the Devices pop-ups choose from: the eight kinds, brands and models from Supported devices,
 * the Sites the viewer may put a device at, and the hub that checks each Site. Loaded only when a
 * pop-up opens.
 */
final class DeviceFormOptions
{
    /**
     * The Add one device kinds and what each is stored as in the registry.
     *
     * @var array<string, array{label: string, domain: string, category: string, subcategory: ?string}>
     */
    public const array KINDS = [
        'network' => ['label' => 'Network', 'domain' => 'it_infrastructure', 'category' => 'network', 'subcategory' => null],
        'camera' => ['label' => 'Camera', 'domain' => 'security', 'category' => 'cctv', 'subcategory' => 'ip_camera'],
        'alarm' => ['label' => 'Alarm', 'domain' => 'security', 'category' => 'alarm', 'subcategory' => 'panel'],
        'door' => ['label' => 'Door', 'domain' => 'security', 'category' => 'access_control', 'subcategory' => 'card_reader'],
        'sensor' => ['label' => 'Sensor', 'domain' => 'iot_healthcare', 'category' => 'environmental', 'subcategory' => null],
        'tracker' => ['label' => 'Tracker', 'domain' => 'tracking', 'category' => 'personal_tracker', 'subcategory' => 'wearable_gps'],
        'computer' => ['label' => 'Computer', 'domain' => 'it_infrastructure', 'category' => 'endpoint', 'subcategory' => 'workstation'],
        'other' => ['label' => 'Other', 'domain' => 'it_infrastructure', 'category' => 'other', 'subcategory' => null],
    ];

    private const array CLASS_KIND = [
        'camera' => 'camera',
        'alarm_panel' => 'alarm',
        'access_control' => 'door',
        'sensor' => 'sensor',
        'tracker' => 'tracker',
        'network' => 'network',
        'computer' => 'computer',
    ];

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly DeviceDriverRegistry $drivers,
    ) {}

    /** The Add one device kind a registered device shows as. */
    public static function kindOf(Device $device): string
    {
        $kind = DeviceKind::of($device);

        return match ($kind) {
            DeviceKind::AlarmPanel => 'alarm',
            DeviceKind::Door => 'door',
            default => match ($kind->tab()) {
                'network' => 'network',
                'cameras' => 'camera',
                'sensors' => 'sensor',
                'trackers' => 'tracker',
                'computers' => 'computer',
                default => 'other',
            },
        };
    }

    /** @return array<string, mixed> */
    public function present(User $viewer): array
    {
        $siteIds = $viewer->canDo('securityDevices.devices.assign') || $viewer->canDo('securityDevices.integrations.manage')
            ? $this->access->accessibleSiteIds($viewer)
            : [];
        $sites = $siteIds === []
            ? collect()
            : Site::query()
                ->whereIn('id', $siteIds)
                ->where('is_active', true)
                ->where('archived', false)
                ->whereNull('archived_at')
                ->orderBy('name')
                ->get(['id', 'name']);

        $hubs = MonitoringCollector::query()
            ->whereNotNull('approved_at')
            ->whereNull('revoked_at')
            ->orderBy('id')
            ->get();
        $resolver = app(CoveringHubResolver::class);

        $ranges = $sites->isEmpty()
            ? collect()
            : DiscoveryScope::query()
                ->whereIn('site_id', $sites->pluck('id'))
                ->where('status', 'active')
                ->orderBy('id')
                ->get(['id', 'site_id', 'cidrs'])
                ->groupBy('site_id');

        return [
            'kinds' => collect(self::KINDS)->map(fn (array $kind, string $key): array => [
                'value' => $key,
                'label' => $kind['label'],
            ])->values()->all(),
            'models' => $this->models(),
            'watch' => $this->watchTimings(),
            'sites' => $sites->map(function (Site $site) use ($hubs, $resolver, $siteIds, $ranges): array {
                $covering = $hubs
                    ->filter(fn (MonitoringCollector $hub): bool => in_array((int) $site->id, $hub->authorisedSiteIds(), true))
                    ->values();
                $suggested = $resolver->forSite((int) $site->id);
                $label = fn (MonitoringCollector $hub): string => in_array((int) $hub->site_id, $siteIds, true)
                    ? CoveringHubResolver::label($hub)
                    : 'the Site hub';
                $range = $ranges->get($site->id)?->flatMap(fn (DiscoveryScope $scope): array => (array) $scope->cidrs)
                    ->first(fn (mixed $cidr): bool => is_string($cidr) && str_contains($cidr, '/') && ! str_ends_with($cidr, '/32'));

                return [
                    'id' => (int) $site->id,
                    'name' => (string) $site->name,
                    'checked_by' => $suggested !== null ? $label($suggested) : 'The Oblivion server',
                    'hubs' => $covering->map(fn (MonitoringCollector $hub): array => [
                        'id' => (int) $hub->id,
                        'label' => $label($hub),
                    ])->all(),
                    'suggested_hub_id' => $suggested?->id !== null ? (int) $suggested->id : null,
                    'suggested_range' => is_string($range) ? $range : null,
                ];
            })->all(),
        ];
    }

    /**
     * Watch this device (pop-up 9) turns on the offline check, which uses the standard availability
     * timings. Said in words, with how soon someone would know.
     *
     * @return array{every: string, after: string, know_within: string}
     */
    private function watchTimings(): array
    {
        $profile = MonitoringProfile::query()
            ->where('name', MonitoringProfile::DEFAULT_AVAILABILITY_NAME)
            ->where('is_active', true)
            ->first();
        $interval = max(1, (int) ($profile?->interval_seconds ?? 60));
        $misses = max(1, (int) ($profile?->failure_confirmations ?? 3));
        $every = $interval % 60 === 0
            ? ($interval === 60 ? 'Every minute' : 'Every '.intdiv($interval, 60).' minutes')
            : 'Every '.$interval.' seconds';
        $within = (int) ceil($interval * $misses / 60);

        return [
            'every' => $every,
            'after' => $misses === 1 ? '1 missed check' : $misses.' missed checks',
            'know_within' => $within <= 1 ? 'about a minute' : 'about '.$within.' minutes',
        ];
    }

    /** @return list<array{value: string, label: string, description: string, kind: string, brand: string, model: string}> */
    private function models(): array
    {
        $rows = [];
        foreach ($this->drivers->all() as $driver) {
            $meta = $driver->metadata();
            if ($meta->deviceClass === DriverDeviceClass::Telemetry) {
                continue;
            }
            $kind = self::CLASS_KIND[$meta->deviceClass->value] ?? 'other';
            // The rebuild never shows "Tested on real hardware" until a walk proves it (brief rule).
            $level = $meta->supportLevel === DriverSupportLevel::TestedOnHardware ? null : $meta->supportLevel->label();
            $support = implode(' · ', array_filter([$level, $meta->viewOnly ? 'view only' : null]));
            $models = $meta->models === [] ? [$meta->name] : $meta->models;
            foreach ($models as $model) {
                $value = $meta->brand.'|'.$model;
                $rows[$value] = [
                    'value' => $value,
                    'label' => $meta->brand.' '.$model,
                    'description' => $support,
                    'kind' => $kind,
                    'brand' => $meta->brand,
                    'model' => $model,
                ];
            }
        }

        return array_values($rows);
    }
}
