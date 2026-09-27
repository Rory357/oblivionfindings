<?php

use App\Domain\SecurityDevices\Models\Device;
use App\Models\Asset;
use App\Models\AssetGeofence;
use App\Models\AuditLog;
use App\Models\ControlRoomAlert;
use App\Models\FleetResidentTransport;
use App\Models\FleetTelemetryEvent;
use App\Models\LoneWorkerSession;
use App\Models\Permission;
use App\Models\User;
use App\Services\Integration\IntegrationEventHistoryService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Support\ClientLocationWorkspaceFixture;

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
});

function peopleFixture(...$arguments): array
{
    $fixture = ClientLocationWorkspaceFixture::make(...$arguments);
    grantPeoplePermission($fixture['actor'], 'people_locations.history.view');

    return $fixture;
}

function grantPeoplePermission(User $actor, string $key): void
{
    $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'test', 'module' => 'Test']);
    $actor->roles()->first()->permissions()->syncWithoutDetaching([$permission->id]);
    $actor->unsetRelation('roles');
}

function peoplePoint($device): void
{
    $device->update(['latitude' => -36.85, 'longitude' => 174.76, 'last_seen_at' => now(),
        'meta' => ['lat' => -36.85, 'lng' => 174.76, 'last_location_at' => now()->subMinute()->toISOString(), 'accuracy' => 12]]);
}

it('uses canonical consent and Site access before exposing people or counts', function () {
    extract(peopleFixture());
    $other = peopleFixture();
    peoplePoint($device);
    $this->actingAs($actor)->getJson('/operations/people-locations/map')->assertOk()
        ->assertJsonCount(1, 'people')->assertJsonPath('people.0.id', 'c'.$client->id)
        ->assertJsonPath('people.0.positionState', 'recent')->assertJsonCount(1, 'sites')
        ->assertDontSee($other['client']->full_name);
    $consent->update(['withdrawn_at' => now()]);
    $this->getJson('/operations/people-locations/map')->assertOk()->assertJsonCount(0, 'people')->assertJsonCount(0, 'sites');
});

it('denies callers lacking telemetry access and does not grant client permission from Fleet', function () {
    $actor = User::factory()->create();
    $this->actingAs($actor)->getJson('/operations/people-locations/map')->assertForbidden();
});

it('never treats last contact as a fresh position or default false as observed power', function () {
    extract(peopleFixture());
    $device->update(['latitude' => -36.85, 'longitude' => 174.76, 'last_seen_at' => now(),
        'battery_level' => 90, 'meta' => ['lat' => -36.85, 'lng' => 174.76, 'last_location_at' => now()->subHour()->toISOString(), 'charging_status' => 'charging']]);
    $this->actingAs($actor)->getJson('/operations/people-locations/people')->assertOk()
        ->assertJsonPath('people.0.positionState', 'stale')->assertJsonPath('people.0.battery', null)
        ->assertJsonPath('people.0.power', 'unknown');
});

it('requires a deliberate source choice when two authorised trackers exist', function () {
    extract(peopleFixture());
    $second = Device::factory()->tracking()->create();
    $copy = $assignment->replicate();
    $copy->device_id = $second->id;
    $copy->save();
    peoplePoint($device);
    $this->actingAs($actor)->getJson('/operations/people-locations/history?selected=c'.$client->id)->assertOk()
        ->assertJsonCount(2, 'people.0.sources')->assertJsonPath('people.0.position', null)->assertJsonPath('history.needsSource', true);
    $this->getJson('/operations/people-locations/history?selected=c'.$client->id.'&source='.$assignment->id)->assertOk()
        ->assertJsonPath('people.0.position.lat', -36.85)->assertJsonPath('history.source.id', (string) $assignment->id);
    $this->getJson('/operations/people-locations/history?selected=c'.$client->id.'&source=999999')->assertOk()
        ->assertJsonPath('people.0.position', null)->assertJsonPath('history.needsSource', true);
});

it('keeps prior assignments and future observations out of history', function () {
    extract(peopleFixture());
    $this->mock(IntegrationEventHistoryService::class)->shouldReceive('forDeviceWindow')->andReturn(['positions' => collect([
        ['lat' => -36.8, 'lng' => 174.7, 'timestamp' => now()->subDays(3)->toISOString()],
        ['lat' => -36.8, 'lng' => 174.7, 'timestamp' => now()->addDay()->toISOString()],
    ]), 'truncated' => false]);
    $this->actingAs($actor)->getJson('/operations/people-locations/history?selected=c'.$client->id)->assertOk()->assertJsonCount(0, 'history.positions');
});

