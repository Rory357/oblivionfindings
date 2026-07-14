<?php

namespace Tests\Unit\ControlRoom;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ControlRoom\AlertSla;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoom\SignalType;
use App\Models\ControlRoom\SlaDefinition;
use App\Models\ControlRoomAlert;
use App\Models\Site;
use App\Models\User;
use App\Services\ControlRoom\ControlRoomAlertLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

class ControlRoomAlertLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    private ControlRoomAlertLifecycleService $service;

    private User $actor;

    private Site $site;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-07-14 08:00:00'));
        $this->site = Site::factory()->create(['tenant_id' => 1]);
        $this->client = Client::factory()->create([
            'organization_id' => 1,
            'site_id' => $this->site->id,
        ]);
        $this->actor = User::factory()->create([
            'organization_id' => 1,
            'approved_at' => now(),
        ]);
        HrEmployeeProfile::factory()->create([
            'tenant_id' => 1,
            'user_id' => $this->actor->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
        ]);
        $this->service = app(ControlRoomAlertLifecycleService::class);
    }

    public function test_human_lifecycle_is_sequential_and_records_each_sla_clock_once(): void
    {
        $alert = ControlRoomAlert::factory()->open()->withNotes('Original sensor payload')->create([
            'triggered_at' => now(),
        ]);
        $sla = $this->attachSla($alert);

        $this->service->acknowledge($alert, $this->actor);
        $this->travel(4)->minutes();
        $this->service->startTriage($alert, $this->actor);
        $this->travel(11)->minutes();
        $this->service->resolve($alert, $this->actor, 'Scene is safe and staff have been briefed.', 'controlled');
        $this->travel(1)->minute();
        $this->service->close($alert, $this->actor, 'Supervisor checked the response record.');

        $alert->refresh();
        $sla->refresh();

        $this->assertSame(ControlRoomAlert::STATUS_CLOSED, $alert->status);
        $this->assertSame('Original sensor payload', $alert->notes);
        $this->assertSame($this->actor->id, $alert->acknowledged_by_user_id);
        $this->assertSame($this->actor->id, $alert->resolved_by_user_id);
        $this->assertSame($this->actor->id, $alert->closed_by_user_id);
        $this->assertNotNull($sla->acknowledged_at);
        $this->assertNotNull($sla->responded_at);
        $this->assertNotNull($sla->resolved_at);

        $activity = collect($alert->context['activity_log'] ?? []);
        $this->assertTrue($activity->contains(fn (array $entry): bool => $entry['content'] === 'Scene is safe and staff have been briefed.'));
        $this->assertTrue($activity->contains(fn (array $entry): bool => $entry['content'] === 'Supervisor checked the response record.'));

        $this->assertSame(1, AuditLog::query()->where('action', 'controlRoom.alert.acknowledge')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'controlRoom.alert.triage')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'controlRoom.alert.resolve')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'controlRoom.alert.close')->count());
    }

    public function test_acknowledge_and_triage_notes_are_appended_inside_the_lifecycle_without_overwriting_source_notes(): void
    {
        $alert = ControlRoomAlert::factory()->open()->withNotes('Immutable sensor source payload')->create([
            'context' => ['source_marker' => 'preserved'],
        ]);

        $this->service->acknowledge(
            $alert,
            $this->actor,
            'Acknowledged after confirming the caller and location.',
        );
        $this->service->startTriage(
            $alert->fresh(),
            $this->actor,
            'Triaging immediate controls with the on-call lead.',
        );

        $alert->refresh();
        $activity = collect($alert->context['activity_log'] ?? []);
        $acknowledgeAudit = AuditLog::query()
            ->where('action', 'controlRoom.alert.acknowledge')
            ->latest('id')
            ->firstOrFail();
        $triageAudit = AuditLog::query()
            ->where('action', 'controlRoom.alert.triage')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('Immutable sensor source payload', $alert->notes);
        $this->assertSame('preserved', $alert->context['source_marker'] ?? null);
        $this->assertTrue($activity->contains(
            fn (array $entry): bool => ($entry['transition'] ?? null) === 'acknowledge'
                && ($entry['content'] ?? null) === 'Acknowledged after confirming the caller and location.',
        ));
        $this->assertTrue($activity->contains(
            fn (array $entry): bool => ($entry['transition'] ?? null) === 'triage'
                && ($entry['content'] ?? null) === 'Triaging immediate controls with the on-call lead.',
        ));
        $this->assertSame(
            'Acknowledged after confirming the caller and location.',
            $acknowledgeAudit->meta['operator_note'] ?? null,
        );
        $this->assertSame(
            'Triaging immediate controls with the on-call lead.',
            $triageAudit->meta['operator_note'] ?? null,
        );
    }

    public function test_human_user_cannot_jump_from_open_to_triaging_or_resolved(): void
    {
        $alert = ControlRoomAlert::factory()->open()->create();

        foreach (['triage', 'resolve'] as $transition) {
            try {
                if ($transition === 'triage') {
                    $this->service->startTriage($alert->fresh(), $this->actor);
                } else {
                    $this->service->resolve($alert->fresh(), $this->actor, 'Attempted shortcut.', 'shortcut');
                }

                $this->fail("The {$transition} shortcut should have been rejected.");
            } catch (InvalidArgumentException) {
                $this->assertSame(ControlRoomAlert::STATUS_OPEN, $alert->fresh()->status);
            }
        }
    }

    public function test_confirmed_sensor_detection_completes_response_clocks_and_can_then_resolve(): void
    {
        $alert = ControlRoomAlert::factory()->open()->create([
            'source' => 'sensor',
            'triggered_at' => now(),
        ]);
        $sla = $this->attachSla($alert);

        $this->travel(3)->minutes();
        $this->service->confirmSensor($alert, $this->actor);

        $this->assertSame(ControlRoomAlert::STATUS_CONFIRMED, $alert->fresh()->status);
        $this->assertNotNull($sla->fresh()->acknowledged_at);
        $this->assertNotNull($sla->fresh()->responded_at);

        $this->service->resolve($alert->fresh(), $this->actor, 'The fall was confirmed and immediate controls are complete.', 'sensor_confirmed');

        $this->assertSame(ControlRoomAlert::STATUS_RESOLVED, $alert->fresh()->status);
        $this->assertNotNull($sla->fresh()->resolved_at);
    }

    public function test_dismissed_sensor_detection_is_terminal_and_excluded_from_sla_compliance(): void
    {
        $alert = ControlRoomAlert::factory()->open()->create([
            'source' => 'sensor',
            'triggered_at' => now()->subHour(),
        ]);
        $sla = $this->attachSla($alert);

        $this->service->dismissSensor($alert, $this->actor, 'Device was being tested after maintenance.');

        $alert->refresh();
        $sla->refresh();

        $this->assertSame(ControlRoomAlert::STATUS_DISMISSED, $alert->status);
        $this->assertTrue($alert->isTerminal());
        $this->assertFalse($alert->isActionable());
        $this->assertSame(AlertSla::ENDED_DISMISSED, $sla->ended_as);
        $this->assertFalse($sla->isApplicable());
        $this->assertSame(0, ControlRoomAlert::query()->actionable()->count());
        $this->assertSame(0, AlertSla::query()->applicable()->count());
    }

    public function test_signal_backed_detection_uses_its_canonical_type_instead_of_a_literal_source_slug(): void
    {
        $type = SignalType::query()->create([
            'code' => 'lifecycle-real-fall-detection',
            'name' => 'Fall detected',
            'category' => SignalType::CATEGORY_PEOPLE_SAFETY,
            'default_severity' => 'critical',
            'is_active' => true,
        ]);
        $alert = ControlRoomAlert::factory()->open()->create([
            'source' => 'personal_tracker',
            'alert_type' => 'Fall detected',
        ]);
        Signal::query()->create([
            'alert_id' => $alert->id,
            'signal_type_id' => $type->id,
            'signal_type_code' => $type->code,
            'severity_hint' => 'critical',
            'occurred_at' => now(),
            'status' => 'processed',
        ]);

        $this->service->confirmSensor($alert, $this->actor);

        $this->assertSame(ControlRoomAlert::STATUS_CONFIRMED, $alert->fresh()->status);
    }

    public function test_sensor_only_transitions_reject_non_sensor_alerts_without_mutation(): void
    {
        foreach (['confirm', 'dismiss'] as $transition) {
            $alert = ControlRoomAlert::factory()->open()->create([
                'source' => 'manual',
                'context' => ['source_marker' => 'preserved'],
            ]);

            try {
                if ($transition === 'confirm') {
                    $this->service->confirmSensor($alert, $this->actor);
                } else {
                    $this->service->dismissSensor($alert, $this->actor, 'This must remain a manual alert.');
                }

                $this->fail("The {$transition} sensor transition should reject a non-sensor alert.");
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('not a sensor alert', $exception->getMessage());
            }

            $alert->refresh();
            $this->assertSame(ControlRoomAlert::STATUS_OPEN, $alert->status);
            $this->assertSame('preserved', data_get($alert->context, 'source_marker'));
            $this->assertNull($alert->resolved_at);
            $this->assertNull($alert->resolution_code);
        }
    }

    public function test_incident_reopen_starts_a_new_sla_cycle_without_rewriting_history(): void
    {
        $alert = ControlRoomAlert::factory()->closed()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'triggered_at' => now()->subHours(2),
            'resolution_code' => 'initial_response_complete',
        ]);
        $incident = ClientIncident::factory()->reviewed()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'control_room_alert_id' => $alert->id,
            'reopened_at' => now(),
            'reopened_by' => $this->actor->id,
            'reopened_reason' => 'New witness information changes the immediate risk picture.',
        ]);
        $alert->forceFill([
            'context' => [
                'journey_attention' => [
                    'type' => 'incident_reopened',
                    'incident_id' => $incident->id,
                    'requires_operational_reopen' => true,
                ],
            ],
        ])->save();
        $sla = $this->attachSla($alert);
        $sla->forceFill([
            'acknowledged_at' => now()->subMinutes(110),
            'responded_at' => now()->subMinutes(105),
            'resolved_at' => now()->subMinutes(30),
        ])->save();

        $this->service->reopenForIncident(
            $alert,
            $incident,
            $this->actor,
            'Reopen operational response for the new witness information.',
        );

        $alert->refresh();
        $sla->refresh();

        $this->assertSame(ControlRoomAlert::STATUS_TRIAGING, $alert->status);
        $this->assertNull($alert->resolved_at);
        $this->assertNull($alert->resolved_by_user_id);
        $this->assertNull($alert->closed_at);
        $this->assertNull($alert->closed_by_user_id);
        $this->assertNull($alert->resolution_code);
        $this->assertSame(2, $sla->cycle_number);
        $this->assertCount(1, $sla->cycle_history ?? []);
        $this->assertSame(AlertSla::ENDED_REOPENED, $sla->cycle_history[0]['ended_as']);
        $this->assertNull($sla->ended_as);
        $this->assertNotNull($sla->acknowledged_at);
        $this->assertNotNull($sla->responded_at);
        $this->assertNull($sla->resolved_at);
    }

    public function test_incident_reopen_creates_a_current_sla_cycle_when_the_alert_has_no_sla_row(): void
    {
        $alert = ControlRoomAlert::factory()->closed()->create([
            'alert_type' => 'incident.manual',
            'severity' => 'high',
            'source' => 'incident',
            'triggered_at' => now()->subHours(3),
        ]);
        $incident = $this->reopenedIncidentAwaitingOperationalAction($alert);
        SlaDefinition::create([
            'name' => 'Reopened incident SLA',
            'code' => 'reopened-incident-'.$alert->id,
            'alert_types' => ['incident.manual'],
            'severities' => ['high'],
            'sources' => ['incident'],
            'acknowledge_target_minutes' => 5,
            'response_target_minutes' => 10,
            'resolution_target_minutes' => 30,
            'is_active' => true,
        ]);
        $reopenedAt = now();

        $this->service->reopenForIncident(
            $alert,
            $incident,
            $this->actor,
            'Start a new operational cycle for the new evidence.',
        );

        $sla = $alert->fresh()->sla;
        $this->assertNotNull($sla);
        $this->assertSame(1, $sla->cycle_number);
        $this->assertTrue($sla->cycle_started_at->equalTo($reopenedAt));
        $this->assertTrue($sla->acknowledged_at->equalTo($reopenedAt));
        $this->assertTrue($sla->responded_at->equalTo($reopenedAt));
        $this->assertNull($sla->resolved_at);
        $this->assertSame([], $sla->cycle_history ?? []);
    }

    public function test_incident_reopen_rolls_back_when_no_current_sla_definition_can_start_the_cycle(): void
    {
        $alert = ControlRoomAlert::factory()->closed()->create([
            'alert_type' => 'incident.unmatched',
            'severity' => 'high',
            'source' => 'incident',
            'resolution_code' => 'initial_response_complete',
        ]);
        $incident = $this->reopenedIncidentAwaitingOperationalAction($alert);

        try {
            $this->service->reopenForIncident(
                $alert,
                $incident,
                $this->actor,
                'Attempt a reopen without an SLA definition.',
            );
            $this->fail('An operational reopen without a current SLA definition must fail closed.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('SLA definition', $exception->getMessage());
        }

        $alert->refresh();
        $this->assertSame(ControlRoomAlert::STATUS_CLOSED, $alert->status);
        $this->assertSame('initial_response_complete', $alert->resolution_code);
        $this->assertSame('incident_reopened', data_get($alert->context, 'journey_attention.type'));
        $this->assertNull($alert->sla);
        $this->assertSame(
            0,
            AuditLog::query()
                ->where('action', 'controlRoom.alert.reopenForIncident')
                ->where('auditable_id', $alert->id)
                ->count(),
        );
    }

    public function test_incident_reopen_rejects_an_inconsistent_dismissed_sla_cycle(): void
    {
        $alert = ControlRoomAlert::factory()->closed()->create([
            'resolution_code' => 'initial_response_complete',
        ]);
        $incident = $this->reopenedIncidentAwaitingOperationalAction($alert);
        $sla = $this->attachSla($alert);
        $sla->endAsDismissed(now()->subMinute());
        $historyBefore = $sla->fresh()->cycle_history;

        try {
            $this->service->reopenForIncident(
                $alert,
                $incident,
                $this->actor,
                'Attempt to restart an internally inconsistent dismissed clock.',
            );
            $this->fail('A dismissed SLA cycle must not be silently restarted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('dismissed SLA clock', $exception->getMessage());
        }

        $alert->refresh();
        $sla->refresh();
        $this->assertSame(ControlRoomAlert::STATUS_CLOSED, $alert->status);
        $this->assertSame('initial_response_complete', $alert->resolution_code);
        $this->assertSame(AlertSla::ENDED_DISMISSED, $sla->ended_as);
        $this->assertSame($historyBefore, $sla->cycle_history);
    }

    private function reopenedIncidentAwaitingOperationalAction(ControlRoomAlert $alert): ClientIncident
    {
        $incident = ClientIncident::factory()->reviewed()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'control_room_alert_id' => $alert->id,
            'reopened_at' => now(),
            'reopened_by' => $this->actor->id,
            'reopened_reason' => 'New evidence changes the immediate risk picture.',
        ]);
        $alert->forceFill([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'context' => [
                'journey_attention' => [
                    'type' => 'incident_reopened',
                    'incident_id' => $incident->id,
                    'requires_operational_reopen' => true,
                ],
            ],
        ])->save();

        return $incident;
    }

    private function attachSla(ControlRoomAlert $alert): AlertSla
    {
        $definition = SlaDefinition::create([
            'name' => 'Lifecycle test SLA '.$alert->id,
            'code' => 'lifecycle-'.$alert->id,
            'acknowledge_target_minutes' => 5,
            'response_target_minutes' => 10,
            'resolution_target_minutes' => 30,
            'is_active' => true,
        ]);

        return AlertSla::createFromDefinition($alert, $definition);
    }
}
