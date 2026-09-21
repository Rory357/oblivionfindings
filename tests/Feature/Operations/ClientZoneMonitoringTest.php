<?php

use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Jobs\DispatchFleetSignalOutbox;
use App\Models\Asset;
use App\Models\AssetGeofence;
use App\Models\AuditLog;
use App\Models\ClientGeofenceMonitor;
use App\Models\ControlRoom\SignalSource;
use App\Models\ControlRoom\SignalType;
use App\Models\ControlRoom\TriageQueue;
use App\Models\ControlRoomAlert;
use App\Models\FleetSignal;
use App\Models\FleetSignalOutbox;
use App\Models\FleetTelemetryEvent;
use App\Models\Site;
use App\Services\ControlRoom\SignalProcessingService;
use App\Services\Fleet\FleetTelemetryIngestService;
use App\Services\Tracking\ClientLocationAccessService;
use App\Services\Tracking\ClientLocationZoneDraftService;
use App\Services\Tracking\ClientZoneMonitoringService;
use App\Services\Tracking\ClientZonePosition;
use App\Services\Tracking\ClientZoneSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\ClientLocationWorkspaceFixture;

beforeEach(function () {
    // Freeze the storage clock in UTC; only the schedule is interpreted in Auckland.
    $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00:00', 'Pacific/Auckland')->utc());
    Http::preventStrayRequests();
    Queue::fake();
    Notification::fake();
    Mail::fake();
});

function zoneMonitorFixture(bool $activate = true, string $classification = 'agreed'): array
{
    $f = ClientLocationWorkspaceFixture::make();
    $f['assignment']->update(['access_audience' => ['authorised_client_care', 'control_room']]);
    $f['fingerprint'] = app(ClientLocationAccessService::class)->fingerprint(app(ClientLocationAccessService::class)->resolve($f['actor'], $f['client']));
    $f['payload']['access_fingerprint'] = $f['fingerprint'];
    $f['asset'] = Asset::factory()->create(['site_id' => $f['site']->id, 'home_site_id' => $f['site']->id, 'client_id' => $f['client']->id]);
    DeviceAssetLink::query()->create(['device_id' => $f['device']->id, 'asset_id' => $f['asset']->id, 'link_type' => 'primary', 'linked_at' => now()->subDay()]);
    SignalSource::query()->firstOrCreate(['slug' => 'personal_tracker'], ['name' => 'Personal Tracker', 'status' => 'active']);
    SignalType::query()->firstOrCreate(['code' => ClientZoneMonitoringService::CONTROL_TYPE], ['name' => 'Safe zone', 'category' => 'people_safety', 'default_severity' => 'high', 'is_active' => true]);
    TriageQueue::query()->firstOrCreate(['code' => 'zone_test'], ['name' => 'Control Room high priority', 'tier' => 2, 'handle_severities' => ['high'], 'is_active' => true]);
    $f['payload']['classification'] = $classification;
    $f['zone'] = app(ClientLocationZoneDraftService::class)->save($f['actor'], $f['client'], $f['payload']);
    $f['input'] = ['action' => 'activate', 'access_fingerprint' => $f['fingerprint'], 'expected_revision' => 1, 'idempotency_key' => 'synthetic-monitor-start-001', 'reviewed' => true];
    $f['url'] = "/operations/clients/{$f['client']->id}/location/zones/{$f['zone']['id']}/monitoring";
    if ($activate) {
        $f['zone'] = app(ClientZoneMonitoringService::class)->change($f['actor'], $f['client'], $f['zone']['id'], $f['input']);
    }

    return $f;
}

function zoneReport(array $f, float $latitude = -36.86, array $overrides = []): FleetTelemetryEvent
{
    return FleetTelemetryEvent::query()->create([
        'asset_id' => $f['asset']->id, 'device_id' => $f['device']->id, 'vendor' => 'synthetic',
        'occurred_at' => now(), 'received_at' => now(), 'latitude' => $latitude, 'longitude' => 174.76,
        'accuracy_m' => 5, 'consent_blocked' => false, 'idempotency_key' => (string) Str::uuid(), ...$overrides,
    ]);
}