it('keeps server-side selection and history empty after care assignment removal', function () {
    extract(peopleFixture(false, true));
    $this->actingAs($actor)->getJson('/operations/people-locations/history?selected=c'.$client->id)->assertOk()->assertJsonCount(1, 'people');
    $client->supportWorkers()->detach($actor->id);
    $this->getJson('/operations/people-locations/history?selected=c'.$client->id)->assertOk()->assertJsonCount(0, 'people')->assertJsonPath('history', null);
});

it('requires export permission and a reason then produces the vehicle-style report with an audit', function () {
    extract(peopleFixture());
    peoplePoint($device);
    $payload = ['person' => 'c'.$client->id, 'source' => $assignment->id, 'date' => now('Pacific/Auckland')->toDateString(),
        'kind' => 'day', 'format' => 'html', 'reason' => 'Authorised care review'];
    $this->actingAs($actor)->postJson('/operations/people-locations/export', $payload)->assertForbidden();
    grantPeoplePermission($actor, 'people_locations.export');
    $this->actingAs($actor->fresh())->postJson('/operations/people-locations/export', [...$payload, 'reason' => ''])->assertUnprocessable();
    $this->postJson('/operations/people-locations/export', $payload)->assertOk()->assertSee('Daily location')
        ->assertSee('page-head')->assertSee('Pacific/Auckland')->assertHeader('Cache-Control', 'max-age=0, no-store, private');
    expect(AuditLog::where('action', 'people_locations.report_exported')->where('client_id', $client->id)->exists())->toBeTrue();
    $consent->update(['withdrawn_at' => now()]);
    $this->postJson('/operations/people-locations/export', $payload)->assertForbidden();
});

it('fails an outage instead of returning an empty successful population', function () {
    extract(peopleFixture());
    $this->mock(IntegrationEventHistoryService::class)->shouldReceive('forDeviceWindow')->andThrow(new RuntimeException('Read unavailable'));
    $this->actingAs($actor)->getJson('/operations/people-locations/map')->assertStatus(500);
});

it('hides Control Room evidence without its independent read permission', function () {
    extract(peopleFixture());
    $this->actingAs($actor)->getJson('/operations/people-locations/alerts')->assertOk()
        ->assertJsonPath('canReadAlerts', false)->assertJsonCount(0, 'alerts');
});

it('links the original readable response and removes it when authority ends', function () {
    extract(peopleFixture());
    grantPeoplePermission($actor, 'controlRoom.alerts.view');
    $alert = ControlRoomAlert::create(['source' => 'personal_tracker', 'alert_type' => 'fall_detected', 'severity' => 'critical',
        'status' => 'open', 'site_id' => $site->id, 'client_id' => $client->id, 'reference_number' => 'PL-TEST-1',
        'context' => ['normalized_data' => ['client_id' => $client->id]], 'triggered_at' => now()]);
    $this->actingAs($actor->fresh())->getJson('/operations/people-locations/alerts')->assertOk()
        ->assertJsonCount(1, 'alerts')->assertJsonPath('alerts.0.id', $alert->id)
        ->assertJsonPath('alerts.0.href', '/control-room/alerts/'.$alert->id.'?return_to=%2Foperations%2Fpeople-locations%2Falerts');
    $consent->update(['withdrawn_at' => now()]);
    $this->getJson('/operations/people-locations/alerts')->assertOk()->assertJsonCount(0, 'alerts');
});

