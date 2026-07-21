<?php

use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Models\MonitoringProfile;
use App\Domain\Monitoring\Models\MonitorObservation;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Site;
use App\Support\LegacyStorageContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('provides the native monitoring persistence contract', function () {
    expect(Schema::hasColumns('monitoring_collectors', [
        'collector_uuid',
        'site_id',
        'status',
        'last_seen_at',
        'config',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('monitoring_profiles', [
            'failure_confirmations',
            'recovery_confirmations',
            'stale_after_seconds',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('monitors', [
            'device_id',
            'profile_id',
            'collector_id',
            'kind',
            'current_state',
            'pending_state',
            'pending_count',
            'affects_availability',
            'last_observation_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('monitor_observations', [
            'monitor_id',
            'device_id',
            'site_id',
            'collector_id',
            'source_key',
            'state',
            'observed_at',
            'ingested_at',
        ]))->toBeTrue();
});

it('globally identifies collectors by uuid', function () {
    $uuid = fake()->uuid();

    MonitoringCollector::factory()->create(['collector_uuid' => $uuid]);

    expect(fn () => DB::table('monitoring_collectors')->insert([
        LegacyStorageContext::column() => 22,
        'collector_uuid' => $uuid,
        'name' => 'Duplicate collector identity',
        'created_at' => now(),
        'updated_at' => now(),
    ]))
        ->toThrow(QueryException::class);
});

it('globally identifies monitoring profiles by name', function () {
    MonitoringProfile::factory()->create(['name' => 'Core network availability']);

    expect(fn () => DB::table('monitoring_profiles')->insert([
        LegacyStorageContext::column() => 22,
        'name' => 'Core network availability',
        'created_at' => now(),
        'updated_at' => now(),
    ]))
        ->toThrow(QueryException::class);
});

it('relates a typed monitor to canonical device site profile collector and observation evidence', function () {
    $site = Site::factory()->create();
    $device = Device::factory()->itInfrastructure()->create();
    DeviceAssignment::create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id,
        'assignment_type' => 'permanent',
        'assigned_at' => now(),
    ]);
    $collector = MonitoringCollector::create([
        'collector_uuid' => fake()->uuid(),
        'name' => 'Remote site collector',
        'site_id' => $site->id,
        'status' => 'online',
        'last_seen_at' => now(),
        'config' => ['buffering' => true],
    ]);
    $profile = MonitoringProfile::create([
        'name' => 'Core network availability',
        'interval_seconds' => 30,
        'failure_confirmations' => 3,
        'recovery_confirmations' => 2,
        'stale_after_seconds' => 180,
        'is_active' => true,
    ]);
    $monitor = Monitor::create([
        'device_id' => $device->id,
        'profile_id' => $profile->id,
        'collector_id' => $collector->id,
        'kind' => MonitorKind::Icmp,
        'name' => 'Gateway reachability',
        'target' => '10.42.0.1',
        'current_state' => MonitorState::Unknown,
        'affects_availability' => true,
    ]);
    $otherSite = Site::factory()->create();
    $otherDevice = Device::factory()->itInfrastructure()->create();
    $otherCollector = MonitoringCollector::factory()->create(['site_id' => $otherSite->id]);
    $observation = $monitor->observations()->create([
        'device_id' => $otherDevice->id,
        'site_id' => $otherSite->id,
        'collector_id' => $otherCollector->id,
        'source_key' => 'collector-1:icmp:20260718T040000Z',
        'state' => MonitorState::Healthy,
        'latency_ms' => 8,
        'metrics' => ['packet_loss_percent' => 0],
        'observed_at' => now(),
        'ingested_at' => now(),
    ]);

    expect($monitor->fresh()->kind)->toBe(MonitorKind::Icmp)
        ->and($monitor->fresh()->current_state)->toBe(MonitorState::Unknown)
        ->and($monitor->device->is($device))->toBeTrue()
        ->and($monitor->profile->is($profile))->toBeTrue()
        ->and($monitor->collector->is($collector))->toBeTrue()
        ->and($observation->fresh()->state)->toBe(MonitorState::Healthy)
        ->and($observation->device->is($device))->toBeTrue()
        ->and($observation->site->is($site))->toBeTrue()
        ->and($observation->collector->is($collector))->toBeTrue()
        ->and($monitor->observations()->sole()->is($observation))->toBeTrue();

    expect(fn () => $observation->update(['site_id' => Site::factory()->create()->id]))
        ->toThrow(LogicException::class, 'Monitoring observation provenance is immutable.');

    expect(fn () => $observation->fresh()
        ->forceFill(['device_id' => $otherDevice->id])
        ->saveQuietly())
        ->toThrow(LogicException::class, 'Monitoring observation provenance is immutable.');

    expect(fn () => MonitorObservation::query()
        ->whereKey($observation->id)
        ->update(['collector_id' => $otherCollector->id]))
        ->toThrow(LogicException::class, 'Monitoring observation provenance is immutable.');

    expect(fn () => MonitorObservation::query()->insert([
        ...LegacyStorageContext::attributes(),
        'monitor_id' => $monitor->id,
        'device_id' => $otherDevice->id,
        'site_id' => $otherSite->id,
        'collector_id' => $otherCollector->id,
        'source_key' => 'unsafe-builder-insert',
        'state' => MonitorState::Healthy->value,
        'observed_at' => now(),
        'ingested_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(LogicException::class, 'Monitoring observations must use the canonical creation boundary.');
});
