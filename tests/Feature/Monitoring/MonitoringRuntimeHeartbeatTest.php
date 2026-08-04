<?php

use App\Domain\Monitoring\Jobs\RuntimeQueueHeartbeat;
use App\Domain\Monitoring\Models\MonitoringRuntimeHeartbeat;
use App\Domain\Monitoring\Services\ListenerHeartbeatReporter;
use App\Domain\Monitoring\Services\MonitoringRuntimeHealthService;
use App\Domain\Monitoring\Services\MonitoringRuntimeHeartbeatService;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('dispatches one durable canary to every isolated runtime queue', function () {
    Queue::fake();
    CarbonImmutable::setTestNow('2026-07-27T10:00:00Z');
    $service = app(MonitoringRuntimeHeartbeatService::class);

    expect($service->dispatch())->toBe(8)
        ->and(MonitoringRuntimeHeartbeat::query()->count())->toBe(8)
        ->and($service->components())->toBe([
            'events' => 'monitoring-events',
            'checks' => 'monitoring-checks',
            'discovery' => 'monitoring-discovery',
            'provider' => 'monitoring-provider',
            'topology' => 'monitoring-topology',
            'maintenance' => 'monitoring-maintenance',
            'orchestration' => 'monitoring',
            'commands' => 'monitoring-commands',
        ]);

    Queue::assertPushed(RuntimeQueueHeartbeat::class, 8);
    foreach ($service->components() as $component => $queue) {
        Queue::assertPushed(RuntimeQueueHeartbeat::class, fn (RuntimeQueueHeartbeat $job): bool => $job->component === $component
            && $job->queueName === $queue
            && $job->queue === $queue
            && $job->connection === 'redis');
    }
});

it('acknowledges only the current component and queue dispatch token', function () {
    Queue::fake();
    CarbonImmutable::setTestNow('2026-07-27T10:00:00Z');
    $service = app(MonitoringRuntimeHeartbeatService::class);
    $service->dispatch();
    $first = Queue::pushed(RuntimeQueueHeartbeat::class)
        ->first(fn (RuntimeQueueHeartbeat $job): bool => $job->component === 'checks');

    CarbonImmutable::setTestNow('2026-07-27T10:01:00Z');
    $service->dispatch();
    $second = Queue::pushed(RuntimeQueueHeartbeat::class)
        ->last(fn (RuntimeQueueHeartbeat $job): bool => $job->component === 'checks');

    $first->handle($service);
    expect(MonitoringRuntimeHeartbeat::query()->where('component', 'checks')->firstOrFail()->last_consumed_at)
        ->toBeNull();

    $second->handle($service);
    $heartbeat = MonitoringRuntimeHeartbeat::query()->where('component', 'checks')->firstOrFail();
    expect($heartbeat->last_consumed_token)->toBe($second->dispatchToken)
        ->and($heartbeat->last_consumed_dispatch_at->toIso8601String())->toBe('2026-07-27T10:01:00+00:00')
        ->and($heartbeat->last_consumed_at->toIso8601String())->toBe('2026-07-27T10:01:00+00:00');
});

it('reports current and stale worker health without exposing queue counts to scoped viewers', function () {
    Queue::fake();
    CarbonImmutable::setTestNow('2026-07-27T10:00:00Z');
    $heartbeatService = app(MonitoringRuntimeHeartbeatService::class);
    $heartbeatService->dispatch();
    Queue::pushed(RuntimeQueueHeartbeat::class)
        ->each(fn (RuntimeQueueHeartbeat $job) => $job->handle($heartbeatService));

    $viewer = User::factory()->create();
    $access = Mockery::mock(SecurityDevicesAccessService::class);
    $access->shouldReceive('canViewAllSites')->twice()->with($viewer)->andReturn(false);
    $access->shouldReceive('accessibleSiteIds')->twice()->with($viewer)->andReturn([]);
    $access->shouldReceive('visibleDevices')->twice()->with($viewer)->andReturnUsing(
        fn () => Device::query()->whereRaw('1 = 0'),
    );
    $health = new MonitoringRuntimeHealthService(
        $access,
        app(ListenerHeartbeatReporter::class),
    );

    $current = $health->present($viewer);
    expect($current['workers'])->toMatchArray([
        'state' => 'available',
        'available' => 8,
        'total' => 8,
        'attention' => 0,
        'not_observed' => 0,
    ])->and($current['queues']['commands'])->toMatchArray([
        'state' => 'scope_restricted',
        'pending' => null,
        'worker_state' => 'available',
        'heartbeat_age_seconds' => 0,
    ]);

    CarbonImmutable::setTestNow('2026-07-27T10:03:01Z');
    $stale = $health->present($viewer);
    expect($stale['workers'])->toMatchArray([
        'state' => 'attention',
        'available' => 0,
        'attention' => 8,
    ])->and($stale['queues']['checks']['worker_state'])->toBe('stale')
        ->and($stale['queues']['checks']['pending'])->toBeNull();
});