it('counts a worker once across live sessions and removes ended sessions and their alerts', function () {
    extract(peopleFixture());
    grantPeoplePermission($actor, 'hazards.manage');
    grantPeoplePermission($actor, 'controlRoom.alerts.view');
    $sessions = collect([1, 2])->map(fn () => LoneWorkerSession::create(['user_id' => $actor->id, 'site_id' => $site->id,
        'started_at' => now()->subHour(), 'expected_end_at' => now()->addHour(), 'status' => 'active', 'created_by' => $actor->id]));
    $session = $sessions->last();
    ControlRoomAlert::create(['source' => 'lone_worker', 'alert_type' => 'lone_worker_emergency', 'severity' => 'critical',
        'status' => 'open', 'site_id' => $site->id, 'reference_number' => 'PL-STAFF-1',
        'context' => ['normalized_data' => ['lone_worker_session_id' => $session->id]], 'triggered_at' => now()]);
    $this->actingAs($actor->fresh())->getJson('/operations/people-locations/people?population=staff')->assertOk()
        ->assertJsonCount(1, 'people')->assertJsonCount(2, 'people.0.sources')->assertJsonPath('people.0.position', null)
        ->assertJsonCount(1, 'alerts')->assertJsonPath('alerts.0.personId', 's'.$actor->id);
    foreach ($sessions as $session) {
        $session->update(['ended_at' => now(), 'status' => 'completed']);
    }
    $this->getJson('/operations/people-locations/people?population=staff')->assertOk()->assertJsonCount(0, 'people')->assertJsonCount(0, 'alerts');
});

it('bounds an outing export to passenger departure and arrival instead of vehicle return', function () {
    extract(peopleFixture());
    grantPeoplePermission($actor, 'people_locations.export');
    grantPeoplePermission($actor, 'fleet.viewAny');
    $asset = Asset::factory()->create(['category' => 'vehicle', 'site_id' => $site->id]);
    $journey = FleetResidentTransport::create(['asset_id' => $asset->id, 'resident_id' => $client->id, 'resident_name' => $client->full_name, 'site_id' => $site->id,
        'driver_user_id' => $actor->id, 'transport_type' => 'medical', 'departed_at' => now()->subMinutes(40), 'arrived_at' => now()->subMinutes(20),
        'status' => 'completed', 'journey_uuid' => (string) Str::uuid()]);
    $this->mock(IntegrationEventHistoryService::class)->shouldReceive('forDeviceWindow')->andReturn(['positions' => collect([
        ['lat' => -36.123456, 'lng' => 174.1, 'timestamp' => now()->subMinutes(30)->toISOString()],
        ['lat' => -36.654321, 'lng' => 174.1, 'timestamp' => now()->subMinutes(10)->toISOString()],
    ]), 'truncated' => false]);
    $this->actingAs($actor->fresh())->postJson('/operations/people-locations/export', ['person' => 'c'.$client->id,
        'source' => $assignment->id, 'date' => now('Pacific/Auckland')->toDateString(), 'kind' => 'outing', 'journey' => $journey->id,
        'format' => 'html', 'reason' => 'Review passenger journey'])->assertOk()->assertSee('-36.123456')->assertDontSee('-36.654321');
});

it('uses observation timestamps for battery power and movement independently', function () {
    extract(peopleFixture());
    $asset = Asset::factory()->create(['site_id' => $site->id]);
    FleetTelemetryEvent::create(['asset_id' => $asset->id, 'device_id' => $device->id, 'vendor' => 'synthetic',
        'occurred_at' => now()->subMinutes(50), 'received_at' => now(), 'battery_pct' => 12, 'motion_status' => 'stationary',
        'event_type' => 'position', 'consent_blocked' => false, 'idempotency_key' => (string) Str::uuid(),
        'raw_payload' => ['charging_status' => 'stopped_charging']]);
    $this->actingAs($actor)->getJson('/operations/people-locations/people')->assertOk()->assertJsonPath('people.0.battery', 12)
        ->assertJsonPath('people.0.power', 'not_charging')->assertJsonPath('people.0.motion', 'stationary');
});

it('withholds rendered report bytes when consent is withdrawn during generation', function () {
    extract(peopleFixture());
    grantPeoplePermission($actor, 'people_locations.export');
    View::composer('pdf.people-location-report', function () use ($consent) {
        $consent->update(['withdrawn_at' => now()]);
    });
    $this->actingAs($actor->fresh())->postJson('/operations/people-locations/export', ['person' => 'c'.$client->id,
        'source' => $assignment->id, 'date' => now('Pacific/Auckland')->toDateString(), 'kind' => 'day',
        'format' => 'html', 'reason' => 'Review authorised day'])->assertForbidden();
    expect(AuditLog::where('action', 'people_locations.report_exported')->exists())->toBeFalse();
});

