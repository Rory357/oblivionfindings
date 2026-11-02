<?php

namespace App\Domain\SecurityDevices\Moves;

use App\Domain\Monitoring\Discovery\Models\DiscoveryCandidate;
use App\Domain\Monitoring\Discovery\Services\DeviceFingerprintService;
use App\Domain\SecurityDevices\Config\DeviceTaxonomy;
use App\Domain\SecurityDevices\Drivers\Services\DeviceDriverRegistry;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Screens\DeviceKind;
use Throwable;

/**
 * What a device that comes out as "Other device" really is, from clear evidence only (S&D data moves,
 * "Devices: kind, room and Site"). Three kinds of evidence count, each already stored:
 *
 *  1. a discovery fingerprint: a scan matched to this device, identified with high confidence (80+);
 *  2. a UniFi fingerprint: UniFi's own model code for the device (UDM, USW, U6, UNVR …);
 *  3. the driver catalogue: the maker and a model code that a driver lists.
 *
 * When the evidence disagrees, nothing is suggested. A subcategory is suggested only when it is a known
 * type for the device's own domain and category (the move never changes those) and it makes the
 * device's kind what the evidence says. Otherwise the evidence is shown so a person can fix the type.
 */
final class DeviceTypeEvidence
{
    /** UniFi model-code prefixes, longest first, as UnifiOperationalBridgeService reads them. */
    private const array UNIFI_PREFIXES = [
        'unvr' => ['security', 'cctv', 'nvr'],
        'udm' => ['it_infrastructure', 'network', 'router'],
        'udr' => ['it_infrastructure', 'network', 'router'],
        'uxg' => ['it_infrastructure', 'network', 'router'],
        'ucg' => ['it_infrastructure', 'network', 'router'],
        'usw' => ['it_infrastructure', 'network', 'switch'],
        'uap' => ['it_infrastructure', 'network', 'wireless_ap'],
        'uvc' => ['security', 'cctv', 'ip_camera'],
        'u6' => ['it_infrastructure', 'network', 'wireless_ap'],
        'u7' => ['it_infrastructure', 'network', 'wireless_ap'],
        'ux' => ['it_infrastructure', 'network', 'router'],
    ];

    /** Driver classes that name exactly one kind. Network, trackers and hub logs don't. */
    private const array CLASS_KINDS = [
        'camera' => DeviceKind::Camera,
        'alarm_panel' => DeviceKind::AlarmPanel,
        'access_control' => DeviceKind::Door,
        'sensor' => DeviceKind::Sensor,
        'computer' => DeviceKind::Computer,
    ];

    public function __construct(
        private readonly DeviceFingerprintService $fingerprints,
        private readonly DeviceDriverRegistry $drivers,
    ) {}

    /**
     * What the evidence says. `kind` is null when there is no clear evidence, or it disagrees;
     * `subcategory` is null when nothing can be set without changing the device's category.
     *
     * @return array{kind: ?DeviceKind, subcategory: ?string, source: string, note: string}
     */
    public function for(Device $device): array
    {
        $found = array_values(array_filter([
            $this->discovery($device),
            $this->unifi($device),
            $this->catalogue($device),
        ]));
        if ($found === []) {
            return ['kind' => null, 'subcategory' => null, 'source' => '', 'note' => 'No clear evidence of what it is.'];
        }

        $kinds = array_unique(array_map(fn (array $evidence): string => $evidence['kind']->value, $found));
        if (count($kinds) > 1) {
            return [
                'kind' => null,
                'subcategory' => null,
                'source' => implode(' + ', array_column($found, 'source')),
                'note' => 'The evidence disagrees ('.implode(', ', array_map(fn (array $e): string => $e['source'].': '.$e['kind']->label(), $found)).').',
            ];
        }

        $kind = $found[0]['kind'];
        $source = implode(' + ', array_column($found, 'source'));
        $exact = array_values(array_filter(array_column($found, 'subcategory')));
        $subcategory = $this->pick($device, $exact, $kind);

        return [
            'kind' => $kind,
            'subcategory' => $subcategory,
            'source' => $source,
            'note' => $subcategory !== null
                ? $kind->label().' ('.$source.').'
                : $source.' says '.$kind->label().', but no single type in its category '
                    .'('.$device->domain.' › '.$device->category.') fits. Change its type on the device page.',
        ];
    }

    /**
     * The subcategory to set: one the evidence named, else the only one in the device's own category
     * that gives the evidence's kind.
     *
     * @param  list<string>  $exact
     */
    private function pick(Device $device, array $exact, DeviceKind $kind): ?string
    {
        $domain = (string) $device->domain;
        $category = strtolower((string) $device->category);
        foreach (array_unique($exact) as $subcategory) {
            if (DeviceTaxonomy::isValid($domain, $category, $subcategory)
                && DeviceKind::from_($domain, $category, $subcategory) === $kind) {
                return $subcategory;
            }
        }

        $fits = array_values(array_filter(
            array_keys(DeviceTaxonomy::subcategoriesFor($domain, $category)),
            fn (string $subcategory): bool => DeviceKind::from_($domain, $category, $subcategory) === $kind,
        ));

        return count($fits) === 1 ? $fits[0] : null;
    }

