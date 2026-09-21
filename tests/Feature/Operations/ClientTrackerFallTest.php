<?php

namespace Tests\Feature\Operations;

use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Exceptions\SafetySignalUnroutable;
use App\Jobs\DispatchFleetSignalOutbox;
use App\Models\Asset;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoom\SignalSource;
use App\Models\ControlRoom\SignalType;
use App\Models\ControlRoom\TriageQueue;
use App\Models\ControlRoomAlert;
use App\Models\FleetSignal;
use App\Models\FleetSignalOutbox;
use App\Models\FleetTelemetryEvent;
use App\Services\ControlRoom\SignalProcessingService;
use App\Services\Fleet\FleetTelemetryIngestService;
use App\Services\Tracking\ClientTrackerFallService;
use App\Services\Tracking\ClientTrackerStatusService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\ClientLocationWorkspaceFixture;
use Tests\TestCase;

class ClientTrackerFallTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-12T00:00:00Z'));
        Http::preventStrayRequests();
        Queue::fake();
        Notification::fake();
        Mail::fake();
        $f = ClientLocationWorkspaceFixture::make();
        $f['assignment']->update(['access_audience' => ['authorised_client_care', 'control_room']]);
        $f['device']->update(['provider' => 'queclink']);
        $f['asset'] = Asset::factory()->create(['site_id' => $f['site']->id, 'home_site_id' => $f['site']->id, 'client_id' => $f['client']->id]);
        DeviceAssetLink::query()->create(['device_id' => $f['device']->id, 'asset_id' => $f['asset']->id, 'link_type' => 'primary', 'linked_at' => now()->subDay()]);
        SignalSource::query()->firstOrCreate(['slug' => 'personal_tracker'], ['name' => 'Personal Tracker', 'status' => 'active']);
        SignalType::query()->firstOrCreate(['code' => 'fall_detected'], ['name' => 'Fall detected', 'category' => 'people_safety', 'default_severity' => 'critical', 'is_active' => true]);
        TriageQueue::query()->firstOrCreate(['code' => 'fall_test'], ['name' => 'Control Room critical', 'tier' => 1, 'handle_severities' => ['critical'], 'is_active' => true]);

        return $f;
    }

    private function event(array $f, array $changes = []): FleetTelemetryEvent
    {
        return FleetTelemetryEvent::query()->create(['asset_id' => $f['asset']->id, 'device_id' => $f['device']->id,
            'vendor' => 'synthetic', 'event_type' => 'fall_detected', 'occurred_at' => now(), 'received_at' => now(),
            'latitude' => null, 'longitude' => null, 'consent_blocked' => false, 'idempotency_key' => (string) Str::uuid(), ...$changes]);
    }

    public function test_real_intake_routes_fall_without_gps_to_control_room_and_the_client_indicator_once(): void
    {
        $f = $this->fixture();
        $payload = ['imei' => $f['device']->imei ?: $f['device']->device_uid, 'message_id' => 'fall-test-001',
            'timestamp' => now()->toISOString(), 'alarm' => 'fall_detected'];
        $result = app(FleetTelemetryIngestService::class)->ingest('queclink', $payload);
        $this->assertTrue($result['ok']);
        $this->assertTrue(app(FleetTelemetryIngestService::class)->ingest('queclink', $payload)['duplicate']);
        $signal = FleetSignal::query()->where('signal_type', ClientTrackerFallService::SIGNAL)->sole();
        $outbox = FleetSignalOutbox::query()->where('fleet_signal_id', $signal->id)->sole();
        (new DispatchFleetSignalOutbox($outbox->id))->handle(app(SignalProcessingService::class));
        (new DispatchFleetSignalOutbox($outbox->id))->handle(app(SignalProcessingService::class));
        $this->assertSame('sent', $outbox->fresh()->status);
        $alert = ControlRoomAlert::query()->sole();
        $this->assertSame($f['client']->id, $alert->client_id);
        $this->assertSame('personal_tracker', $alert->source);
        $this->assertSame('critical', $alert->severity);
        $this->assertSame('fall_detected', data_get($alert->context, 'signal_payload.tracker_event.type'));
        $this->assertSame('fall_detected', app(ClientTrackerStatusService::class)->read($f['actor'], $f['client'])['fall_report_type']);
        Http::assertNothingSent();
    }

    public function test_man_down_motion_future_blocked_and_preassignment_reports_do_not_fabricate_falls(): void
    {
        $f = $this->fixture();
        foreach ([['event_type' => 'man_down'], ['event_type' => 'motion_start'], ['consent_blocked' => true],
            ['occurred_at' => now()->addMinute()], ['occurred_at' => now()->subWeek()]] as $changes) {
            DB::transaction(fn () => app(ClientTrackerFallService::class)->evaluate($this->event($f, $changes)));
        }
        $this->assertSame(0, FleetSignal::count());
        $f['assignment']->update(['access_audience' => ['authorised_client_care']]);
        DB::transaction(fn () => app(ClientTrackerFallService::class)->evaluate($this->event($f)));
        $this->assertSame(0, FleetSignal::count());
    }

    public function test_withdrawal_or_changed_lineage_stops_queued_fall_projection(): void
    {
        $f = $this->fixture();
        DB::transaction(fn () => app(ClientTrackerFallService::class)->evaluate($this->event($f)));
        $signal = FleetSignal::query()->sole();
        DB::transaction(fn () => app(SignalProcessingService::class)->ingestFromFleetSignal($signal));
        $f['assignment']->update(['collection_stopped_at' => now()]);
        $this->expectException(SafetySignalUnroutable::class);
        app(SignalProcessingService::class)->process(Signal::query()->sole());
    }
}