it('keeps personal evidence out of Inertia browser-history props', function () {
    extract(peopleFixture());
    $this->actingAs($actor)->get('/operations/people-locations/people')
        ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('operations/people-locations/index')->where('view', 'people')->missing('workspace'));
});

it('separates current map access from retained history and export', function () {
    extract(ClientLocationWorkspaceFixture::make());
    grantPeoplePermission($actor, 'people_locations.export');
    $this->actingAs($actor->fresh())->getJson('/operations/people-locations/history?selected=c'.$client->id)->assertOk()
        ->assertJsonPath('canViewHistory', false)->assertJsonPath('canExport', false)->assertJsonPath('history', null);
    $this->getJson('/operations/people-locations/report-preview?person=c'.$client->id.'&source='.$assignment->id.'&date='.now('Pacific/Auckland')->toDateString())->assertForbidden();
    grantPeoplePermission($actor, 'people_locations.history.view');
    $this->actingAs($actor->fresh())->getJson('/operations/people-locations/map?selected=c'.$client->id)->assertOk()->assertJsonPath('history', null);
    $this->getJson('/operations/people-locations/settings?selected=c'.$client->id)->assertOk()->assertJsonPath('history', null);
});

function peopleTelemetry($device, $asset, string $at, ?int $battery = 68): array
{
    return ['asset_id' => $asset->id, 'device_id' => $device->id, 'vendor' => 'synthetic',
        'occurred_at' => $at, 'received_at' => $at, 'battery_pct' => $battery, 'latitude' => -36.85, 'longitude' => 174.76,
        'event_type' => 'position', 'consent_blocked' => false, 'idempotency_key' => (string) Str::uuid(),
        'raw_payload' => '{}', 'created_at' => now(), 'updated_at' => now()];
}

it('uses exact Auckland day boundaries for both telemetry streams', function (string $date, string $first, string $last) {
    $this->travelTo(CarbonImmutable::parse($date.' 12:00:00', 'Pacific/Auckland')->addDay()->utc());
    extract(peopleFixture());
    $assignment->update(['assigned_at' => now()->subDays(3), 'collection_started_at' => now()->subDays(3)]);
    $asset = Asset::factory()->create(['site_id' => $site->id]);
    foreach ([CarbonImmutable::parse($first, 'UTC')->subSecond()->toDateTimeString(), $first, $last, CarbonImmutable::parse($last, 'UTC')->addSecond()->toDateTimeString()] as $at) {
        DB::table('fleet_telemetry_events')->insert(peopleTelemetry($device, $asset, $at));
    }
    $this->actingAs($actor)->getJson('/operations/people-locations/history?selected=c'.$client->id.'&date='.$date)->assertOk()
        ->assertJsonCount(2, 'history.samples')->assertJsonCount(2, 'history.positions')
        ->assertJsonPath('history.samples.0.at', CarbonImmutable::parse($first, 'UTC')->toISOString())
        ->assertJsonPath('history.samples.1.at', CarbonImmutable::parse($last, 'UTC')->toISOString())
        ->assertJsonPath('history.window.from', CarbonImmutable::parse($first, 'UTC')->toISOString())
        ->assertJsonPath('history.window.to', CarbonImmutable::parse($last, 'UTC')->endOfSecond()->toISOString());
})->with([
    '23-hour spring day' => ['2026-09-27', '2026-09-26 12:00:00', '2026-09-27 10:59:59'],
    '25-hour autumn day' => ['2026-04-05', '2026-04-04 11:00:00', '2026-04-05 11:59:59'],
    '24-hour ordinary day' => ['2026-09-26', '2026-09-25 12:00:00', '2026-09-26 11:59:59'],
]);

