<?php

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Models\Asset;
use App\Models\FleetTelemetryEvent;
use App\Services\Tracking\ClientTrackerStatusService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Support\ClientLocationWorkspaceFixture;

it('preserves panic evidence without coercing missing or malformed states to a reported clear status', function () {
    Http::preventStrayRequests();
    Queue::fake();
    extract(ClientLocationWorkspaceFixture::make());
    $this->actingAs($actor);
    foreach ([null, false, true, 'false', 'yes', 2] as $state) {
        $device->update(['meta' => ['panic_active' => $state]]);
        $this->get("/operations/clients/{$client->id}?tab=location")->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('location.tracker.panic_active', is_bool($state) ? $state : null)
                ->where('location.tracker.panic_acknowledged_at', null));
    }
    $ack = now()->toISOString();
    $device->update(['meta' => ['panic_active' => false, 'panic_acknowledged_at' => $ack]]);
    $this->get("/operations/clients/{$client->id}?tab=location")->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('location.tracker.panic_active', false)
            ->where('location.tracker.panic_acknowledged_at', $ack));
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('only presents motion with a valid report in the current collection period', function () {
    Http::preventStrayRequests();
    Queue::fake();
    extract(ClientLocationWorkspaceFixture::make());
    $this->actingAs($actor);
    $time = now()->subMinute()->startOfSecond()->toISOString();
    foreach (['moving' => 'moving', 'motion' => 'moving', 'rest' => 'stationary', 'stationary' => 'stationary', 'false' => null] as $raw => $expected) {
        $device->update(['meta' => ['motion' => $raw, 'last_location_at' => $time]]);
        $this->get("/operations/clients/{$client->id}?tab=location")->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('location.tracker.motion_status', $expected)
                ->where('location.tracker.motion_reported_at', $expected ? $time : null)
                ->where('location.tracker.fall_report_type', null));
    }
    foreach ([null, 'invalid', now()->addMinute()->toISOString(), now()->subDays(2)->toISOString()] as $badTime) {
        $device->update(['meta' => ['motion' => 'moving', 'last_location_at' => $badTime]]);
        expect(app(ClientTrackerStatusService::class)->read($actor, $client)['motion_status'])->toBeNull();
    }
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('bounds fall and movement reports to the assigned device and collection window without inventing a sensor state', function () {
    Http::preventStrayRequests();
    Queue::fake();
    extract(ClientLocationWorkspaceFixture::make());
    $service = app(ClientTrackerStatusService::class);
    $empty = ['motion_status' => null, 'motion_reported_at' => null, 'fall_report_type' => null, 'fall_reported_at' => null];
    expect($service->read($actor, $client))->toBe($empty);
    foreach ([now()->subDays(2), now()->addHour()] as $excluded) {
        DeviceEvent::withoutEvents(fn () => DeviceEvent::query()->create([
            'device_id' => $device->id, 'event_type' => 'fall_detected', 'severity' => 'critical',
            'payload' => [], 'source' => 'synthetic-test', 'occurred_at' => $excluded,
        ]));
    }
    $otherDevice = Device::factory()->tracking()->create();
    DeviceEvent::withoutEvents(fn () => DeviceEvent::query()->create([
        'device_id' => $otherDevice->id, 'event_type' => 'fall_detected', 'severity' => 'critical',
        'payload' => [], 'source' => 'synthetic-test', 'occurred_at' => now()->subMinute(),
    ]));
    expect($service->read($actor, $client))->toBe($empty);
    $asset = Asset::factory()->create(['site_id' => $site->id]);
    $time = now()->subMinutes(5)->startOfSecond();
    $report = FleetTelemetryEvent::query()->create([
        'asset_id' => $asset->id, 'device_id' => $device->id, 'vendor' => 'synthetic',
        'occurred_at' => $time, 'received_at' => now(), 'motion_status' => 'motion',
        'event_type' => 'man_down', 'consent_blocked' => false, 'idempotency_key' => (string) Str::uuid(),
    ]);
    expect($service->read($actor, $client))->toBe([
        'motion_status' => 'moving', 'motion_reported_at' => $time->toISOString(),
        'fall_report_type' => 'man_down', 'fall_reported_at' => $time->toISOString(),
    ]);
    $report->update(['consent_blocked' => true]);
    expect($service->read($actor, $client))->toBe($empty);
    DeviceEvent::withoutEvents(fn () => DeviceEvent::query()->create([
        'device_id' => $device->id, 'event_type' => 'fall_detected', 'severity' => 'critical',
        'payload' => [], 'source' => 'synthetic-test', 'occurred_at' => $time,
    ]));
    expect($service->read($actor, $client)['fall_report_type'])->toBe('fall_detected');
    $assignment->update(['collection_started_at' => now()]);
    expect($service->read($actor, $client))->toBe($empty);
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});