it('requires management and explicit revision review, replays activation and pauses without editing active geometry', function () {
    $f = zoneMonitorFixture(false);
    $this->actingAs($f['actor'])->postJson($f['url'], [...$f['input'], 'reviewed' => false])->assertUnprocessable();
    $this->postJson($f['url'], [...$f['input'], 'expected_revision' => 2])->assertConflict();
    $this->postJson($f['url'], $f['input'])->assertOk()->assertJsonPath('zone.monitoring.status', 'active');
    $this->postJson($f['url'], $f['input'])->assertOk();
    expect(ClientGeofenceMonitor::count())->toBe(1)->and(FleetSignal::count())->toBe(0);
    $this->putJson(str_replace('/monitoring', '', $f['url']), [...$f['payload'], 'expected_revision' => 1])->assertConflict();
    $pause = [...$f['input'], 'action' => 'pause', 'monitor_id' => ClientGeofenceMonitor::first()->id];
    $this->postJson($f['url'], $pause)->assertOk()->assertJsonPath('zone.monitoring.status', 'paused');
    $this->postJson($f['url'], $pause)->assertOk();
    $this->postJson($f['url'], $f['input'])->assertConflict();
    $this->postJson($f['url'], [...$f['input'], 'idempotency_key' => 'synthetic-monitor-start-002'])->assertOk();
    $this->postJson($f['url'], $pause)->assertConflict();
    expect(ClientGeofenceMonitor::count())->toBe(2);
    $f['actor']->roles()->detach();
    $this->postJson($f['url'], $f['input'])->assertForbidden();
});

it('routes one breach episode into the existing durable Control Room workflow with the client and response instructions', function () {
    $f = zoneMonitorFixture();
    $service = app(ClientZoneMonitoringService::class);
    $event = zoneReport($f);
    $service->evaluate($event);
    $service->evaluate($event);
    $this->travel(1)->minutes();
    $service->evaluate(zoneReport($f));
    expect(FleetSignal::count())->toBe(1)->and(FleetSignalOutbox::count())->toBe(1);
    $job = new DispatchFleetSignalOutbox(FleetSignalOutbox::first()->id);
    $job->handle(app(SignalProcessingService::class));
    $job->handle(app(SignalProcessingService::class));
    expect(FleetSignalOutbox::first()->status)->toBe('sent')->and(ControlRoomAlert::count())->toBe(1);
    $alert = ControlRoomAlert::first();
    expect($alert->client_id)->toBe($f['client']->id)->and($alert->site_id)->toBe($f['site']->id)
        ->and($alert->source)->toBe('personal_tracker')->and($alert->severity)->toBe('high')->and($alert->queue_id)->not->toBeNull()
        ->and(data_get($alert->context, 'signal_payload.safe_zone.response'))->toBe($f['payload']['response_proposal']);
    $this->travel(1)->minutes();
    $service->evaluate(zoneReport($f, -36.85));
    expect($alert->fresh()->status)->toBe('open');
    $this->travel(1)->minutes();
    $service->evaluate(zoneReport($f));
    expect(FleetSignal::count())->toBe(2)->and(ClientGeofenceMonitor::first()->breach_count)->toBe(2);
    Http::assertNothingSent();
});

it('checks attention zones on entry and waits through imprecise fixes without restarting a breach', function () {
    $f = zoneMonitorFixture(true, 'attention');
    $service = app(ClientZoneMonitoringService::class);
    $service->evaluate(zoneReport($f));
    expect(FleetSignal::count())->toBe(0);
    $this->travel(1)->minutes();
    $service->evaluate(zoneReport($f, -36.85));
    $this->travel(1)->minutes();
    $service->evaluate(zoneReport($f, -36.85, ['accuracy_m' => 200]));
    $this->travel(1)->minutes();
    $service->evaluate(zoneReport($f, -36.85));
    expect(FleetSignal::count())->toBe(1);
});

it('ignores preactivation future duplicate late paused blocked and wrong-asset reports', function () {
    $f = zoneMonitorFixture();
    $service = app(ClientZoneMonitoringService::class);
    foreach ([['occurred_at' => now()->subSecond()], ['occurred_at' => now()->addMinute()], ['consent_blocked' => true], ['asset_id' => Asset::factory()->create()->id], ['latitude' => null]] as $override) {
        $service->evaluate(zoneReport($f, -36.86, $override));
    }
    expect(FleetSignal::count())->toBe(0);
    $this->travel(2)->minutes();
    $service->evaluate(zoneReport($f, -36.85));
    $service->evaluate(zoneReport($f, -36.86, ['occurred_at' => now()->subMinute()]));
    $service->change($f['actor'], $f['client'], $f['zone']['id'], [...$f['input'], 'action' => 'pause', 'monitor_id' => $f['zone']['monitoring']['id']]);
    $this->travel(1)->minutes();
    $service->evaluate(zoneReport($f));
    expect(FleetSignal::count())->toBe(0);
});

