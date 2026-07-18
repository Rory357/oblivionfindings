<?php

use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Models\MonitoringProfile;
use App\Domain\SecurityDevices\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('provides the native monitoring persistence contract', function () {
    expect(Schema::hasColumns('monitoring_collectors', [
        'tenant_id',
        'collector_uuid',
        'site_id',
        'status',
        'last_seen_at',
        'config',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('monitoring_profiles', [
            'tenant_id',
            'failure_confirmations',
            'recovery_confirmations',
            'stale_after_seconds',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('monitors', [
            'tenant_id',
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
            'tenant_id',
            'monitor_id',
            'source_key',
            'state',
            'observed_at',
            'ingested_at',
        ]))->toBeTrue();
});

it('relates a typed monitor to its tenant device profile collector and observations', function () {
    $device = Device::factory()->itInfrastructure()->create(['tenant_id' => 42]);
    $collector = MonitoringCollector::create([
        'tenant_id' => 42,
        'collector_uuid' => fake()->uuid(),
        'name' => 'Remote site collector',
        'status' => 'online',
        'last_seen_at' => now(),
        'config' => ['buffering' => true],
    ]);
    $profile = MonitoringProfile::create([
        'tenant_id' => 42,
        'name' => 'Core network availability',
        'interval_seconds' => 30,
        'failure_confirmations' => 3,
        'recovery_confirmations' => 2,
        'stale_after_seconds' => 180,
        'is_active' => true,
    ]);
    $monitor = Monitor::create([
        'tenant_id' => 42,
        'device_id' => $device->id,
        'profile_id' => $profile->id,
        'collector_id' => $collector->id,
        'kind' => MonitorKind::Icmp,
        'name' => 'Gateway reachability',
        'target' => '10.42.0.1',
        'current_state' => MonitorState::Unknown,
        'affects_availability' => true,
    ]);
    $observation = $monitor->observations()->create([
        'tenant_id' => 42,
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
        ->and($monitor->observations()->sole()->is($observation))->toBeTrue()
        ->and(Monitor::forTenant(42)->sole()->is($monitor))->toBeTrue()
        ->and(Monitor::forTenant(7)->doesntExist())->toBeTrue();
});
