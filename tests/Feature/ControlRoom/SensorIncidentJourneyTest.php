<?php

namespace Tests\Feature\ControlRoom;

use App\Models\AuditLog;
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
        $confirmedAt = data_get($alert->fresh()->context, 'confirmed_at');
        $acknowledgedAt = $alert->fresh()->acknowledged_at?->toISOString();
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
        $this->assertSame($confirmedAt, data_get($alert->fresh()->context, 'confirmed_at'));
        $this->assertSame($acknowledgedAt, $alert->fresh()->acknowledged_at?->toISOString());
        $this->assertSame(1, AuditLog::query()
            ->where('action', 'controlRoom.alert.confirm')
            ->where('auditable_id', $alert->id)
            ->count());
        $this->assertSame($alert->id, $signal->fresh()->alert_id);
        $this->assertDatabaseCount('client_incidents', 1);
        $this->assertDatabaseCount('control_room_alerts', 1);
        $this->assertDatabaseCount('hs_events', 1);
    }

    public function test_linked_triaging_alert_completes_partial_confirmation_once(): void
    {
        $operator = User::factory()->create();
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $alert = ControlRoomAlert::factory()->triaging()->create([
            'source' => 'sensor',
            'alert_type' => 'sensor.fall_detected',
            'severity' => 'critical',
            'client_id' => $client->id,
            'site_id' => $site->id,
            'context' => ['device_zone' => 'Hallway'],
        ]);
        Signal::create([
            'alert_id' => $alert->id,
            'client_id' => $client->id,
            'site_id' => $site->id,
            'signal_type_code' => 'fall_detected',
            'severity_hint' => 'critical',
            'occurred_at' => now()->subMinutes(3),
            'payload' => ['confidence' => 0.92],
            'status' => 'processed',
        ]);
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create([
            'client_id' => $client->id,
            'reported_by' => $operator->id,
            'source' => 'sensor',
            'severity' => 'high',
            'status' => 'submitted',
            'submitted_at' => now()->subMinute(),
            'control_room_alert_id' => $alert->id,
        ]));

        $result = app(SensorIncidentBridgeService::class)->confirm($alert, $operator);
        $alert->refresh();

        $this->assertTrue($result->is($incident));
        $this->assertSame(ControlRoomAlert::STATUS_CONFIRMED, $alert->status);
        $this->assertSame($incident->id, data_get($alert->context, 'incident_id'));
        $this->assertSame($operator->name, data_get($alert->context, 'confirmed_by'));
        $this->assertNotNull(data_get($alert->context, 'confirmed_at'));
        $this->assertNotNull($alert->acknowledged_at);
        $this->assertNotNull($alert->acknowledged_by_user_id);
        $this->assertSame(1, AuditLog::query()
            ->where('action', 'controlRoom.alert.confirm')
            ->where('auditable_id', $alert->id)
            ->count());
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