it('prevents alert publication after consent collection or assignment authority changes', function () {
    $f = zoneMonitorFixture();
    $service = app(ClientZoneMonitoringService::class);
    $service->evaluate(zoneReport($f));
    $f['assignment']->update(['collection_stopped_at' => now()]);
    (new DispatchFleetSignalOutbox(FleetSignalOutbox::first()->id))->handle(app(SignalProcessingService::class));
    expect(ControlRoomAlert::count())->toBe(0)->and(FleetSignalOutbox::first()->status)->toBe('unroutable');
    $this->travel(1)->minutes();
    $service->evaluate(zoneReport($f));
    expect(FleetSignal::count())->toBe(1);
});

it('does not activate without Control Room routing and a current nonexpired response plan', function () {
    $f = zoneMonitorFixture(false);
    SignalSource::where('slug', 'personal_tracker')->update(['status' => 'inactive']);
    $this->actingAs($f['actor'])->postJson($f['url'], $f['input'])->assertStatus(503);
    SignalSource::where('slug', 'personal_tracker')->update(['status' => 'active']);
    $this->travelTo(CarbonImmutable::parse('2026-11-01 10:00:00', 'Pacific/Auckland')->utc());
    $this->postJson($f['url'], $f['input'])->assertUnprocessable();
    expect(ClientGeofenceMonitor::count())->toBe(0);
});

it('evaluates Auckland windows with exclusive ends overnight start-date exceptions and daylight saving', function () {
    $schedule = ['timezone' => 'Pacific/Auckland', 'weekdays' => [1, 6], 'start' => '20:00', 'end' => '07:00', 'following_day' => true,
        'first_date' => '2026-09-21', 'last_date' => '2026-09-26', 'exception_dates' => []];
    $service = new ClientZoneSchedule;
    foreach (['2026-09-21 19:59' => null, '2026-09-21 20:00' => '2026-09-21', '2026-09-22 06:59' => '2026-09-21', '2026-09-22 07:00' => null,
        '2026-09-27 03:30' => '2026-09-26', '2026-09-27 07:00' => null] as $time => $expected) {
        expect($service->window($schedule, CarbonImmutable::parse($time, 'Pacific/Auckland')))->toBe($expected);
    }
    expect($service->window([...$schedule, 'exception_dates' => ['2026-09-21']], CarbonImmutable::parse('2026-09-22 06:00', 'Pacific/Auckland')))->toBeNull();
});

it('rearms on the next scheduled window and does not emit outside scheduled hours', function () {
    $f = zoneMonitorFixture();
    $service = app(ClientZoneMonitoringService::class);
    $service->evaluate(zoneReport($f));
    $this->travelTo(now()->setTimezone('Pacific/Auckland')->setTime(16, 0)->utc());
    $service->evaluate(zoneReport($f));
    expect(FleetSignal::count())->toBe(1);
    $this->travelTo(now()->setTimezone('Pacific/Auckland')->addDay()->setTime(9, 0)->utc());
    $service->evaluate(zoneReport($f));
    expect(FleetSignal::count())->toBe(2);
});

it('classifies polygon interiors edges and accuracy overlap without false certainty', function () {
    $shape = ['type' => 'polygon', 'coordinates' => [['lat' => -36.84, 'lng' => 174.75], ['lat' => -36.86, 'lng' => 174.75], ['lat' => -36.86, 'lng' => 174.77], ['lat' => -36.84, 'lng' => 174.77]]];
    $service = new ClientZonePosition;
    expect($service->classify($shape, -36.85, 174.76, 10))->toBe('inside')
        ->and($service->classify($shape, -36.87, 174.76, 10))->toBe('outside')
        ->and($service->classify($shape, -36.84, 174.76, 10))->toBe('uncertain')
        ->and($service->classify($shape, -36.85, 174.76, 2000))->toBe('uncertain');
});

it('uses the canonical telemetry ingestion path and creates only one signal on vendor replay', function () {
    $f = zoneMonitorFixture();
    $f['device']->update(['provider' => 'queclink', 'imei' => 'ZONE-SYNTHETIC-001', 'device_uid' => 'ZONE-SYNTHETIC-001']);
    $payload = ['imei' => 'ZONE-SYNTHETIC-001', 'gps_time' => now()->toISOString(), 'lat' => -36.86, 'lng' => 174.76, 'speed' => 0];
    $ingest = app(FleetTelemetryIngestService::class);
    expect($ingest->ingest('queclink', $payload)['ok'])->toBeTrue();
    expect($ingest->ingest('queclink', $payload)['duplicate'])->toBeTrue();
    expect(FleetSignal::where('signal_type', ClientZoneMonitoringService::SIGNAL)->count())->toBe(1);
    expect(FleetSignalOutbox::count())->toBe(1);
});

