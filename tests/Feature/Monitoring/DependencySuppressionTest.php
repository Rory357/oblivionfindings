<?php

use App\Domain\Monitoring\Data\ObservationInput;
use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorDependency;
use App\Domain\Monitoring\Services\DependencyEvaluator;
use App\Domain\Monitoring\Services\MonitoringObservationIngestor;
use App\Domain\SecurityDevices\Enums\AssignmentType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Models\ControlRoom\Device as ControlRoomDevice;
use App\Models\ControlRoomAlert;
use App\Models\ItTicket;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Database\Seeders\SecurityDevicesSignalSeeder;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-07-23T13:00:00Z');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

it('keeps a downstream symptom visible but suppresses its alert and ticket behind one root cause', function () {
    $site = dependencySite();
    $wanDevice = dependencyDevice($site, ['name' => 'WAN gateway']);
    $cameraDevice = dependencyDevice($site, ['name' => 'Camera']);
    $wan = Monitor::factory()->create([
        'device_id' => $wanDevice->id,
        'current_state' => MonitorState::Failed,
        'effective_state' => MonitorState::Failed,
        'affects_availability' => true,
    ]);
    $camera = Monitor::factory()->create([
        'device_id' => $cameraDevice->id,
        'current_state' => MonitorState::Failed,
        'effective_state' => MonitorState::Failed,
        'affects_availability' => true,
    ]);
    MonitorDependency::query()->create([
        'site_id' => $site->id,
        'upstream_monitor_id' => $wan->id,
        'downstream_monitor_id' => $camera->id,
        'policy' => 'suppress_notifications_and_ticketing',
        'source' => 'manual',
        'confidence' => 1,
        'is_active' => true,
    ]);

    $result = app(DependencyEvaluator::class)->evaluate($camera, now());

    expect($result->effectiveState)->toBe(MonitorState::Suppressed)
        ->and($result->underlyingState)->toBe(MonitorState::Failed)
        ->and($result->rootCauseMonitorId)->toBe($wan->id)
        ->and($result->symptomVisible)->toBeTrue();
});

it('rejects cycles and cross Site dependencies and ignores weak inferred edges', function () {
    $site = dependencySite();
    $otherSite = dependencySite();
    $a = Monitor::factory()->create(['device_id' => dependencyDevice($site)->id, 'current_state' => MonitorState::Failed]);
    $b = Monitor::factory()->create(['device_id' => dependencyDevice($site)->id, 'current_state' => MonitorState::Failed]);
    $foreign = Monitor::factory()->create(['device_id' => dependencyDevice($otherSite)->id]);

    MonitorDependency::query()->create([
        'site_id' => $site->id,
        'upstream_monitor_id' => $a->id,
        'downstream_monitor_id' => $b->id,
        'policy' => 'suppress_notifications_and_ticketing',
        'source' => 'manual',
        'confidence' => 1,
        'is_active' => true,
    ]);

    expect(fn () => MonitorDependency::query()->create([
        'site_id' => $site->id,
        'upstream_monitor_id' => $b->id,
        'downstream_monitor_id' => $a->id,
        'policy' => 'suppress_notifications_and_ticketing',
        'source' => 'manual',
        'confidence' => 1,
        'is_active' => true,
    ]))->toThrow(LogicException::class, 'cycle')
        ->and(fn () => MonitorDependency::query()->create([
            'site_id' => $site->id,
            'upstream_monitor_id' => $a->id,
            'downstream_monitor_id' => $foreign->id,
            'policy' => 'suppress_notifications_and_ticketing',
            'source' => 'manual',
            'confidence' => 1,
            'is_active' => true,
        ]))->toThrow(UnexpectedValueException::class, 'same canonical Site');

    $weak = MonitorDependency::query()->create([
        'site_id' => $site->id,
        'upstream_monitor_id' => $a->id,
        'downstream_monitor_id' => Monitor::factory()->create([
            'device_id' => dependencyDevice($site)->id,
            'current_state' => MonitorState::Failed,
        ])->id,
        'policy' => 'suppress_notifications_and_ticketing',
        'source' => 'topology',
        'confidence' => 0.7,
        'is_active' => true,
    ]);
    $downstream = Monitor::query()->findOrFail($weak->downstream_monitor_id);

    expect(app(DependencyEvaluator::class)->evaluate($downstream, now())->effectiveState)
        ->toBe(MonitorState::Failed);

    $weak->forceFill(['confidence' => 0.9])->save();
    expect(app(DependencyEvaluator::class)->evaluate($downstream, now())->effectiveState)
        ->toBe(MonitorState::Suppressed);
});

