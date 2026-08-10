<?php

use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Site;
use App\Support\LegacyStorageContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

it('reconciles fully null compatibility rows and proves zero provenance gaps', function () {
    $monitor = Monitor::factory()->create();
    $site = Site::factory()->create();
    DeviceAssignment::create([
        'device_id' => $monitor->device_id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id,
        'assignment_type' => 'permanent',
        'assigned_at' => now(),
    ]);
    $collector = MonitoringCollector::factory()->create(['site_id' => $site->id]);
    $monitor->forceFill(['collector_id' => $collector->id])->save();
    $observationId = DB::table('monitor_observations')->insertGetId([
        ...LegacyStorageContext::attributes(),
        'monitor_id' => $monitor->id,
        'source_key' => 'old-worker-null-provenance',
        'state' => MonitorState::Healthy->value,
        'observed_at' => now(),
        'ingested_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(Artisan::call('monitoring:reconcile-observation-provenance', ['--chunk' => 1]))->toBe(0);

    $observation = DB::table('monitor_observations')->find($observationId);
    expect($observation->device_id)->toBe($monitor->device_id)
        ->and($observation->site_id)->toBe($site->id)
        ->and($observation->collector_id)->toBe($collector->id)
        ->and(Artisan::output())->toContain('zero gaps');
});

it('fails closed without mutating partial or contradictory provenance', function () {
    $monitor = Monitor::factory()->create();
    $site = Site::factory()->create();
    $otherSite = Site::factory()->create();
    DeviceAssignment::create([
        'device_id' => $monitor->device_id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id,
        'assignment_type' => 'permanent',
        'assigned_at' => now(),
    ]);
    $base = [
        ...LegacyStorageContext::attributes(),
        'monitor_id' => $monitor->id,
        'state' => MonitorState::Healthy->value,
        'observed_at' => now(),
        'ingested_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ];
    $partialId = DB::table('monitor_observations')->insertGetId([
        ...$base,
        'source_key' => 'partial-provenance',
        'device_id' => $monitor->device_id,
    ]);
    $contradictoryId = DB::table('monitor_observations')->insertGetId([
        ...$base,
        'source_key' => 'contradictory-provenance',
        'device_id' => $monitor->device_id,
        'site_id' => $otherSite->id,
    ]);

    expect(Artisan::call('monitoring:reconcile-observation-provenance'))->toBe(1)
        ->and(Artisan::output())->toContain('did not reach zero gaps')
        ->and(DB::table('monitor_observations')->find($partialId)->site_id)->toBeNull()
        ->and(DB::table('monitor_observations')->find($contradictoryId)->site_id)->toBe($otherSite->id);
});
