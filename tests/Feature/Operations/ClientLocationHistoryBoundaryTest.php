<?php

use App\Services\Integration\IntegrationEventHistoryService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\Support\ClientLocationWorkspaceFixture;

it('excludes prior, expired, unclocked and future device positions from the initial profile', function () {
    extract(ClientLocationWorkspaceFixture::make());
    $this->actingAs($actor);
    foreach ([now()->subDays(2)->toISOString(), now()->subDays(31)->toISOString(), null, 'invalid', now()->addDay()->toISOString()] as $timestamp) {
        $device->update(['latitude' => -36.85, 'longitude' => 174.76, 'meta' => ['lat' => -36.85, 'lng' => 174.76, 'last_location_at' => $timestamp, 'accuracy' => 9]]);
        $this->get(route('operations.clients.show', $client, false))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('location.currentLocation', null)->where('location.tracker.last_location_at', null));
    }
});

it('keeps coordinates time and quality together and never attaches an unrelated address', function () {
    extract(ClientLocationWorkspaceFixture::make());
    $observedAt = now()->subMinute()->startOfSecond();
    $device->update(['latitude' => -36.85, 'longitude' => 174.76, 'meta' => ['lat' => -36.85, 'lng' => 174.76, 'last_location_at' => $observedAt->toISOString(), 'accuracy' => 9]]);
    $this->mock(IntegrationEventHistoryService::class)->shouldReceive('forDevice')->andReturn(collect([
        ['lat' => -36.90, 'lng' => 174.80, 'address' => 'An older different place', 'timestamp' => now()->subHour()->toISOString(), 'speed' => null],
    ]));
    $this->actingAs($actor)->get(route('operations.clients.show', $client, false))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('location.currentLocation.lat', -36.85)->where('location.currentLocation.accuracy', 9)
        ->where('location.currentLocation.address', null)->where('location.tracker.last_location_at', $observedAt->toISOString()));
    $device->update(['latitude' => -37]);
    $this->get(route('operations.clients.show', $client, false))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('location.currentLocation.lat', -36.90)->where('location.currentLocation.address', 'An older different place')
        ->where('location.currentLocation.accuracy', null));
});

it('applies retention to a position collected during the current assignment', function () {
    extract(ClientLocationWorkspaceFixture::make());
    $assignment->update(['assigned_at' => now()->subDays(3), 'collection_started_at' => now()->subDays(3), 'retention_days' => 1]);
    $device->update(['latitude' => -36.85, 'longitude' => 174.76, 'meta' => ['lat' => -36.85, 'lng' => 174.76, 'last_location_at' => now()->subDays(2)->toISOString()]]);
    $this->actingAs($actor)->get(route('operations.clients.show', $client, false))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('location.currentLocation', null)->where('location.tracker.last_location_at', null));
});

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
});

it('bounds observations to the current collection start and validates actual dates', function () {
    extract(ClientLocationWorkspaceFixture::make());
    $this->mock(IntegrationEventHistoryService::class)->shouldReceive('forDevice')->once()->withArgs(function ($actual, $filters, $eventType, $retention) use ($device, $assignment) {
        expect($actual->id)->toBe($device->id)->and($filters['date_from'])->toBe($assignment->collection_started_at->utc()->toDateTimeString())->and($retention)->toBe(30);

        return true;
    })->andReturn(collect([
        ['timestamp' => now()->subDays(2)->toISOString(), 'lat' => 1, 'lng' => 1],
        ['timestamp' => now()->subHour()->toISOString(), 'lat' => 2, 'lng' => 2],
    ]));
    $url = "/operations/clients/{$client->id}/location/history";
    $this->actingAs($actor)->getJson($url)->assertOk()->assertJsonCount(1, 'locations')->assertJsonPath('locations.0.lat', 2)->assertJsonPath('access_fingerprint', $fingerprint);
    $this->getJson($url.'?date_from=2026-02-30')->assertUnprocessable();
    $this->getJson($url.'?date_from=2026-09-20&date_to=2026-09-19')->assertUnprocessable();
});

it('discards a history read when collection ends while data is being read', function () {
    extract(ClientLocationWorkspaceFixture::make());
    $this->mock(IntegrationEventHistoryService::class)->shouldReceive('forDevice')->once()->andReturnUsing(function () use ($assignment) {
        $assignment->update(['collection_stopped_at' => now()]);

        return collect([['timestamp' => now()->subHour()->toISOString(), 'lat' => 1, 'lng' => 1]]);
    });
    $this->actingAs($actor)->getJson("/operations/clients/{$client->id}/location/history")->assertForbidden()->assertJsonMissingPath('locations');
});

it('converts Auckland calendar dates before querying the canonical history service', function () {
    $this->travelTo(Carbon::parse('2026-09-21T12:00:00Z'));
    extract(ClientLocationWorkspaceFixture::make());
    $assignment->update(['assigned_at' => now()->subDays(2), 'collection_started_at' => now()->subDays(2)]);
    $this->mock(IntegrationEventHistoryService::class)->shouldReceive('forDevice')->once()->withArgs(function ($device, $filters) {
        expect($filters)->toBe(['date_from' => '2026-09-20 12:00:00', 'date_to' => '2026-09-21 11:59:59']);

        return true;
    })->andReturn(collect());
    $this->actingAs($actor)->getJson("/operations/clients/{$client->id}/location/history?date_from=2026-09-21&date_to=2026-09-21")->assertOk();
});