it('persists a suppressed symptom and root cause without emitting a duplicate availability event', function () {
    $site = dependencySite();
    $upstream = Monitor::factory()->create([
        'device_id' => dependencyDevice($site)->id,
        'current_state' => MonitorState::Failed,
        'effective_state' => MonitorState::Failed,
    ]);
    $downstream = Monitor::factory()->create([
        'device_id' => dependencyDevice($site)->id,
        'current_state' => MonitorState::Healthy,
        'effective_state' => MonitorState::Healthy,
        'affects_availability' => true,
    ]);
    $downstream->profile->forceFill(['failure_confirmations' => 1])->save();
    MonitorDependency::query()->create([
        'site_id' => $site->id,
        'upstream_monitor_id' => $upstream->id,
        'downstream_monitor_id' => $downstream->id,
        'policy' => 'suppress_notifications_and_ticketing',
        'source' => 'manual',
        'confidence' => 1,
        'is_active' => true,
    ]);

    $result = app(MonitoringObservationIngestor::class)->ingest(
        $downstream->fresh('profile'),
        dependencyObservation('symptom-failed', MonitorState::Failed),
        $site->id,
        $downstream->device_id,
        null,
    );

    expect($result->stateChanged)->toBeTrue()
        ->and($downstream->fresh()->current_state)->toBe(MonitorState::Failed)
        ->and($downstream->fresh()->effective_state)->toBe(MonitorState::Suppressed)
        ->and($downstream->fresh()->root_cause_monitor_id)->toBe($upstream->id)
        ->and($downstream->observations()->count())->toBe(1)
        ->and(DeviceEvent::query()->where('device_id', $downstream->device_id)->count())->toBe(0);
});

it('recovers only the matching Control Room alert and IT incident when one of two root monitors recovers', function () {
    config()->set('queue.default', 'sync');
    $this->seed(SecurityDevicesSignalSeeder::class);
    $site = dependencySite();
    $device = dependencyDevice($site, ['name' => 'Shared edge appliance']);
    $projection = ControlRoomDevice::query()->create([
        'name' => $device->name,
        'canonical_device_id' => $device->id,
        'site_id' => $site->id,
        'type' => ControlRoomDevice::TYPE_NETWORK,
    ]);
    $first = Monitor::factory()->create([
        'device_id' => $device->id,
        'kind' => MonitorKind::Icmp,
        'current_state' => MonitorState::Healthy,
        'effective_state' => MonitorState::Healthy,
        'affects_availability' => true,
    ]);
    $second = Monitor::factory()->create([
        'device_id' => $device->id,
        'kind' => MonitorKind::Tcp,
        'current_state' => MonitorState::Healthy,
        'effective_state' => MonitorState::Healthy,
        'affects_availability' => true,
    ]);
    foreach ([$first, $second] as $monitor) {
        $monitor->profile->forceFill(['failure_confirmations' => 1, 'recovery_confirmations' => 1])->save();
        app(MonitoringObservationIngestor::class)->ingest(
            $monitor->fresh('profile'),
            dependencyObservation("failure-{$monitor->id}", MonitorState::Failed),
            $site->id,
            $device->id,
            null,
        );
    }

    $events = DeviceEvent::query()->where('device_id', $device->id)->where('event_type', 'offline')->orderBy('id')->get();
    $keys = $events->pluck('payload.monitor_correlation_key');

    expect($events)->toHaveCount(2)
        ->and($keys->unique())->toHaveCount(2)
        ->and(ControlRoomAlert::query()->where('device_id', $projection->id)->count())->toBe(2)
        ->and(ItTicket::query()->count())->toBe(2);

    app(MonitoringObservationIngestor::class)->ingest(
        $first->fresh('profile'),
        dependencyObservation('first-recovered', MonitorState::Healthy),
        $site->id,
        $device->id,
        null,
    );

    $resolvedAlerts = ControlRoomAlert::query()->where('device_id', $projection->id)->where('status', ControlRoomAlert::STATUS_RESOLVED)->get();
    $recoveredTickets = ItTicket::query()->whereNotNull('monitoring_recovered_at')->get();
    $recoveryEvent = $recoveredTickets->first()?->events()->where('type', 'monitoring_recovered')->first();

    expect($resolvedAlerts)->toHaveCount(1)
        ->and(data_get($resolvedAlerts->first()?->context, 'normalized_data.monitor_correlation_key'))->toBe($keys->first())
        ->and($recoveredTickets)->toHaveCount(1)
        ->and(data_get($recoveryEvent?->payload, 'monitor_correlation_key'))
        ->toBe($keys->first());
});

function dependencyObservation(string $key, MonitorState $state): ObservationInput
{
    return new ObservationInput(
        sourceKey: $key,
        state: $state,
        observedAt: CarbonImmutable::now('UTC'),
        message: $state === MonitorState::Failed ? 'dependency_probe_failed' : 'dependency_probe_ok',
    );
}

function dependencySite(): Site
{
    return Site::factory()->create([
        'is_active' => true,
        'archived' => false,
        'archived_at' => null,
    ]);
}

/** @param array<string, mixed> $attributes */
function dependencyDevice(Site $site, array $attributes = []): Device
{
    $device = Device::factory()->itInfrastructure()->create($attributes);
    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id,
        'assignment_type' => AssignmentType::Permanent,
        'assigned_at' => now()->subMinute(),
        'released_at' => null,
    ]);

    return $device;
}
