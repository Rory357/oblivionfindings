<?php

namespace Tests\Feature\ControlRoom;

use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoomAlert;
use App\Models\HsEvent;
use App\Models\Site;
use App\Models\User;
use App\Services\ControlRoom\SensorIncidentBridgeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SensorIncidentJourneyTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirm_reuses_the_sensor_alert_and_builds_one_evidenced_journey_on_retry(): void
    {
        $operator = User::factory()->create();
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $alert = ControlRoomAlert::factory()->open()->create([
            'source' => 'sensor',
            'alert_type' => 'sensor.fall_detected',
            'severity' => 'critical',
            'client_id' => $client->id,
            'site_id' => $site->id,
            'context' => ['device_zone' => 'Bedroom'],
        ]);
        $signal = Signal::create([
            'alert_id' => $alert->id,
            'client_id' => $client->id,
            'site_id' => $site->id,
            'signal_type_code' => 'fall_detected',
            'severity_hint' => 'critical',
            'occurred_at' => now()->subMinutes(2),
            'payload' => ['confidence' => 0.97, 'location' => 'Bedroom'],
            'status' => 'processed',
        ]);

        $first = app(SensorIncidentBridgeService::class)->confirm($alert, $operator);
        $second = app(SensorIncidentBridgeService::class)->confirm($alert->fresh(), $operator);
        $first = $first->fresh();
        $event = HsEvent::query()->sole();

        $this->assertTrue($second->is($first));
        $this->assertSame('sensor', $first->source);
        $this->assertFalse($first->interactive);
        $this->assertSame('submitted', $first->status);
        $this->assertSame('fall_detected', data_get($first->metadata, 'sensor_evidence.signal_type'));
        $this->assertSame(0.97, data_get($first->metadata, 'sensor_evidence.payload.confidence'));
        $this->assertSame($alert->id, $first->control_room_alert_id);
        $this->assertSame($event->id, $first->hs_event_id);
        $this->assertSame($alert->id, $event->control_room_alert_id);
        $this->assertSame($first->id, $event->source_id);
        $this->assertSame(ClientIncident::class, $event->source_type);
        $this->assertSame($first->id, data_get($alert->fresh()->context, 'incident_id'));
        $this->assertSame(ControlRoomAlert::STATUS_CONFIRMED, $alert->fresh()->status);
        $this->assertSame($alert->id, $signal->fresh()->alert_id);
        $this->assertDatabaseCount('client_incidents', 1);
        $this->assertDatabaseCount('control_room_alerts', 1);
        $this->assertDatabaseCount('hs_events', 1);
    }

    public function test_dismiss_suppresses_the_signal_without_creating_an_incident_journey(): void
    {
        $operator = User::factory()->create();
        $alert = ControlRoomAlert::factory()->open()->create([
            'source' => 'sensor',
            'alert_type' => 'sensor.fall_detected',
        ]);
        $signal = Signal::create([
            'alert_id' => $alert->id,
            'signal_type_code' => 'fall_detected',
            'severity_hint' => 'medium',
            'occurred_at' => now(),
            'payload' => ['confidence' => 0.12],
            'status' => 'processed',
        ]);

        app(SensorIncidentBridgeService::class)->dismiss(
            $alert,
            'Resident sat down safely.',
            $operator,
        );

        $this->assertSame(ControlRoomAlert::STATUS_DISMISSED, $alert->fresh()->status);
        $this->assertSame('false_positive', $alert->fresh()->resolution_code);
        $this->assertSame('Resident sat down safely.', data_get($alert->fresh()->context, 'dismissed_reason'));
        $this->assertSame('suppressed', $signal->fresh()->status);
        $this->assertDatabaseCount('client_incidents', 0);
        $this->assertDatabaseCount('hs_events', 0);
        $this->assertDatabaseCount('control_room_alerts', 1);
    }
}
