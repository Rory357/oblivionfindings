<?php

use App\Events\FleetSignalEmitted;
use App\Jobs\DetectFleetOfflineDevices;
use App\Jobs\DispatchFleetSignalOutbox;
use App\Models\Asset;
use App\Models\FleetSignal;
use App\Models\FleetSignalOutbox;
use App\Models\FleetVehicleStateSnapshot;
use App\Services\Fleet\FleetSignalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

function offlineDetectionState(): FleetVehicleStateSnapshot
{
    return FleetVehicleStateSnapshot::query()->create([
        'asset_id' => Asset::factory()->vehicle()->create()->id,
        'status' => 'online',
        'last_seen_at' => now()->subMinutes(20),
    ]);
}

beforeEach(function () {
    $this->freezeTime();
    config()->set('fleet.signals.offline_after_minutes', 15);
    Queue::fake();
    Event::fake([FleetSignalEmitted::class]);
});

afterEach(function () {
    $this->travelBack();
});

it('rejects invalid offline configuration before changing state or publishing', function ($minutes) {
    $state = offlineDetectionState();
    config()->set('fleet.signals.offline_after_minutes', $minutes);

    expect(fn () => (new DetectFleetOfflineDevices)->handle(app(FleetSignalService::class)))
        ->toThrow(InvalidArgumentException::class, 'Fleet offline timeout must be a positive whole number of minutes.');
    expect($state->fresh()->status)->toBe('online')
        ->and(FleetSignal::query()->count())->toBe(0)
        ->and(FleetSignalOutbox::query()->count())->toBe(0);
    Queue::assertNothingPushed();
    Event::assertNotDispatched(FleetSignalEmitted::class);
})->with([
    'missing' => [null], 'zero' => [0], 'negative' => [-1],
    'boolean true' => [true], 'boolean false' => [false],
    'fraction' => [1.5], 'empty string' => [''], 'units' => ['15 minutes'],
    'integer overflow' => ['999999999999999999999999'], 'array' => [[]],
]);

it('accepts an environment integer string without changing the configured cutoff', function () {
    $state = offlineDetectionState();
    config()->set('fleet.signals.offline_after_minutes', '30');
    (new DetectFleetOfflineDevices)->handle(app(FleetSignalService::class));
    expect($state->fresh()->status)->toBe('online')->and(FleetSignal::query()->count())->toBe(0);
    $this->travel(11)->minutes();
    (new DetectFleetOfflineDevices)->handle(app(FleetSignalService::class));
    expect($state->fresh()->status)->toBe('offline')->and(FleetSignal::query()->count())->toBe(1);
});

it('commits one offline source and outbox before dispatch and ignores repeat sweeps', function () {
    $state = offlineDetectionState();
    $detector = new DetectFleetOfflineDevices;
    $detector->handle(app(FleetSignalService::class));
    $detector->handle(app(FleetSignalService::class));

    $signal = FleetSignal::query()->sole();
    expect($state->fresh()->status)->toBe('offline')
        ->and($signal->signal_type)->toBe('device.offline')
        ->and($signal->payload['last_seen_at'])->toBe($state->last_seen_at->toISOString())
        ->and($signal->payload['availability']['scope'])->toBeNull()
        ->and($signal->payload['availability']['episode_key'])->toBe($signal->idempotency_key)
        ->and((int) FleetSignalOutbox::query()->sole()->fleet_signal_id)->toBe((int) $signal->id);
    Queue::assertPushed(DispatchFleetSignalOutbox::class, 1);
    Event::assertDispatched(FleetSignalEmitted::class, 1);
});

