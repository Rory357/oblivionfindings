<?php

use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Models\Asset;
use App\Models\AssetTracker;
use App\Models\Site;
use App\Services\Fleet\FleetDeviceRuntimeService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates one canonical non-operational Device for an unrecognised tracker state', function () {
    $site = Site::factory()->create();
    $asset = Asset::factory()->forSite($site)->create();
    $tracker = AssetTracker::query()->create([
        'asset_id' => $asset->id,
        'vendor' => 'queclink',
        'device_uid' => 'TRACKER-PENDING-01',
        'imei' => '867530900000001',
        'status' => 'pending',
        'paired_at' => now(),
        'vendor_metadata' => [
            'provider_device_id' => 'provider-tracker-001',
            'access_token' => 'must-not-enter-canonical-device-state',
            'position' => ['latitude' => -36.8485, 'longitude' => 174.7633],
        ],
    ]);

    $device = app(FleetDeviceRuntimeService::class)
        ->ensureCanonicalDeviceForTracker($tracker);

    expect($device->domain)->toBe('tracking')
        ->and($device->provider)->toBe('queclink')
        ->and($device->device_uid)->toBe($tracker->device_uid)
        ->and($device->legacy_asset_tracker_id)->toBe($tracker->id)
        ->and($device->status)->toBe(DeviceStatus::InStock)
        ->and(data_get($device->provider_observed_state, 'provider.value'))->toBe('queclink')
        ->and(data_get($device->provider_observed_state, 'imei.value'))->toBe('867530900000001')
        ->and(data_get($device->provider_observed_state, 'imei.source'))->toBe('queclink')
        ->and(data_get($device->provider_observed_state, 'imei.quality'))->toBe('authoritative_provider')
        ->and(data_get($device->external_ref, 'provider'))->toBe('queclink')
        ->and(data_get($device->external_ref, 'provider_entity_id'))->toBe('provider-tracker-001')
        ->and(data_get($device->external_ref, 'access_token'))->toBeNull()
        ->and(data_get($device->external_ref, 'position'))->toBeNull()
        ->and($device->status->isOperational())->toBeFalse()
        ->and(Device::query()->count())->toBe(1)
        ->and(DeviceAssetLink::query()->active()->where('device_id', $device->id)->count())->toBe(1)
        ->and(DeviceAssetLink::query()->active()->where('device_id', $device->id)->value('asset_id'))->toBe($asset->id)
        ->and(app(CanonicalDeviceSiteResolver::class)->resolveForContext($device->id))->toBe($site->id)
        ->and($tracker->fresh()->asset_id)->toBe($asset->id);
});

it('reuses canonical provider identity rather than creating a second Device', function () {
    $site = Site::factory()->create();
    $asset = Asset::factory()->forSite($site)->create();
    $tracker = AssetTracker::query()->create([
        'asset_id' => $asset->id,
        'vendor' => 'queclink',
        'device_uid' => 'TRACKER-PAIRED-01',
        'imei' => '867530900000002',
        'status' => 'paired',
        'paired_at' => now(),
    ]);
    $runtime = app(FleetDeviceRuntimeService::class);

    $first = $runtime->ensureCanonicalDeviceForTracker($tracker);
    $second = $runtime->ensureCanonicalDeviceForTracker($tracker->fresh());

    expect($second->is($first))->toBeTrue()
        ->and($first->status)->toBe(DeviceStatus::Active)
        ->and($first->status->isOperational())->toBeTrue()
        ->and(Device::query()->count())->toBe(1)
        ->and(DeviceAssetLink::query()->active()->where('device_id', $first->id)->count())->toBe(1)
        ->and(DeviceAssetLink::query()->active()->where('device_id', $first->id)->value('asset_id'))->toBe($asset->id)
        ->and(app(CanonicalDeviceSiteResolver::class)->resolve($first->id))->toBe($site->id);
});