    /** @return array{kind: DeviceKind, subcategory: ?string, source: string}|null */
    private function discovery(Device $device): ?array
    {
        $candidate = DiscoveryCandidate::query()
            ->where('canonical_device_id', $device->id)
            // Only a scan result someone (or the matcher) confirmed is this device: a reused IP or
            // hostname can link a different device's fingerprint to it.
            ->where('decision', 'matched')
            ->whereNotNull('evidence_snapshot')
            ->orderByDesc('id')
            ->first();
        if (! $candidate instanceof DiscoveryCandidate || ! is_array($candidate->evidence_snapshot)) {
            return null;
        }

        try {
            $identification = $this->fingerprints->identifyFromSnapshot($candidate->evidence_snapshot);
        } catch (Throwable) {
            return null;
        }
        if ($identification->confidence < 80) {
            return null;
        }
        $kind = DeviceKind::from_($identification->domain, $identification->category, (string) $identification->subcategory);

        return $kind === DeviceKind::Other ? null : [
            'kind' => $kind,
            'subcategory' => $identification->subcategory,
            'source' => 'Discovery scan',
        ];
    }

    /** @return array{kind: DeviceKind, subcategory: ?string, source: string}|null */
    private function unifi(Device $device): ?array
    {
        $provider = strtolower(trim((string) ($device->provider ?: data_get($device->external_ref, 'provider'))));
        if (! in_array($provider, ['unifi', 'ubiquiti'], true)) {
            return null;
        }

        $productLine = strtolower(trim((string) data_get($device->meta, 'product_line')));
        foreach ([data_get($device->meta, 'provider_type'), data_get($device->external_ref, 'provider_type'), $device->model] as $code) {
            $code = strtolower(trim((string) $code));
            if ($code === '') {
                continue;
            }
            foreach (self::UNIFI_PREFIXES as $prefix => [$domain, $category, $subcategory]) {
                if (str_starts_with($code, $prefix)) {
                    return ['kind' => DeviceKind::from_($domain, $category, $subcategory), 'subcategory' => $subcategory, 'source' => 'UniFi model'];
                }
            }
        }

        return match ($productLine) {
            'protect' => ['kind' => DeviceKind::Camera, 'subcategory' => 'ip_camera', 'source' => 'UniFi Protect'],
            'access' => ['kind' => DeviceKind::Door, 'subcategory' => 'card_reader', 'source' => 'UniFi Access'],
            default => null,
        };
    }

    /** @return array{kind: DeviceKind, subcategory: null, source: string}|null */
    private function catalogue(Device $device): ?array
    {
        $maker = strtolower(trim((string) $device->manufacturer));
        $model = trim((string) $device->model);
        if ($maker === '' || $model === '') {
            return null;
        }

        $kinds = [];
        foreach ($this->drivers->all() as $driver) {
            $meta = $driver->metadata();
            $kind = self::CLASS_KINDS[$meta->deviceClass->value] ?? null;
            if ($kind === null) {
                continue;
            }
            $brand = (preg_split('/[\s(]+/u', trim($meta->brand)) ?: [''])[0];
            if ($brand === '' || preg_match('/(?<![\p{L}\p{N}])'.preg_quote($brand, '/').'(?![\p{L}\p{N}])/iu', $maker) !== 1) {
                continue;
            }
            foreach (self::modelCodes($meta->models) as $code) {
                if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($code, '/').'(?![\p{L}\p{N}])/iu', $model) === 1) {
                    $kinds[$kind->value] = $kind;
                    break;
                }
            }
        }

        return count($kinds) === 1
            ? ['kind' => array_values($kinds)[0], 'subcategory' => null, 'source' => 'Driver catalogue']
            : null;
    }

    /**
     * The model codes a driver lists ("HS2016", "AM307", "USW-Lite-8-PoE"), not series words or versions.
     *
     * @param  list<string>  $models
     * @return list<string>
     */
    private static function modelCodes(array $models): array
    {
        $codes = [];
        foreach ($models as $entry) {
            foreach (preg_split('/[\s\/,()&]+/u', $entry) ?: [] as $token) {
                $token = trim($token, " +.\t");
                if (mb_strlen($token) >= 3
                    && preg_match('/\p{L}/u', $token) === 1
                    && preg_match('/\p{N}/u', $token) === 1
                    && preg_match('/^(?:v\d|x(?:64|86)$)/i', $token) !== 1) {
                    $codes[] = $token;
                }
            }
        }

        return array_values(array_unique($codes));
    }
}