it('keeps a valid pending alert after pausing while rejecting a changed client or site before publication', function () {
    $f = zoneMonitorFixture();
    $service = app(ClientZoneMonitoringService::class);
    $service->evaluate(zoneReport($f));
    $this->travel(1)->minutes();
    $service->change($f['actor'], $f['client'], $f['zone']['id'], [...$f['input'], 'action' => 'pause', 'monitor_id' => $f['zone']['monitoring']['id']]);
    (new DispatchFleetSignalOutbox(FleetSignalOutbox::first()->id))->handle(app(SignalProcessingService::class));
    expect(ControlRoomAlert::count())->toBe(1);
    $service->change($f['actor'], $f['client'], $f['zone']['id'], [...$f['input'], 'idempotency_key' => 'synthetic-monitor-start-002']);
    $service->evaluate(zoneReport($f));
    $f['asset']->update(['site_id' => Site::factory()->create()->id]);
    (new DispatchFleetSignalOutbox(FleetSignalOutbox::latest('id')->first()->id))->handle(app(SignalProcessingService::class));
    expect(ControlRoomAlert::count())->toBe(1)->and(FleetSignalOutbox::latest('id')->first()->status)->toBe('unroutable');
});

it('rolls back activation when auditing fails and does not treat an old buffered fix as a new emergency', function () {
    $f = zoneMonitorFixture(false);
    $fail = true;
    AuditLog::creating(function ($log) use (&$fail) {
        if ($fail && $log->action === 'client.location.zone_monitor.activated') {
            throw new RuntimeException('Synthetic audit failure');
        }
    });
    $this->actingAs($f['actor'])->postJson($f['url'], $f['input'])->assertStatus(500);
    expect(ClientGeofenceMonitor::count())->toBe(0);
    $fail = false;
    $this->postJson($f['url'], $f['input'])->assertOk();
    $time = now();
    $this->travel(6)->minutes();
    app(ClientZoneMonitoringService::class)->evaluate(zoneReport($f, -36.86, ['occurred_at' => $time]));
    expect(FleetSignal::count())->toBe(0);
});

it('starts a fresh episode after an uncertain first fix in a new schedule window', function () {
    $f = zoneMonitorFixture();
    $service = app(ClientZoneMonitoringService::class);
    $service->evaluate(zoneReport($f));
    $this->travelTo(now()->setTimezone('Pacific/Auckland')->addDay()->setTime(9, 0)->utc());
    $service->evaluate(zoneReport($f, -36.85, ['accuracy_m' => 100]));
    $this->travel(1)->minutes();
    $service->evaluate(zoneReport($f));
    expect(FleetSignal::count())->toBe(2);
});

it('requires a Control Room audience and marks a changed assignment for review', function () {
    $f = zoneMonitorFixture();
    $f['assignment']->update(['access_audience' => ['authorised_client_care']]);
    $envelope = app(ClientLocationZoneDraftService::class)->read($f['actor'], $f['client']);
    expect($envelope['zones'][0]['monitoring']['authority_current'])->toBeFalse();
    app(ClientZoneMonitoringService::class)->evaluate(zoneReport($f));
    expect(FleetSignal::count())->toBe(0);
    $input = [...$f['input'], 'access_fingerprint' => $envelope['access_fingerprint'], 'monitor_id' => $f['zone']['monitoring']['id']];
    $this->actingAs($f['actor'])->postJson($f['url'], [...$input, 'action' => 'pause'])->assertOk();
    $this->postJson($f['url'], [...$input, 'idempotency_key' => 'synthetic-monitor-start-002'])->assertUnprocessable();
});

it('rejects malformed legacy site geometry at activation without creating monitoring', function () {
    $f = zoneMonitorFixture(false);
    $boundary = AssetGeofence::create(['site_id' => $f['site']->id, 'name' => 'Malformed legacy boundary', 'scope' => 'house', 'type' => 'polygon', 'shape' => ['coordinates' => []], 'is_active' => true]);
    $payload = [...$f['payload'], 'expected_revision' => 1, 'geometry_source' => 'canonical', 'canonical_geofence_id' => $boundary->id, 'canonical_geometry_hash' => app(ClientLocationZoneDraftService::class)->geometryHash($boundary), 'idempotency_key' => 'synthetic-invalid-geometry'];
    unset($payload['geometry']);
    app(ClientLocationZoneDraftService::class)->save($f['actor'], $f['client'], $payload, $f['zone']['id']);
    $this->actingAs($f['actor'])->postJson($f['url'], [...$f['input'], 'expected_revision' => 2])->assertUnprocessable();
    expect(ClientGeofenceMonitor::count())->toBe(0);
});