it('rolls back the offline transition if the durable outbox cannot be saved and permits retry', function () {
    $state = offlineDetectionState();
    $event = 'eloquent.creating: '.FleetSignalOutbox::class;
    $injectFailure = true;
    Event::listen($event, function () use (&$injectFailure) {
        if ($injectFailure) {
            throw new RuntimeException('injected outbox failure');
        }
    });
    try {
        expect(fn () => (new DetectFleetOfflineDevices)->handle(app(FleetSignalService::class)))
            ->toThrow(RuntimeException::class, 'injected outbox failure');
    } finally {
        $injectFailure = false;
    }
    expect($state->fresh()->status)->toBe('online')
        ->and(FleetSignal::query()->count())->toBe(0)
        ->and(FleetSignalOutbox::query()->count())->toBe(0);
    Queue::assertNothingPushed();
    Event::assertNotDispatched(FleetSignalEmitted::class);

    (new DetectFleetOfflineDevices)->handle(app(FleetSignalService::class));
    expect($state->fresh()->status)->toBe('offline')
        ->and(FleetSignal::query()->count())->toBe(1)
        ->and(FleetSignalOutbox::query()->count())->toBe(1);
});

it('does not dispatch source work when the enclosing caller rolls back', function () {
    $state = offlineDetectionState();
    DB::beginTransaction();
    try {
        (new DetectFleetOfflineDevices)->handle(app(FleetSignalService::class));
        expect($state->fresh()->status)->toBe('offline');
        Queue::assertNothingPushed();
        Event::assertNotDispatched(FleetSignalEmitted::class);
    } finally {
        DB::rollBack();
    }
    expect($state->fresh()->status)->toBe('online')
        ->and(FleetSignal::query()->count())->toBe(0)
        ->and(FleetSignalOutbox::query()->count())->toBe(0);
    Queue::assertNothingPushed();
    Event::assertNotDispatched(FleetSignalEmitted::class);
});

it('waits for the enclosing caller commit before dispatching the signal and outbox job', function () {
    offlineDetectionState();
    DB::transaction(function () {
        (new DetectFleetOfflineDevices)->handle(app(FleetSignalService::class));
        Queue::assertNothingPushed();
        Event::assertNotDispatched(FleetSignalEmitted::class);
        expect(FleetSignal::query()->count())->toBe(1);
    });
    Queue::assertPushed(DispatchFleetSignalOutbox::class, 1);
    Event::assertDispatched(FleetSignalEmitted::class, 1);
});

it('rechecks a candidate after a fresh heartbeat arrives during the scan', function () {
    $state = offlineDetectionState();
    $event = 'eloquent.retrieved: '.FleetVehicleStateSnapshot::class;
    $refreshed = false;
    Event::listen($event, function (FleetVehicleStateSnapshot $candidate) use ($state, &$refreshed) {
        if (! $refreshed && (int) $candidate->asset_id === (int) $state->asset_id) {
            $refreshed = true;
            DB::table('fleet_vehicle_state_snapshots')->where('asset_id', $state->asset_id)
                ->update(['last_seen_at' => now()]);
        }
    });
    (new DetectFleetOfflineDevices)->handle(app(FleetSignalService::class));
    expect($refreshed)->toBeTrue()
        ->and($state->fresh()->status)->toBe('online')
        ->and(FleetSignal::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('retains the configured cutoff and creates a new source only after a later heartbeat episode', function () {
    $state = offlineDetectionState();
    $state->update(['last_seen_at' => now()->subMinutes(15)]);
    $detector = new DetectFleetOfflineDevices;
    $detector->handle(app(FleetSignalService::class));
    expect($state->fresh()->status)->toBe('online');

    $this->travel(1)->seconds();
    $detector->handle(app(FleetSignalService::class));
    $firstKey = FleetSignal::query()->sole()->idempotency_key;
    $state->refresh()->update(['status' => 'online', 'last_seen_at' => now()]);
    $this->travel(16)->minutes();
    $detector->handle(app(FleetSignalService::class));
    expect($state->fresh()->status)->toBe('offline')
        ->and(FleetSignal::query()->count())->toBe(2)
        ->and(FleetSignal::query()->where('idempotency_key', '!=', $firstKey)->count())->toBe(1)
        ->and(FleetSignalOutbox::query()->count())->toBe(2);
});
