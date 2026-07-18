<?php

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Models\ControlRoom\Device as ControlRoomDevice;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoom\SignalSource;
use App\Models\ControlRoomAlert;
use App\Services\ControlRoom\SignalProcessingService;
use Database\Seeders\SecurityDevicesSignalSeeder;

beforeEach(function () {
    $this->seed(SecurityDevicesSignalSeeder::class);
});

it('resolves the offline alert and does not create a device-online alert', function () {
    $device = Device::factory()->itInfrastructure()->create();
    $projection = ControlRoomDevice::create([
        'name' => $device->name,
        'canonical_device_id' => $device->id,
        'type' => ControlRoomDevice::TYPE_NETWORK,
    ]);

    DeviceEvent::create([
        'device_id' => $device->id,
        'event_type' => 'offline',
        'severity' => 'high',
        'source' => 'oblivion_monitoring',
        'occurred_at' => now()->subMinute(),
    ]);
    $offline = ControlRoomAlert::where('device_id', $projection->id)->latest('id')->firstOrFail();

    $recoveryEvent = DeviceEvent::create([
        'device_id' => $device->id,
        'event_type' => 'online',
        'severity' => 'info',
        'source' => 'oblivion_monitoring',
        'occurred_at' => now(),
    ]);

    expect($offline->fresh()->status)->toBe(ControlRoomAlert::STATUS_RESOLVED)
        ->and(data_get($offline->fresh()->context, 'resolution.source'))->toBe('monitoring_recovery')
        ->and(ControlRoomAlert::where('device_id', $projection->id)->count())->toBe(1)
        ->and(Signal::where('signal_type_code', 'device_online')->firstOrFail()->status)->toBe('processed')
        ->and($recoveryEvent->fresh()->processed_at)->not->toBeNull();
});

it('processes an identity-less recovery without resolving unrelated alerts', function () {
    $device = Device::factory()->itInfrastructure()->create();
    $projection = ControlRoomDevice::create([
        'name' => $device->name,
        'canonical_device_id' => $device->id,
        'type' => ControlRoomDevice::TYPE_NETWORK,
    ]);

    DeviceEvent::create([
        'device_id' => $device->id,
        'event_type' => 'offline',
        'severity' => 'high',
        'source' => 'oblivion_monitoring',
        'occurred_at' => now()->subMinute(),
    ]);
    $offline = ControlRoomAlert::where('device_id', $projection->id)->firstOrFail();

    $source = SignalSource::where('slug', 'security_devices')->firstOrFail();
    $signal = app(SignalProcessingService::class)->ingest([
        'signal_source_id' => $source->id,
        'signal_type_code' => 'device_online',
        'severity_hint' => 'info',
        'occurred_at' => now(),
        'normalized_data' => [],
    ]);

    $resolved = app(SignalProcessingService::class)->processDeviceRecovery($signal);

    expect($resolved)->toBe(0)
        ->and($offline->fresh()->status)->toBe(ControlRoomAlert::STATUS_OPEN)
        ->and($signal->fresh()->status)->toBe('processed');
});

it('rejects non-recovery signals from the device recovery path', function () {
    $source = SignalSource::where('slug', 'security_devices')->firstOrFail();
    $signal = app(SignalProcessingService::class)->ingest([
        'signal_source_id' => $source->id,
        'signal_type_code' => 'device_offline',
        'severity_hint' => 'high',
        'occurred_at' => now(),
    ]);

    expect(fn () => app(SignalProcessingService::class)->processDeviceRecovery($signal))
        ->toThrow(InvalidArgumentException::class, 'Only device_online signals');
});
