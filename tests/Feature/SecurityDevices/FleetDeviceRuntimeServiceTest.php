<?php

use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Models\Asset;
use App\Models\AssetTracker;
use App\Services\Fleet\FleetDeviceRuntimeService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates one canonical non-operational Device for an unrecognised tracker state', function () {
    $asset = Asset::factory()->create();
    $tracker = AssetTracker::query()->create([
        'asset_id' => $asset->id,
        'vendor' => 'queclink',
        'device_uid' => 'TRACKER-PENDING-01',
        'imei' => '867530900000001',
        'status' => 'pending',
        'paired_at' => now(),
    ]);

    $device = app(FleetDeviceRuntimeService::class)
        ->ensureCanonicalDeviceForTracker($tracker);

    expect($device->domain)->toBe('tracking')
        ->and($device->provider)->toBe('queclink')
        ->and($device->legacy_asset_tracker_id)->toBe($tracker->id)
        ->and($device->status)->toBe(DeviceStatus::InStock)
        ->and($device->status->isOperational())->toBeFalse();
});

it('reuses canonical provider identity rather than creating a second Device', function () {
    $asset = Asset::factory()->create();
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
        ->and($first->status->isOperational())->toBeTrue();
});
