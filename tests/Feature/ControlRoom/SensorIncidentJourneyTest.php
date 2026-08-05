<?php

namespace Tests\Feature\ControlRoom;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoom\SignalType;
use App\Models\ControlRoomAlert;
use App\Models\HsEvent;
use App\Models\Site;
use App\Models\User;
use App\Services\ControlRoom\SensorIncidentBridgeService;
use App\Services\ControlRoom\SignalProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SensorIncidentJourneyTest extends TestCase
{
    use RefreshDatabase;

    public function test_sensor_confirmation_and_trusted_signal_processing_expose_outer_deadlock_retries(): void
    {
        foreach ([
            [SensorIncidentBridgeService::class, 'confirm'],
            [SignalProcessingService::class, 'process'],
        ] as [$service, $method]) {
            $reflection = new \ReflectionClass($service);
            $attempts = $reflection->getReflectionConstant('TRANSACTION_ATTEMPTS');
            $this->assertNotFalse($attempts, "{$service} must declare its outer retry contract.");
            $this->assertSame(3, $attempts->getValue());

            $methodReflection = $reflection->getMethod($method);
            $source = implode('', array_slice(
                file($methodReflection->getFileName()),
                $methodReflection->getStartLine() - 1,
                $methodReflection->getEndLine() - $methodReflection->getStartLine() + 1,
            ));
            $this->assertStringContainsString('self::TRANSACTION_ATTEMPTS', $source);
        }
    }

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

        $overrides = [
            'immediate_action_taken' => 'Resident checked and the bedroom made safe.',
        ];
        $first = app(SensorIncidentBridgeService::class)->confirm($alert, $operator, $overrides);
        $confirmedAt = data_get($alert->fresh()->context, 'confirmed_at');
        $acknowledgedAt = $alert->fresh()->acknowledged_at?->toISOString();
        $second = app(SensorIncidentBridgeService::class)->confirm($alert->fresh(), $operator, $overrides);
        $first = $first->fresh();
        $event = HsEvent::query()->sole();

        $this->assertTrue($second->is($first));
        $this->assertSame('sensor', $first->source);
        $this->assertFalse($first->interactive);
        $this->assertSame('submitted', $first->status);
        $this->assertSame(
            'Resident checked and the bedroom made safe.',
            $first->immediate_action_taken,
        );
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

        $result = app(SensorIncidentBridgeService::class)->confirm($alert, $operator, [
            'immediate_action_taken' => 'Resident checked during partial confirmation repair.',
        ]);
        $alert->refresh();

        $this->assertTrue($result->is($incident));
        $this->assertSame(
            'Resident checked during partial confirmation repair.',
            $incident->fresh()->immediate_action_taken,
        );
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

    public function test_confirmed_alert_repairs_a_missing_hs_link_without_reconfirming(): void
    {
        $operator = User::factory()->create();
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $confirmedAt = now()->subMinutes(4)->startOfSecond();
        $acknowledgedAt = now()->subMinutes(5)->startOfSecond();
        $alert = ControlRoomAlert::factory()->create([
            'source' => 'sensor',
            'alert_type' => 'sensor.fall_detected',
            'severity' => 'critical',
            'status' => ControlRoomAlert::STATUS_CONFIRMED,
            'client_id' => $client->id,
            'site_id' => $site->id,
            'acknowledged_at' => $acknowledgedAt,
            'acknowledged_by_user_id' => $operator->id,
            'context' => [
                'confirmed_by' => $operator->name,
                'confirmed_at' => $confirmedAt->toISOString(),
            ],
        ]);
        Signal::create([
            'alert_id' => $alert->id,
            'client_id' => $client->id,
            'site_id' => $site->id,
            'signal_type_code' => 'fall_detected',
            'severity_hint' => 'critical',
            'occurred_at' => now()->subMinutes(6),
            'payload' => ['confidence' => 0.98],
            'status' => 'processed',
        ]);
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create([
            'client_id' => $client->id,
            'reported_by' => $operator->id,
            'source' => 'sensor',
            'severity' => 'high',
            'status' => 'submitted',
            'submitted_at' => now()->subMinutes(4),
            'control_room_alert_id' => $alert->id,
            'hs_event_id' => null,
        ]));

        $result = app(SensorIncidentBridgeService::class)->confirm($alert, $operator, [
            'immediate_action_taken' => 'Resident checked while the H&S link was repaired.',
        ]);
        $alert->refresh();
        $incident->refresh();
        $event = HsEvent::query()->sole();

        $this->assertTrue($result->is($incident));
        $this->assertSame(
            'Resident checked while the H&S link was repaired.',
            $incident->immediate_action_taken,
        );
        $this->assertSame(ControlRoomAlert::STATUS_CONFIRMED, $alert->status);
        $this->assertSame($confirmedAt->toISOString(), data_get($alert->context, 'confirmed_at'));
        $this->assertSame($acknowledgedAt->toISOString(), $alert->acknowledged_at?->toISOString());
        $this->assertSame($incident->id, data_get($alert->context, 'incident_id'));
        $this->assertSame($event->id, $incident->hs_event_id);
        $this->assertSame($alert->id, $event->control_room_alert_id);
        $this->assertSame(0, AuditLog::query()
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
        $this->assertSame(1, AuditLog::query()
            ->where('action', 'controlRoom.alert.dismiss')
            ->where('auditable_id', $alert->id)
            ->count());
    }

    public function test_stale_open_model_cannot_dismiss_a_confirmed_alert_with_a_claimed_journey(): void
    {
        $operator = User::factory()->create();
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $staleAlert = ControlRoomAlert::factory()->open()->create([
            'source' => 'sensor',
            'alert_type' => 'sensor.fall_detected',
            'client_id' => $client->id,
            'site_id' => $site->id,
            'context' => ['device_zone' => 'Bathroom'],
        ]);
        $signal = Signal::create([
            'alert_id' => $staleAlert->id,
            'client_id' => $client->id,
            'site_id' => $site->id,
            'signal_type_code' => 'fall_detected',
            'severity_hint' => 'high',
            'occurred_at' => now()->subMinute(),
            'payload' => ['confidence' => 0.96],
            'status' => 'processed',
        ]);
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'reported_by' => $operator->id,
            'source' => 'sensor',
            'status' => 'submitted',
            'submitted_at' => now(),
            'control_room_alert_id' => $staleAlert->id,
        ]));
        $event = HsEvent::factory()->forClientIncident($incident)->create([
            'control_room_alert_id' => $staleAlert->id,
            'created_by' => $operator->id,
        ]);
        $incident->updateQuietly(['hs_event_id' => $event->id]);
        DB::table('control_room_alerts')->where('id', $staleAlert->id)->update([
            'status' => ControlRoomAlert::STATUS_CONFIRMED,
            'context' => json_encode([
                'device_zone' => 'Bathroom',
                'incident_id' => $incident->id,
                'confirmed_by' => $operator->name,
                'confirmed_at' => now()->toISOString(),
            ], JSON_THROW_ON_ERROR),
        ]);

        try {
            app(SensorIncidentBridgeService::class)->dismiss(
                $staleAlert,
                'Stale false-positive decision.',
                $operator,
            );
            $this->fail('A stale open model must not overwrite a confirmed incident journey.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('cannot be dismissed', $exception->getMessage());
        }

        $persistedAlert = $staleAlert->fresh();
        $this->assertSame(ControlRoomAlert::STATUS_CONFIRMED, $persistedAlert->status);
        $this->assertSame($incident->id, data_get($persistedAlert->context, 'incident_id'));
        $this->assertNull(data_get($persistedAlert->context, 'dismissed_reason'));
        $this->assertSame($staleAlert->id, $incident->fresh()->control_room_alert_id);
        $this->assertSame($event->id, $incident->fresh()->hs_event_id);
        $this->assertSame($staleAlert->id, $event->fresh()->control_room_alert_id);
        $this->assertSame('processed', $signal->fresh()->status);
        $this->assertSame(0, AuditLog::query()
            ->where('action', 'controlRoom.alert.dismiss')
            ->where('auditable_id', $staleAlert->id)
            ->count());
    }

    public function test_task7_final_gap_signal_category_allows_a_production_source_slug_to_confirm(): void
    {
        $operator = User::factory()->create();
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $signalType = SignalType::query()->create([
            'code' => 'wearable_fall_detected',
            'name' => 'Wearable fall detected',
            'category' => SignalType::CATEGORY_PEOPLE_SAFETY,
            'default_severity' => 'critical',
            'is_active' => true,
        ]);
        $alert = ControlRoomAlert::factory()->open()->create([
            'source' => 'personal_tracker',
            'alert_type' => 'wearable_fall_detected',
            'severity' => 'critical',
            'client_id' => $client->id,
            'site_id' => $site->id,
        ]);
        Signal::query()->create([
            'alert_id' => $alert->id,
            'signal_type_id' => $signalType->id,
            'signal_type_code' => $signalType->code,
            'client_id' => $client->id,
            'site_id' => $site->id,
            'severity_hint' => 'critical',
            'occurred_at' => now()->subMinute(),
            'payload' => ['confidence' => 0.99],
            'status' => 'processed',
        ]);

        $incident = app(SensorIncidentBridgeService::class)->confirm($alert, $operator, [
            'immediate_action_taken' => 'Wearer contacted and immediate assistance arranged.',
        ]);

        $this->assertSame($alert->id, $incident->control_room_alert_id);
        $this->assertSame(ControlRoomAlert::STATUS_CONFIRMED, $alert->fresh()->status);
        $this->assertSame($incident->id, data_get($alert->fresh()->context, 'incident_id'));
        $this->assertDatabaseCount('client_incidents', 1);
        $this->assertDatabaseCount('hs_events', 1);
    }

    public function test_task7_final_gap_non_detection_signal_category_cannot_use_sensor_confirmation(): void
    {
        $operator = User::factory()->create();
        $signalType = SignalType::query()->create([
            'code' => 'training_record_updated',
            'name' => 'Training record updated',
            'category' => SignalType::CATEGORY_COMPLIANCE,
            'default_severity' => 'low',
            'is_active' => true,
        ]);
        $alert = ControlRoomAlert::factory()->open()->create([
            'source' => 'external_hr',
            'alert_type' => 'training_record_updated',
        ]);
        Signal::query()->create([
            'alert_id' => $alert->id,
            'signal_type_id' => $signalType->id,
            'signal_type_code' => $signalType->code,
            'occurred_at' => now(),
            'payload' => [],
            'status' => 'processed',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Alert {$alert->id} is not a sensor alert.");

        app(SensorIncidentBridgeService::class)->confirm($alert, $operator);
    }
}