it('applies an early passenger window before the 500 sample limit and describes overnight day slices', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-28 12:00:00', 'Pacific/Auckland')->utc());
    extract(peopleFixture());
    grantPeoplePermission($actor, 'fleet.viewAny');
    grantPeoplePermission($actor, 'people_locations.export');
    $assignment->update(['assigned_at' => now()->subDays(3), 'collection_started_at' => now()->subDays(3)]);
    $asset = Asset::factory()->create(['category' => 'vehicle', 'site_id' => $site->id]);
    $journey = FleetResidentTransport::create(['asset_id' => $asset->id, 'resident_id' => $client->id, 'resident_name' => $client->full_name, 'site_id' => $site->id,
        'driver_user_id' => $actor->id, 'transport_type' => 'medical', 'departed_at' => '2026-09-26 11:00:00', 'arrived_at' => '2026-09-26 14:00:00',
        'status' => 'completed', 'journey_uuid' => (string) Str::uuid()]);
    $rows = [peopleTelemetry($device, $asset, '2026-09-26 13:00:00', 17)];
    for ($i = 0; $i < 510; $i++) {
        $rows[] = peopleTelemetry($device, $asset, CarbonImmutable::parse('2026-09-27 00:00:00', 'UTC')->addSeconds($i)->toDateTimeString());
    }
    DB::table('fleet_telemetry_events')->insert($rows);
    $url = '/operations/people-locations/report-preview?person=c'.$client->id.'&source='.$assignment->id.'&date=2026-09-27';
    $this->actingAs($actor->fresh())->getJson($url)->assertOk()->assertJsonPath('truncated', true)->assertJsonCount(500, 'samples');
    $this->getJson($url.'&journey='.$journey->id)->assertOk()->assertJsonCount(1, 'samples')->assertJsonPath('samples.0.battery', 17)
        ->assertJsonPath('truncated', false)->assertJsonPath('window.from', '2026-09-26T12:00:00.000000Z')->assertJsonPath('window.to', '2026-09-26T14:00:00.000000Z');
    $this->postJson('/operations/people-locations/export', ['person' => 'c'.$client->id, 'source' => $assignment->id, 'date' => '2026-09-27', 'kind' => 'day', 'format' => 'csv', 'reason' => 'Review retained day'])
        ->assertOk()->assertSee('PARTIAL:')->assertSee('Authorised window (UTC)')->assertSee('Device sample');
});

it('persists personal defaults with conflict protection and refuses an inaccessible site', function () {
    extract(peopleFixture());
    $other = peopleFixture();
    $payload = ['revision' => 0, 'population' => 'clients', 'site' => (string) $site->id, 'peopleView' => 'list', 'boundaries' => false];
    $this->actingAs($actor)->putJson('/operations/people-locations/preferences', $payload)->assertOk()->assertJsonPath('revision', 1);
    $this->putJson('/operations/people-locations/preferences', $payload)->assertConflict();
    $this->getJson('/operations/people-locations/people')->assertOk()->assertJsonPath('filters.site', (string) $site->id)->assertJsonPath('preferences.value.peopleView', 'list');
    $this->putJson('/operations/people-locations/preferences', [...$payload, 'revision' => 1, 'site' => (string) $other['site']->id])->assertUnprocessable();
    $site->update(['archived' => true, 'archived_at' => now()]);
    $this->getJson('/operations/people-locations/preferences')->assertOk()->assertJsonPath('siteUnavailable', true)->assertJsonPath('value.site', 'all');
});

it('revalidates a batched consent after reading observations', function () {
    extract(peopleFixture());
    $this->mock(IntegrationEventHistoryService::class)->shouldReceive('forDeviceWindow')->andReturnUsing(function () use ($consent) {
        $consent->update(['withdrawn_at' => now()]);

        return ['positions' => collect(), 'truncated' => false];
    });
    $this->actingAs($actor)->getJson('/operations/people-locations/map')->assertOk()->assertJsonCount(0, 'people')->assertJsonCount(0, 'sites');
});

it('shows permitted shared boundary geometry without enabling personal monitoring', function () {
    extract(peopleFixture());
    grantPeoplePermission($actor, 'fleet.viewAny');
    $boundary = AssetGeofence::create(['site_id' => $site->id, 'name' => 'Shared reference', 'scope' => 'site', 'type' => 'circle',
        'shape' => ['center' => ['lat' => -36.85, 'lng' => 174.76], 'radius_m' => 100], 'is_active' => false]);
    $this->actingAs($actor->fresh())->getJson('/operations/people-locations/map')->assertOk()->assertJsonCount(1, 'boundaries')
        ->assertJsonPath('boundaries.0.id', $boundary->id)->assertJsonPath('boundaries.0.geometry.radius_m', 100);
    expect($boundary->fresh()->is_active)->toBeFalse();
    $boundary->forceFill(['retired_at' => now()])->save();
    $this->getJson('/operations/people-locations/map')->assertOk()->assertJsonCount(0, 'boundaries');
});
