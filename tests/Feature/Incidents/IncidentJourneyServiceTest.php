<?php

namespace Tests\Feature\Incidents;

use App\Jobs\CheckControlRoomSlaBreaches;
use App\Jobs\Notifications\DeliverControlRoomAlertNotificationJob;
use App\Jobs\Notifications\RecoverControlRoomAlertNotificationsJob;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ControlRoom\AlertQueue;
use App\Models\ControlRoom\AlertSla;
use App\Models\ControlRoom\AlertTask;
use App\Models\ControlRoom\Communication;
use App\Models\ControlRoom\EvidencePack;
use App\Models\ControlRoom\Playbook;
use App\Models\ControlRoom\PlaybookRun;
use App\Models\ControlRoom\PlaybookStep;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoom\SlaDefinition;
use App\Models\ControlRoom\TriageQueue;
use App\Models\ControlRoomAlert;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\IncidentFollowup;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Notifications\ControlRoomAlertNotification;
use App\Observers\ClientIncidentObserver;
use App\Services\ControlRoom\AlertAutomationService;
use App\Services\ControlRoom\AlertWorkspaceService;
use App\Services\ControlRoom\ControlRoomNotificationService;
use App\Services\ControlRoom\ControlRoomReportService;
use App\Services\ControlRoom\IncidentAlertOperationalInitializer;
use App\Services\HealthSafety\HsEventService;
use App\Services\Incidents\IncidentJourney;
use App\Services\Incidents\IncidentJourneyService;
use App\Support\Journeys\JourneyGate;
use Database\Seeders\RbacSeeder;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

class IncidentJourneyServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_incident_journey_service_is_available(): void
    {
        $this->assertInstanceOf(
            IncidentJourneyService::class,
            app(IncidentJourneyService::class),
        );
    }

    public function test_incident_journey_is_a_readonly_value_object_that_allows_legacy_gaps(): void
    {
        $incident = $this->incidentWithoutEvents();
        $journey = new IncidentJourney($incident, null, null);

        $this->assertTrue((new \ReflectionClass($journey))->isReadOnly());
        $this->assertTrue($journey->incident->is($incident));
        $this->assertNull($journey->alert);
        $this->assertNull($journey->hsEvent);
    }

    public function test_alert_read_resolution_rejects_a_foreign_client_claim(): void
    {
        $alertClient = Client::factory()->create();
        $incidentClient = Client::factory()->create();
        $incident = $this->incidentWithoutEvents([
            'client_id' => $incidentClient->id,
        ]);
        $alert = ControlRoomAlert::factory()->create([
            'client_id' => $alertClient->id,
            'context' => ['incident_id' => $incident->id],
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('alert client does not match');

        app(IncidentJourneyService::class)->incidentForAlert($alert);
    }

    public function test_incident_close_gate_rejects_a_poisoned_direct_hs_link(): void
    {
        $incident = $this->incidentWithoutEvents([
            'status' => 'reviewed',
            'reviewed_at' => now(),
            'reviewed_by' => User::factory()->create()->id,
        ]);
        $foreignEvent = HsEvent::factory()->closed()->create();
        $incident->updateQuietly(['hs_event_id' => $foreignEvent->id]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('canonical incident tuple');

        app(IncidentJourneyService::class)->closeGate($incident);
    }

    public function test_incident_close_gate_rejects_a_direct_hs_link_to_a_different_alert(): void
    {
        $actor = User::factory()->create();
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $incidentAlert = ControlRoomAlert::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
        ]);
        $foreignAlert = ControlRoomAlert::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
        ]);
        $incident = $this->incidentWithoutEvents([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'status' => 'reviewed',
            'reviewed_at' => now(),
            'reviewed_by' => $actor->id,
            'control_room_alert_id' => $incidentAlert->id,
        ]);
        $event = HsEvent::factory()
            ->forClientIncident($incident)
            ->closed()
            ->create([
                'client_id' => $client->id,
                'site_id' => $site->id,
                'control_room_alert_id' => $foreignAlert->id,
            ]);
        $incident->updateQuietly(['hs_event_id' => $event->id]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('different direct alert');

        app(IncidentJourneyService::class)->closeGate($incident);
    }

    public function test_incident_close_gate_requires_review_followups_investigation_and_linked_hs_closure(): void
    {
        $actor = User::factory()->create();
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $incident = $this->submittedIncidentWithoutEvents($client, $site, $actor, [
            'status' => 'reviewed',
            'reviewed_at' => now(),
            'reviewed_by' => $actor->id,
            'severity' => 'high',
            'investigation_status' => 'in_progress',
        ]);
        $followup = IncidentFollowup::factory()->create([
            'client_incident_id' => $incident->id,
            'completed_at' => null,
        ]);
        $event = HsEvent::factory()->forClientIncident($incident)->create([
            'status' => HsEvent::STATUS_OPEN,
            'investigation_required' => true,
        ]);
        $investigation = HsInvestigation::factory()->create([
            'hs_event_id' => $event->id,
        ]);
        $incident->updateQuietly(['hs_event_id' => $event->id]);
        $service = app(IncidentJourneyService::class);

        $blocked = $service->closeGate($incident);

        $this->assertInstanceOf(JourneyGate::class, $blocked);
        $this->assertFalse($blocked->allowed);
        $this->assertTrue(collect($blocked->requirements)->firstWhere('key', 'incident_review')['complete']);
        $this->assertFalse(collect($blocked->requirements)->firstWhere('key', 'incident_followups')['complete']);
        $this->assertFalse(collect($blocked->requirements)->firstWhere('key', 'incident_investigation')['complete']);
        $this->assertSame(
            "/health-safety/events/{$event->id}?section=investigation",
            collect($blocked->requirements)->firstWhere('key', 'incident_investigation')['href'],
        );
        $this->assertFalse(collect($blocked->requirements)->firstWhere('key', 'health_safety_governance')['complete']);
        foreach ($blocked->requirements as $requirement) {
            $this->assertSame(['key', 'complete', 'label', 'href'], array_keys($requirement));
        }

        $followup->update(['completed_at' => now()]);
        $investigation->forceFill([
            'status' => HsInvestigation::STATUS_COMPLETED,
            'completed_at' => now(),
        ])->save();
        $event->forceFill([
            'status' => HsEvent::STATUS_CLOSED,
            'closed_at' => now(),
            'closed_by' => $actor->id,
            'closure_summary' => 'Governance work is complete.',
        ])->saveQuietly();

        $ready = $service->closeGate($incident->fresh());
        $this->assertTrue($ready->allowed);
        $this->assertTrue(collect($ready->requirements)->every('complete'));
    }

    public function test_existing_triaging_alert_submits_one_linked_journey_and_retries_without_disturbing_operations(): void
    {
        $actor = User::factory()->create();
        $otherReporter = User::factory()->create();
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $occurredAt = now()->subMinutes(20);
        $originalContext = [
            'signal_id' => 812,
            'workflow' => ['step' => 'operator_triage'],
            'evidence_key' => 'keep-me',
        ];

        $alert = ControlRoomAlert::factory()->triaging()->high()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'notes' => 'Operator notes must remain intact.',
            'playbook_run_id' => 987654,
            'priority' => 'urgent',
            'context' => $originalContext,
        ]);
        $task = AlertTask::create([
            'alert_id' => $alert->id,
            'title' => 'Keep the original response task',
            'status' => AlertTask::STATUS_IN_PROGRESS,
            'priority' => 'high',
        ]);
        $evidence = EvidencePack::create([
            'alert_id' => $alert->id,
            'title' => 'Original evidence pack',
            'status' => 'collecting',
            'items' => [['type' => 'note', 'value' => 'preserve']],
            'item_count' => 1,
        ]);
        $sla = AlertSla::create([
            'alert_id' => $alert->id,
            'acknowledge_target_minutes' => 5,
            'resolution_target_minutes' => 60,
            'acknowledge_deadline' => now()->addMinutes(5),
            'resolution_deadline' => now()->addHour(),
        ]);

        $input = $this->incidentInput($client, $site, [
            'occurred_at' => $occurredAt,
            'metadata' => ['existing_metadata' => 'preserved'],
            'source' => 'manual',
            'status' => 'closed',
            'reported_by' => $otherReporter->id,
            'control_room_alert_id' => null,
            'hs_event_id' => null,
        ]);

        $first = app(IncidentJourneyService::class)->submitFromAlert($alert, $input, $actor);
        $second = app(IncidentJourneyService::class)->submitFromAlert($alert->fresh(), $input, $actor);

        $this->assertTrue($second->incident->is($first->incident));
        $this->assertTrue($second->alert->is($first->alert));
        $this->assertTrue($second->hsEvent->is($first->hsEvent));
        $this->assertDatabaseCount('client_incidents', 1);
        $this->assertDatabaseCount('hs_events', 1);
        $this->assertDatabaseCount('control_room_alerts', 1);

        $incident = $first->incident->fresh();
        $hsEvent = $first->hsEvent->fresh();
        $alert = $alert->fresh();

        $this->assertSame('control_room', $incident->source);
        $this->assertSame('submitted', $incident->status);
        $this->assertNotNull($incident->submitted_at);
        $this->assertSame($actor->id, $incident->reported_by);
        $this->assertSame($client->id, $incident->client_id);
        $this->assertSame($site->id, $incident->site_id);
        $this->assertSame($alert->id, $incident->control_room_alert_id);
        $this->assertSame($hsEvent->id, $incident->hs_event_id);
        $this->assertSame('preserved', $incident->metadata['existing_metadata']);
        $this->assertSame($alert->id, $incident->metadata['journey']['control_room_alert_id']);
        $this->assertSame('high', $incident->metadata['journey']['original_alert_severity']);

        $this->assertSame($incident->id, $hsEvent->source_id);
        $this->assertSame(ClientIncident::class, $hsEvent->source_type);
        $this->assertSame($alert->id, $hsEvent->control_room_alert_id);
        $this->assertSame($site->id, $hsEvent->site_id);
        $this->assertSame($client->id, $hsEvent->client_id);
        $this->assertSame($actor->id, $hsEvent->staff_id);
        $this->assertNull($hsEvent->worksafe_notifiable);
        $this->assertNull($hsEvent->worksafe_decided_at);
        $this->assertSame(HsEvent::HANDOVER_AWAITING_ACCEPTANCE, $hsEvent->handover_status);

        $this->assertSame(ControlRoomAlert::STATUS_TRIAGING, $alert->status);
        $this->assertSame('high', $alert->severity);
        $this->assertSame('Operator notes must remain intact.', $alert->notes);
        $this->assertSame(987654, $alert->playbook_run_id);
        $this->assertSame('urgent', $alert->priority);
        $this->assertEquals(array_merge($originalContext, ['incident_id' => $incident->id]), $alert->context);
        $this->assertSame($task->id, $alert->tasks()->sole()->id);
        $this->assertSame($evidence->id, $alert->evidencePacks()->sole()->id);
        $this->assertSame($sla->id, $alert->sla()->sole()->id);
        $this->assertSame('Keep the original response task', $task->fresh()->title);
        $this->assertSame([['type' => 'note', 'value' => 'preserve']], $evidence->fresh()->items);
    }

    public function test_critical_alert_keeps_governance_critical_while_incident_uses_supported_high_severity(): void
    {
        $actor = User::factory()->create();
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $alert = ControlRoomAlert::factory()->critical()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'context' => ['original' => 'context'],
        ]);

        $journey = app(IncidentJourneyService::class)->submitFromAlert(
            $alert,
            $this->incidentInput($client, $site, [
                'severity' => 'critical',
                'metadata' => ['original' => 'metadata'],
            ]),
            $actor,
        );

        $this->assertSame('high', $journey->incident->fresh()->severity);
        $this->assertSame('critical', $journey->alert->fresh()->severity);
        $this->assertSame('critical', $journey->hsEvent->fresh()->severity);
        $this->assertSame(
            'critical',
            $journey->incident->fresh()->metadata['journey']['original_alert_severity'],
        );
        $this->assertSame('metadata', $journey->incident->fresh()->metadata['original']);
        $this->assertSame('context', $journey->alert->fresh()->context['original']);
    }

    public function test_serious_alert_submission_requires_immediate_action_at_the_domain_boundary(): void
    {
        $actor = User::factory()->create();
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $alert = ControlRoomAlert::factory()->high()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(
            'Immediate action is required for a high or critical Control Room incident.',
        );

        app(IncidentJourneyService::class)->submitFromAlert(
            $alert,
            $this->incidentInput($client, $site, [
                'immediate_action_taken' => '   ',
            ]),
            $actor,
        );
    }

    public function test_serious_alert_submission_accepts_explicit_no_control_truth(): void
    {
        $actor = User::factory()->create();
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $alert = ControlRoomAlert::factory()->critical()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
        ]);

        $journey = app(IncidentJourneyService::class)->submitFromAlert(
            $alert,
            $this->incidentInput($client, $site, [
                'immediate_action_taken' => 'No immediate control was possible',
            ]),
            $actor,
        );

        $this->assertSame(
            'No immediate control was possible',
            $journey->incident->fresh()->immediate_action_taken,
        );
    }

    public function test_low_and_medium_alert_submissions_allow_no_immediate_action(): void
    {
        foreach (['low', 'medium'] as $severity) {
            $actor = User::factory()->create();
            $site = Site::factory()->create();
            $client = Client::factory()->create(['site_id' => $site->id]);
            $alert = ControlRoomAlert::factory()->create([
                'severity' => $severity,
                'client_id' => $client->id,
                'site_id' => $site->id,
            ]);

            $journey = app(IncidentJourneyService::class)->submitFromAlert(
                $alert,
                $this->incidentInput($client, $site, [
                    'severity' => $severity,
                    'immediate_action_taken' => null,
                ]),
                $actor,
            );

            $this->assertNull(
                $journey->incident->fresh()->immediate_action_taken,
            );
        }
    }

    public function test_task12_review_effective_high_incident_severity_is_enforced_at_the_domain_boundary(): void
    {
        $actor = User::factory()->create();
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $alert = ControlRoomAlert::factory()->create([
            'severity' => 'low',
            'client_id' => $client->id,
            'site_id' => $site->id,
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(
            'Immediate action is required for a high or critical Control Room incident.',
        );

        app(IncidentJourneyService::class)->submitFromAlert(
            $alert,
            $this->incidentInput($client, $site, [
                'severity' => 'high',
                'immediate_action_taken' => null,
            ]),
            $actor,
        );
    }

    public function test_task12_review_attach_rejects_a_submitted_serious_incident_without_immediate_action(): void
    {
        $actor = User::factory()->create();
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $alert = ControlRoomAlert::factory()->high()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
        ]);
        $incident = $this->incidentWithoutEvents([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'reported_by' => $actor->id,
            'severity' => 'high',
            'status' => 'submitted',
            'submitted_at' => now(),
            'immediate_action_taken' => null,
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(
            'Immediate action is required for a high or critical Control Room incident.',
        );

        app(IncidentJourneyService::class)->attachAlertToIncident(
            $incident,
            $alert,
            $actor,
        );
    }

    public function test_task12_review_critical_alert_floor_applies_to_a_submitted_medium_incident_repair(): void
    {
        $actor = User::factory()->create();
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $alert = ControlRoomAlert::factory()->critical()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
        ]);
        $this->incidentWithoutEvents([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'reported_by' => $actor->id,
            'severity' => 'medium',
            'status' => 'submitted',
            'submitted_at' => now(),
            'control_room_alert_id' => $alert->id,
            'immediate_action_taken' => null,
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(
            'Immediate action is required for a high or critical Control Room incident.',
        );

        app(IncidentJourneyService::class)->submitFromAlert(
            $alert,
            [],
            $actor,
        );
    }

    public function test_reviewed_and_closed_incident_retries_are_link_only_and_preserve_the_record(): void
    {
        foreach (['reviewed', 'closed'] as $status) {
            $actor = User::factory()->create();
            $reporter = User::factory()->create();
            $reviewer = User::factory()->create();
            $closer = User::factory()->create();
            $site = Site::factory()->create();
            $client = Client::factory()->create(['site_id' => $site->id]);
            $submittedAt = now()->subDays(3);
            $reviewedAt = now()->subDays(2);
            $closedAt = $status === 'closed' ? now()->subDay() : null;
            $occurredAt = now()->subDays(4);
            $incident = $this->incidentWithoutEvents([
                'client_id' => $client->id,
                'site_id' => $site->id,
                'reported_by' => $reporter->id,
                'type' => 'medication_error',
                'source' => 'sensor',
                'severity' => 'medium',
                'status' => $status,
                'submitted_at' => $submittedAt,
                'occurred_at' => $occurredAt,
                'title' => 'Canonical existing title',
                'description' => 'Canonical existing facts.',
                'immediate_action_taken' => 'Canonical immediate controls.',
                'witnesses' => 'Canonical witness.',
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => $reviewedAt,
                'review_notes' => 'Canonical review notes.',
                'closed_by' => $closer->id,
                'closed_at' => $closedAt,
                'closed_outcome' => $status === 'closed' ? 'Controls verified' : null,
                'closed_notes' => $status === 'closed' ? 'Canonical closure notes.' : null,
                'metadata' => [
                    'journey' => [
                        'source' => 'sensor_detection',
                        'original_alert_source' => 'personal_tracker',
                    ],
                    'canonical' => 'keep',
                ],
            ]);
            $alert = ControlRoomAlert::factory()->triaging()->create([
                'source' => 'manual',
                'client_id' => $client->id,
                'site_id' => $site->id,
                'context' => ['incident_id' => $incident->id, 'keep' => $status],
                'notes' => 'Operational notes stay unchanged.',
            ]);
            $preservedFields = [
                'status',
                'source',
                'reported_by',
                'submitted_at',
                'reviewed_by',
                'reviewed_at',
                'review_notes',
                'closed_by',
                'closed_at',
                'closed_outcome',
                'closed_notes',
                'type',
                'severity',
                'occurred_at',
                'title',
                'description',
                'immediate_action_taken',
                'witnesses',
            ];
            $incident = $incident->fresh();
            $before = Arr::only($incident->getAttributes(), $preservedFields);
            $metadataBefore = $incident->metadata;

            $journey = app(IncidentJourneyService::class)->submitFromAlert(
                $alert,
                $this->incidentInput($client, $site, [
                    'type' => 'fall',
                    'severity' => 'high',
                    'title' => 'Retry must not replace title',
                    'description' => 'Retry must not replace facts.',
                    'immediate_action_taken' => 'Retry must not replace controls.',
                    'witnesses' => 'Retry must not replace witnesses.',
                    'occurred_at' => now(),
                    'metadata' => ['canonical' => 'replace-attempt'],
                    'source' => 'control_room',
                ]),
                $actor,
            );

            $this->assertEquals(
                $before,
                Arr::only($journey->incident->fresh()->getAttributes(), $preservedFields),
                "{$status} incident was mutated by retry",
            );
            $this->assertSame($metadataBefore, $journey->incident->fresh()->metadata);
            $this->assertSame($alert->id, $journey->incident->control_room_alert_id);
            $this->assertSame($journey->hsEvent->id, $journey->incident->hs_event_id);
            $this->assertSame($incident->id, $alert->fresh()->context['incident_id']);
            $this->assertSame($status, $alert->fresh()->context['keep']);
        }

        $this->assertDatabaseCount('client_incidents', 2);
        $this->assertDatabaseCount('control_room_alerts', 2);
        $this->assertDatabaseCount('hs_events', 2);
    }

    public function test_sensor_signal_provenance_is_derived_from_the_alert_and_stable_on_retry(): void
    {
        $actor = User::factory()->create();
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $alert = ControlRoomAlert::factory()->triaging()->create([
            'source' => 'personal_tracker',
            'alert_type' => 'sensor.fall_detected',
            'client_id' => $client->id,
            'site_id' => $site->id,
            'context' => ['device_event' => 'fall'],
        ]);
        Signal::create([
            'alert_id' => $alert->id,
            'client_id' => $client->id,
            'site_id' => $site->id,
            'signal_type_code' => 'fall_detected',
            'severity_hint' => 'high',
            'occurred_at' => now()->subMinutes(5),
            'payload' => ['confidence' => 0.96],
            'status' => 'processed',
        ]);

        $first = app(IncidentJourneyService::class)->submitFromAlert(
            $alert,
            $this->incidentInput($client, $site, ['source' => 'manual']),
            $actor,
        );
        $second = app(IncidentJourneyService::class)->submitFromAlert(
            $alert->fresh(),
            $this->incidentInput($client, $site, ['source' => 'control_room']),
            $actor,
        );

        $this->assertTrue($second->incident->is($first->incident));
        $this->assertSame('personal_tracker', $alert->fresh()->source);
        $this->assertSame('sensor', $first->incident->fresh()->source);
        $this->assertSame('sensor', $second->incident->fresh()->source);
        $this->assertSame(
            'personal_tracker',
            $second->incident->fresh()->metadata['journey']['original_alert_source'],
        );
        $this->assertSame($alert->id, $second->incident->control_room_alert_id);
        $this->assertSame($second->hsEvent->id, $second->incident->hs_event_id);
        $this->assertSame($alert->id, $second->hsEvent->control_room_alert_id);
        $this->assertDatabaseCount('control_room_alerts', 1);
        $this->assertDatabaseCount('client_incidents', 1);
        $this->assertDatabaseCount('hs_events', 1);
    }

    public function test_signal_backed_non_sensor_alert_remains_an_interactive_control_room_incident(): void
    {
        $actor = User::factory()->create();
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $alert = ControlRoomAlert::factory()->triaging()->create([
            'source' => 'medication',
            'alert_type' => 'medication.missed_dose',
            'client_id' => $client->id,
            'site_id' => $site->id,
            'context' => ['medication_id' => 314],
        ]);
        Signal::create([
            'alert_id' => $alert->id,
            'client_id' => $client->id,
            'site_id' => $site->id,
            'signal_type_code' => 'medication_missed_dose',
            'severity_hint' => 'high',
            'occurred_at' => now()->subMinutes(5),
            'payload' => ['medication_id' => 314],
            'status' => 'processed',
        ]);

        $journey = app(IncidentJourneyService::class)->submitFromAlert(
            $alert,
            $this->incidentInput($client, $site),
            $actor,
        );
        $incident = $journey->incident->fresh();

        $this->assertSame('control_room', $incident->source);
        $this->assertTrue($incident->interactive);
        $this->assertSame('control_room_alert', $incident->metadata['journey']['source']);
        $this->assertSame('medication', $incident->metadata['journey']['original_alert_source']);
        $this->assertSame('medication', $alert->fresh()->source);
        $this->assertDatabaseCount('control_room_alerts', 1);
        $this->assertDatabaseCount('client_incidents', 1);
        $this->assertDatabaseCount('hs_events', 1);
    }

    public function test_draft_ensure_is_rejected_without_any_journey_writes(): void
    {
        $incident = $this->incidentWithoutEvents([
            'status' => 'draft',
            'submitted_at' => null,
            'severity' => 'high',
        ]);
        $before = $incident->only(['status', 'submitted_at', 'hs_event_id', 'control_room_alert_id', 'metadata']);

        $exception = null;
        try {
            app(IncidentJourneyService::class)->ensureForSubmittedIncident($incident, $incident->reporter);
        } catch (\DomainException $caught) {
            $exception = $caught;
        }

        $this->assertNotNull($exception);
        $this->assertStringContainsString('submitted', strtolower($exception->getMessage()));
        $this->assertDatabaseCount('hs_events', 0);
        $this->assertDatabaseCount('control_room_alerts', 0);
        $this->assertSame($before, $incident->fresh()->only(array_keys($before)));
    }

    public function test_submitted_severity_rules_create_hs_for_all_and_alert_only_for_high_using_reporter_fallback(): void
    {
        $reporter = User::factory()->create();
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $low = $this->submittedIncidentWithoutEvents($client, $site, $reporter, ['severity' => 'low']);
        $medium = $this->submittedIncidentWithoutEvents($client, $site, $reporter, ['severity' => 'medium']);
        $high = $this->submittedIncidentWithoutEvents($client, $site, $reporter, ['severity' => 'high']);

        $lowJourney = app(IncidentJourneyService::class)->ensureForSubmittedIncident($low);
        $mediumJourney = app(IncidentJourneyService::class)->ensureForSubmittedIncident($medium);

        $this->assertNotNull($lowJourney->hsEvent);
        $this->assertNull($lowJourney->alert);
        $this->assertNotNull($mediumJourney->hsEvent);
        $this->assertNull($mediumJourney->alert);
        $this->assertDatabaseCount('hs_events', 2);
        $this->assertDatabaseCount('control_room_alerts', 0);

        $firstHigh = app(IncidentJourneyService::class)->ensureForSubmittedIncident($high);
        $secondHigh = app(IncidentJourneyService::class)->ensureForSubmittedIncident($high->fresh());

        $this->assertTrue($secondHigh->hsEvent->is($firstHigh->hsEvent));
        $this->assertTrue($secondHigh->alert->is($firstHigh->alert));
        $this->assertSame($reporter->id, $firstHigh->alert->created_by_user_id);
        $this->assertDatabaseCount('hs_events', 3);
        $this->assertDatabaseCount('control_room_alerts', 1);

        foreach ([$low, $medium, $high] as $incident) {
            $incident = $incident->fresh();
            $this->assertNotNull($incident->hs_event_id);
            $this->assertSame(
                HsEvent::HANDOVER_AWAITING_ACCEPTANCE,
                $incident->hsEvent->handover_status,
            );
        }
    }

    public function test_high_incident_without_actor_or_reporter_fails_clearly_and_rolls_back_hs_creation(): void
    {
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $incident = $this->submittedIncidentWithoutEvents($client, $site, null, [
            'severity' => 'high',
            'reported_by' => null,
        ]);

        $exception = null;
        try {
            app(IncidentJourneyService::class)->ensureForSubmittedIncident($incident);
        } catch (\DomainException $caught) {
            $exception = $caught;
        }

        $this->assertNotNull($exception);
        $this->assertStringContainsString('actor', strtolower($exception->getMessage()));
        $this->assertDatabaseCount('hs_events', 0);
        $this->assertDatabaseCount('control_room_alerts', 0);
        $this->assertNull($incident->fresh()->hs_event_id);
        $this->assertNull($incident->fresh()->control_room_alert_id);
    }

    public function test_explicit_escalation_keeps_two_nearby_same_client_incidents_as_two_stable_alerts(): void
    {
        $actor = User::factory()->create();
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $occurredAt = now()->subMinutes(10);
        $firstIncident = $this->submittedIncidentWithoutEvents($client, $site, $actor, [
            'type' => 'fall',
            'severity' => 'medium',
            'occurred_at' => $occurredAt,
            'description' => 'First fall in lounge.',
        ]);
        $secondIncident = $this->submittedIncidentWithoutEvents($client, $site, $actor, [
            'type' => 'fall',
            'severity' => 'medium',
            'occurred_at' => $occurredAt->copy()->addMinutes(5),
            'description' => 'Second fall in bedroom.',
        ]);

        $first = app(IncidentJourneyService::class)->ensureAlertForIncident($firstIncident, $actor, 'Operator escalation');
        $firstRetry = app(IncidentJourneyService::class)->ensureAlertForIncident($firstIncident->fresh(), $actor, 'Operator escalation');
        $second = app(IncidentJourneyService::class)->ensureAlertForIncident($secondIncident, $actor, 'Operator escalation');
        $secondRetry = app(IncidentJourneyService::class)->ensureAlertForIncident($secondIncident->fresh(), $actor, 'Operator escalation');

        $this->assertTrue($firstRetry->alert->is($first->alert));
        $this->assertTrue($secondRetry->alert->is($second->alert));
        $this->assertFalse($first->alert->is($second->alert));
        $this->assertDatabaseCount('control_room_alerts', 2);
        $this->assertDatabaseCount('hs_events', 2);

        foreach ([$firstIncident, $secondIncident] as $incident) {
            $incident = $incident->fresh();
            $this->assertNotNull($incident->control_room_alert_id);
            $this->assertNotNull($incident->hs_event_id);
            $this->assertSame($incident->id, $incident->controlRoomAlert->context['incident_id']);
            $this->assertSame($incident->type, $incident->controlRoomAlert->context['incident_type']);
            $this->assertSame($incident->description, $incident->controlRoomAlert->context['description']);
            $this->assertSame('Operator escalation', $incident->controlRoomAlert->context['reason']);
            $this->assertSame('incident_journey', $incident->controlRoomAlert->context['provenance']['source']);
            $this->assertSame($incident->control_room_alert_id, $incident->hsEvent->control_room_alert_id);
        }

        $this->assertSame(0, ClientIncident::query()->whereNull('control_room_alert_id')->count());
        $this->assertSame(0, HsEvent::query()->whereNull('control_room_alert_id')->count());
    }

    public function test_observer_promotes_an_existing_medium_alert_and_reconciles_high_operations_without_demoting_governance(): void
    {
        $actor = User::factory()->create();
        $acceptor = User::factory()->create();
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $mediumQueue = TriageQueue::query()->create([
            'name' => 'Medium incidents',
            'code' => 'medium-incidents',
            'tier' => 2,
            'handle_severities' => ['medium'],
            'handle_sources' => ['incident'],
            'handle_alert_types' => ['incident.fall'],
            'is_active' => true,
        ]);
        $highQueue = TriageQueue::query()->create([
            'name' => 'High incidents',
            'code' => 'high-incidents',
            'tier' => 1,
            'handle_severities' => ['high'],
            'handle_sources' => ['incident'],
            'handle_alert_types' => ['incident.fall'],
            'is_active' => true,
        ]);
        $mediumSla = SlaDefinition::query()->create([
            'name' => 'Medium incident SLA',
            'code' => 'medium-incident-sla',
            'alert_types' => ['incident.fall'],
            'severities' => ['medium'],
            'sources' => ['incident'],
            'acknowledge_target_minutes' => 30,
            'resolution_target_minutes' => 240,
            'is_active' => true,
        ]);
        $highSla = SlaDefinition::query()->create([
            'name' => 'High incident SLA',
            'code' => 'high-incident-sla',
            'alert_types' => ['incident.fall'],
            'severities' => ['high'],
            'sources' => ['incident'],
            'acknowledge_target_minutes' => 10,
            'resolution_target_minutes' => 90,
            'is_active' => true,
        ]);
        $incident = $this->submittedIncidentWithoutEvents($client, $site, $actor, [
            'type' => 'fall',
            'severity' => 'medium',
            'occurred_at' => now()->subHour(),
        ]);
        $journey = app(IncidentJourneyService::class)->ensureAlertForIncident(
            $incident,
            $actor,
            'Operator requested early triage',
        );
        $alert = $journey->alert;
        $hsEvent = $journey->hsEvent;
        $acknowledgedAt = $alert->triggered_at->copy()->addMinutes(20);
        $cycleStartedAt = $alert->triggered_at->copy()->addMinutes(5);
        $cycleHistory = [[
            'cycle' => 1,
            'started_at' => $alert->triggered_at->toIso8601String(),
            'ended_as' => 'reopened',
        ]];
        $alert->sla()->firstOrFail()->forceFill([
            'acknowledged_at' => $acknowledgedAt,
            'acknowledge_variance_minutes' => -10,
            'acknowledge_breached' => false,
            'first_breach_at' => null,
            'cycle_number' => 2,
            'cycle_started_at' => $cycleStartedAt,
            'cycle_history' => $cycleHistory,
            'ended_as' => 'reopened',
        ])->save();
        $acceptedAt = now()->subMinutes(5);
        $alert->updateQuietly(['status' => ControlRoomAlert::STATUS_TRIAGING]);
        $hsEvent->updateQuietly([
            'handover_status' => HsEvent::HANDOVER_ACCEPTED,
            'accepted_by_user_id' => $acceptor->id,
            'accepted_at' => $acceptedAt,
            'worksafe_notifiable' => true,
            'worksafe_status' => HsEvent::WORKSAFE_ACKNOWLEDGED,
            'worksafe_reference' => 'WS-PROMOTION-1',
            'worksafe_site_preserved' => true,
        ]);

        $incident->refresh()->updateQuietly(['severity' => 'high']);
        app(ClientIncidentObserver::class)->updated($incident);

        $alert = $alert->fresh();
        $hsEvent = $hsEvent->fresh();
        $this->assertSame('high', $alert->severity);
        $this->assertSame(ControlRoomAlert::STATUS_TRIAGING, $alert->status);
        $this->assertSame($highQueue->id, $alert->queue_id);
        $this->assertSame($highSla->id, $alert->sla?->sla_definition_id);
        $this->assertNotSame($mediumSla->id, $alert->sla?->sla_definition_id);
        $this->assertSame(10, $alert->sla?->acknowledge_target_minutes);
        $this->assertSame(
            $acknowledgedAt->toDateTimeString(),
            $alert->sla?->acknowledged_at?->toDateTimeString(),
        );
        $this->assertSame(10, $alert->sla?->acknowledge_variance_minutes);
        $this->assertTrue((bool) $alert->sla?->acknowledge_breached);
        $this->assertSame(
            $acknowledgedAt->toDateTimeString(),
            $alert->sla?->first_breach_at?->toDateTimeString(),
        );
        $this->assertSame(2, $alert->sla?->cycle_number);
        $this->assertSame(
            $cycleStartedAt->toDateTimeString(),
            $alert->sla?->cycle_started_at?->toDateTimeString(),
        );
        $this->assertEquals($cycleHistory, $alert->sla?->cycle_history);
        $this->assertSame('reopened', $alert->sla?->ended_as);
        $this->assertDatabaseHas('control_room_alert_queue', [
            'alert_id' => $alert->id,
            'queue_id' => $mediumQueue->id,
        ]);
        $this->assertNotNull(
            AlertQueue::query()
                ->where('alert_id', $alert->id)
                ->where('queue_id', $mediumQueue->id)
                ->value('exited_at'),
        );
        $this->assertDatabaseHas('control_room_alert_queue', [
            'alert_id' => $alert->id,
            'queue_id' => $highQueue->id,
            'exited_at' => null,
        ]);
        $this->assertSame(2, AlertQueue::query()->where('alert_id', $alert->id)->count());
        $this->assertSame($highSla->id, AlertSla::query()->where('alert_id', $alert->id)->value('sla_definition_id'));
        $this->assertSame(HsEvent::HANDOVER_ACCEPTED, $hsEvent->handover_status);
        $this->assertSame($acceptor->id, $hsEvent->accepted_by_user_id);
        $this->assertSame($acceptedAt->toDateTimeString(), $hsEvent->accepted_at->toDateTimeString());
        $this->assertTrue($hsEvent->worksafe_notifiable);
        $this->assertSame(HsEvent::WORKSAFE_ACKNOWLEDGED, $hsEvent->worksafe_status);
        $this->assertSame('WS-PROMOTION-1', $hsEvent->worksafe_reference);
        $this->assertTrue($hsEvent->worksafe_site_preserved);
    }

    public function test_residual_medium_to_critical_promotion_terminalises_unmatched_operations_once(): void
    {
        Notification::fake();
        $this->seed(RbacSeeder::class);
        $actor = User::factory()->create([
            'role' => 'admin',
            'approved_at' => now(),
        ]);
        $actor->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $mediumQueue = TriageQueue::query()->create([
            'name' => 'Medium-only promotion queue',
            'code' => 'medium-only-promotion',
            'tier' => 2,
            'handle_severities' => ['medium'],
            'handle_sources' => ['incident'],
            'handle_alert_types' => ['incident.fall'],
            'is_active' => true,
        ]);
        $mediumSla = SlaDefinition::query()->create([
            'name' => 'Medium-only promotion SLA',
            'code' => 'medium-only-promotion-sla',
            'alert_types' => ['incident.fall'],
            'severities' => ['medium'],
            'sources' => ['incident'],
            'acknowledge_target_minutes' => 1,
            'response_target_minutes' => 2,
            'resolution_target_minutes' => 3,
            'escalate_on_resolution_breach' => true,
            'is_active' => true,
        ]);
        $mediumPlaybook = Playbook::query()->create([
            'name' => 'Medium-only fall response',
            'code' => 'medium-only-fall-response',
            'category' => Playbook::CATEGORY_SAFETY,
            'version' => 1,
            'is_active' => true,
            'auto_attach' => true,
            'trigger_alert_types' => ['incident.fall'],
            'trigger_severities' => ['medium'],
        ]);
        foreach (['Observe and review', 'Record follow-up'] as $order => $title) {
            PlaybookStep::query()->create([
                'playbook_id' => $mediumPlaybook->id,
                'order' => $order,
                'title' => $title,
                'type' => PlaybookStep::TYPE_TASK,
                'is_required' => true,
            ]);
        }
        $incident = $this->submittedIncidentWithoutEvents($client, $site, $actor, [
            'type' => 'fall',
            'severity' => 'medium',
            'occurred_at' => now()->subHour(),
        ]);
        $initial = app(IncidentJourneyService::class)->ensureAlertForIncident(
            $incident,
            $actor,
            'Medium operator review',
        );
        $alert = $initial->alert->fresh();
        $sla = AlertSla::query()->where('alert_id', $alert->id)->sole();
        $mediumRun = $alert->playbookRun()->firstOrFail();
        $this->assertNotNull($sla->cycle_started_at);
        $this->assertSame(
            $alert->triggered_at->toDateTimeString(),
            $sla->cycle_started_at->toDateTimeString(),
        );
        $acknowledgedAt = $alert->triggered_at->copy()->addMinutes(2);
        $respondedAt = $alert->triggered_at->copy()->addMinutes(3);
        $firstBreachAt = $acknowledgedAt->copy();
        $cycleStartedAt = $alert->triggered_at->copy();
        $sla->forceFill([
            'acknowledged_at' => $acknowledgedAt,
            'responded_at' => $respondedAt,
            'acknowledge_variance_minutes' => 1,
            'response_variance_minutes' => 1,
            'acknowledge_breached' => true,
            'response_breached' => true,
            'first_breach_at' => $firstBreachAt,
        ])->save();

        $promoted = app(IncidentJourneyService::class)->ensureAlertForIncident(
            $incident->fresh(),
            $actor,
            'Critical clinical escalation',
            'critical',
        );

        $alert = $alert->fresh();
        $queueHistory = AlertQueue::query()->where('alert_id', $alert->id)->sole();
        $sla = AlertSla::query()->where('alert_id', $alert->id)->sole();
        $mediumRun->refresh();
        $this->assertTrue($promoted->alert->is($alert));
        $this->assertSame('critical', $alert->severity);
        $this->assertNull($alert->queue_id);
        $this->assertNotNull($queueHistory->exited_at);
        $this->assertSame('reconciled_no_match', $queueHistory->exit_reason);
        $this->assertNull($sla->sla_definition_id);
        $this->assertNull($sla->acknowledge_target_minutes);
        $this->assertNull($sla->response_target_minutes);
        $this->assertNull($sla->resolution_target_minutes);
        $this->assertNull($sla->acknowledge_deadline);
        $this->assertNull($sla->response_deadline);
        $this->assertNull($sla->resolution_deadline);
        $this->assertNull($sla->acknowledged_at);
        $this->assertNull($sla->responded_at);
        $this->assertNull($sla->resolved_at);
        $this->assertNull($sla->acknowledge_variance_minutes);
        $this->assertNull($sla->response_variance_minutes);
        $this->assertNull($sla->resolution_variance_minutes);
        $this->assertFalse($sla->acknowledge_breached);
        $this->assertFalse($sla->response_breached);
        $this->assertFalse($sla->resolution_breached);
        $this->assertNull($sla->first_breach_at);
        $this->assertNull($sla->cycle_started_at);
        $this->assertSame('reconciled_no_match', $sla->ended_as);
        $this->assertCount(1, $sla->cycle_history ?? []);
        $snapshot = $sla->cycle_history[0];
        $this->assertSame(1, $snapshot['cycle_number']);
        $this->assertSame('reconciled_no_match', $snapshot['ended_as']);
        $this->assertSame('critical', $snapshot['reconciled_for_severity']);
        $this->assertNotEmpty($snapshot['ended_at']);
        $this->assertSame([
            'id' => $mediumSla->id,
            'code' => 'medium-only-promotion-sla',
            'name' => 'Medium-only promotion SLA',
        ], $snapshot['definition']);
        $this->assertEquals([
            'acknowledge_minutes' => 1,
            'response_minutes' => 2,
            'resolution_minutes' => 3,
        ], $snapshot['targets']);
        $this->assertSame($cycleStartedAt->toIso8601String(), $snapshot['cycle_started_at']);
        $this->assertSame($acknowledgedAt->toIso8601String(), $snapshot['results']['acknowledged_at']);
        $this->assertSame($respondedAt->toIso8601String(), $snapshot['results']['responded_at']);
        $this->assertNull($snapshot['results']['resolved_at']);
        $this->assertSame(1, $snapshot['results']['acknowledge_variance_minutes']);
        $this->assertSame(1, $snapshot['results']['response_variance_minutes']);
        $this->assertNull($snapshot['results']['resolution_variance_minutes']);
        $this->assertTrue($snapshot['results']['acknowledge_breached']);
        $this->assertTrue($snapshot['results']['response_breached']);
        $this->assertFalse($snapshot['results']['resolution_breached']);
        $this->assertSame($firstBreachAt->toIso8601String(), $snapshot['results']['first_breach_at']);
        $this->assertNull($alert->playbook_run_id);
        $this->assertSame(PlaybookRun::STATUS_CANCELLED, $mediumRun->status);
        $this->assertNotNull($mediumRun->completed_at);
        $this->assertSame('critical', data_get($mediumRun->context, 'reconciled_for_severity'));
        $this->assertSame('reconciled_no_match', data_get($mediumRun->context, 'reconciliation_reason'));
        $this->assertNotEmpty(data_get($mediumRun->context, 'reconciled_at'));
        $this->assertSame(
            ['skipped', 'skipped'],
            $mediumRun->steps()->orderBy('order')->pluck('status')->all(),
        );

        $queueExitedAt = $queueHistory->exited_at->toDateTimeString();
        $slaUpdatedAt = $sla->updated_at->toDateTimeString();
        $playbookCompletedAt = $mediumRun->completed_at->toDateTimeString();
        $playbookReconciledAt = data_get($mediumRun->context, 'reconciled_at');
        $this->travel(1)->minute();
        $retry = app(IncidentJourneyService::class)->ensureAlertForIncident(
            $incident->fresh(),
            $actor,
            'Critical clinical escalation',
            'critical',
        );
        $this->travelBack();

        $this->assertTrue($retry->alert->is($promoted->alert));
        $this->assertSame($queueExitedAt, $queueHistory->fresh()->exited_at->toDateTimeString());
        $this->assertSame($slaUpdatedAt, $sla->fresh()->updated_at->toDateTimeString());
        $this->assertSame($playbookCompletedAt, $mediumRun->fresh()->completed_at->toDateTimeString());
        $this->assertSame($playbookReconciledAt, data_get($mediumRun->fresh()->context, 'reconciled_at'));
        $this->assertCount(1, $sla->fresh()->cycle_history ?? []);
        $this->assertSame(1, AlertQueue::query()->where('alert_id', $alert->id)->count());
        $this->assertSame(1, AlertSla::query()->where('alert_id', $alert->id)->count());
        $this->assertSame(1, PlaybookRun::query()->where('alert_id', $alert->id)->count());
        $this->assertSame(2, $mediumRun->steps()->count());

        $escalationLevel = (int) $alert->fresh()->escalation_level;
        app(CheckControlRoomSlaBreaches::class)->handle(
            app(ControlRoomNotificationService::class),
            app(AlertAutomationService::class),
        );
        $this->assertSame($escalationLevel, (int) $alert->fresh()->escalation_level);
        $this->assertFalse($sla->fresh()->resolution_breached);
        $this->assertSame(
            0,
            app(ControlRoomReportService::class)
                ->slaCompliance(now()->subDay(), now()->addDay())['total_with_sla'],
        );
        $workspace = app(AlertWorkspaceService::class)->build($actor, $alert->id);
        $this->assertNotNull($workspace);
        $this->assertNull($workspace['sla']);
        $this->assertNull($workspace['playbook_run']);
    }

    public function test_residual_terminal_sla_reactivates_as_a_new_cycle_when_a_later_severity_matches(): void
    {
        Notification::fake();
        $actor = User::factory()->create();
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $mediumSla = SlaDefinition::query()->create([
            'name' => 'Medium staged SLA',
            'code' => 'medium-staged-sla',
            'alert_types' => ['incident.fall'],
            'severities' => ['medium'],
            'sources' => ['incident'],
            'acknowledge_target_minutes' => 20,
            'resolution_target_minutes' => 120,
            'is_active' => true,
        ]);
        $criticalSla = SlaDefinition::query()->create([
            'name' => 'Critical staged SLA',
            'code' => 'critical-staged-sla',
            'alert_types' => ['incident.fall'],
            'severities' => ['critical'],
            'sources' => ['incident'],
            'acknowledge_target_minutes' => 5,
            'response_target_minutes' => 10,
            'resolution_target_minutes' => 30,
            'is_active' => true,
        ]);
        $incident = $this->submittedIncidentWithoutEvents($client, $site, $actor, [
            'type' => 'fall',
            'severity' => 'medium',
            'occurred_at' => now()->subHour(),
        ]);
        $initial = app(IncidentJourneyService::class)->ensureAlertForIncident(
            $incident,
            $actor,
            'Medium operator review',
        );
        $alert = $initial->alert->fresh();
        $initialSla = AlertSla::query()->where('alert_id', $alert->id)->sole();
        $this->assertSame($mediumSla->id, $initialSla->sla_definition_id);
        $this->assertSame(1, $initialSla->cycle_number);
        $this->assertSame(
            $alert->triggered_at->toDateTimeString(),
            $initialSla->cycle_started_at?->toDateTimeString(),
        );

        app(IncidentJourneyService::class)->ensureAlertForIncident(
            $incident->fresh(),
            $actor,
            'High escalation without an SLA target',
            'high',
        );
        $terminalSla = $initialSla->fresh();
        $this->assertNull($terminalSla->sla_definition_id);
        $this->assertSame(AlertSla::ENDED_RECONCILED_NO_MATCH, $terminalSla->ended_as);
        $this->assertCount(1, $terminalSla->cycle_history ?? []);
        $terminalHistory = $terminalSla->cycle_history;

        $this->travel(10)->minutes();
        $criticalPromotionAt = now()->copy();
        $promoted = app(IncidentJourneyService::class)->ensureAlertForIncident(
            $incident->fresh(),
            $actor,
            'Critical escalation with a new SLA target',
            'critical',
        );

        $activeSla = $terminalSla->fresh();
        $this->assertSame($alert->id, $promoted->alert->id);
        $this->assertSame($criticalSla->id, $activeSla->sla_definition_id);
        $this->assertTrue($activeSla->isApplicable());
        $this->assertNull($activeSla->ended_as);
        $this->assertSame(2, $activeSla->cycle_number);
        $this->assertSame(
            $criticalPromotionAt->toDateTimeString(),
            $activeSla->cycle_started_at?->toDateTimeString(),
        );
        $this->assertSame(
            $criticalPromotionAt->copy()->addMinutes(5)->toDateTimeString(),
            $activeSla->acknowledge_deadline?->toDateTimeString(),
        );
        $this->assertSame(
            $criticalPromotionAt->copy()->addMinutes(10)->toDateTimeString(),
            $activeSla->response_deadline?->toDateTimeString(),
        );
        $this->assertSame(
            $criticalPromotionAt->copy()->addMinutes(30)->toDateTimeString(),
            $activeSla->resolution_deadline?->toDateTimeString(),
        );
        $this->assertEquals($terminalHistory, $activeSla->cycle_history);
        $this->assertNull($activeSla->acknowledged_at);
        $this->assertNull($activeSla->responded_at);
        $this->assertNull($activeSla->resolved_at);
        $this->assertFalse($activeSla->acknowledge_breached);
        $this->assertFalse($activeSla->response_breached);
        $this->assertFalse($activeSla->resolution_breached);

        $cycleStartedAt = $activeSla->cycle_started_at->toDateTimeString();
        $acknowledgeDeadline = $activeSla->acknowledge_deadline->toDateTimeString();
        $updatedAt = $activeSla->updated_at->toDateTimeString();
        $this->travel(1)->minute();
        $retry = app(IncidentJourneyService::class)->ensureAlertForIncident(
            $incident->fresh(),
            $actor,
            'Critical escalation with a new SLA target',
            'critical',
        );
        $this->travelBack();

        $activeSla = $activeSla->fresh();
        $this->assertSame($promoted->alert->id, $retry->alert->id);
        $this->assertSame(2, $activeSla->cycle_number);
        $this->assertSame($cycleStartedAt, $activeSla->cycle_started_at->toDateTimeString());
        $this->assertSame($acknowledgeDeadline, $activeSla->acknowledge_deadline->toDateTimeString());
        $this->assertSame($updatedAt, $activeSla->updated_at->toDateTimeString());
        $this->assertEquals($terminalHistory, $activeSla->cycle_history);
        $this->assertSame(1, AlertSla::query()->where('alert_id', $alert->id)->count());
    }

    public function test_medium_to_critical_promotion_keeps_matching_wildcard_operations(): void
    {
        Notification::fake();
        $actor = User::factory()->create();
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $wildcardQueue = TriageQueue::query()->create([
            'name' => 'Wildcard incident queue',
            'code' => 'wildcard-incident-promotion',
            'tier' => 1,
            'handle_severities' => null,
            'handle_sources' => ['incident'],
            'handle_alert_types' => ['incident.fall'],
            'is_active' => true,
        ]);
        $wildcardSla = SlaDefinition::query()->create([
            'name' => 'Wildcard incident SLA',
            'code' => 'wildcard-incident-promotion-sla',
            'alert_types' => ['incident.fall'],
            'severities' => null,
            'sources' => ['incident'],
            'acknowledge_target_minutes' => 5,
            'resolution_target_minutes' => 60,
            'is_active' => true,
        ]);
        $wildcardPlaybook = Playbook::query()->create([
            'name' => 'Wildcard fall response',
            'code' => 'wildcard-fall-response',
            'category' => Playbook::CATEGORY_SAFETY,
            'version' => 1,
            'is_active' => true,
            'auto_attach' => true,
            'trigger_alert_types' => ['incident.fall'],
            'trigger_severities' => null,
        ]);
        PlaybookStep::query()->create([
            'playbook_id' => $wildcardPlaybook->id,
            'order' => 0,
            'title' => 'Respond at any severity',
            'type' => PlaybookStep::TYPE_TASK,
            'is_required' => true,
        ]);
        $incident = $this->submittedIncidentWithoutEvents($client, $site, $actor, [
            'type' => 'fall',
            'severity' => 'medium',
        ]);
        $initial = app(IncidentJourneyService::class)->ensureAlertForIncident(
            $incident,
            $actor,
            'Medium operator review',
        );
        $alert = $initial->alert->fresh();
        $sla = AlertSla::query()->where('alert_id', $alert->id)->sole();
        $run = $alert->playbookRun()->firstOrFail();
        $queueHistory = AlertQueue::query()->where('alert_id', $alert->id)->sole();

        $promoted = app(IncidentJourneyService::class)->ensureAlertForIncident(
            $incident->fresh(),
            $actor,
            'Critical clinical escalation',
            'critical',
        );
        $retry = app(IncidentJourneyService::class)->ensureAlertForIncident(
            $incident->fresh(),
            $actor,
            'Critical clinical escalation',
            'critical',
        );

        $alert = $alert->fresh();
        $this->assertTrue($retry->alert->is($promoted->alert));
        $this->assertSame('critical', $alert->severity);
        $this->assertSame($wildcardQueue->id, $alert->queue_id);
        $this->assertNull($queueHistory->fresh()->exited_at);
        $this->assertSame($wildcardSla->id, $sla->fresh()->sla_definition_id);
        $this->assertNotNull($sla->fresh()->acknowledge_deadline);
        $this->assertNull($sla->fresh()->ended_as);
        $this->assertNull($sla->fresh()->cycle_history);
        $this->assertSame($run->id, $alert->playbook_run_id);
        $this->assertSame($wildcardPlaybook->id, $run->fresh()->playbook_id);
        $this->assertSame(PlaybookRun::STATUS_IN_PROGRESS, $run->fresh()->status);
        $this->assertSame(1, AlertQueue::query()->where('alert_id', $alert->id)->count());
        $this->assertSame(1, AlertSla::query()->where('alert_id', $alert->id)->count());
        $this->assertSame(1, PlaybookRun::query()->where('alert_id', $alert->id)->count());
        $this->assertSame(1, $run->steps()->count());
    }

    public function test_medium_to_critical_promotion_reconciles_queue_sla_playbook_and_delivery_once_on_retry(): void
    {
        Notification::fake();
        $actor = User::factory()->create();
        $criticalRecipient = User::factory()->create();
        $acceptor = User::factory()->create();
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $mediumQueue = TriageQueue::query()->create([
            'name' => 'Medium promotion queue',
            'code' => 'medium-promotion',
            'tier' => 2,
            'handle_severities' => ['medium'],
            'handle_sources' => ['incident'],
            'handle_alert_types' => ['incident.fall'],
            'is_active' => true,
        ]);
        $criticalQueue = TriageQueue::query()->create([
            'name' => 'Critical promotion queue',
            'code' => 'critical-promotion',
            'tier' => 1,
            'handle_severities' => ['critical'],
            'handle_sources' => ['incident'],
            'handle_alert_types' => ['incident.fall'],
            'assigned_users' => [$criticalRecipient->id],
            'is_active' => true,
        ]);
        SlaDefinition::query()->create([
            'name' => 'Medium promotion SLA',
            'code' => 'medium-promotion-sla',
            'alert_types' => ['incident.fall'],
            'severities' => ['medium'],
            'sources' => ['incident'],
            'acknowledge_target_minutes' => 30,
            'resolution_target_minutes' => 240,
            'is_active' => true,
        ]);
        $mediumPlaybook = Playbook::query()->create([
            'name' => 'Medium fall response',
            'code' => 'medium-fall-response',
            'category' => Playbook::CATEGORY_SAFETY,
            'version' => 1,
            'is_active' => true,
            'auto_attach' => true,
            'trigger_alert_types' => ['incident.fall'],
            'trigger_severities' => ['medium'],
        ]);
        PlaybookStep::query()->create([
            'playbook_id' => $mediumPlaybook->id,
            'order' => 0,
            'title' => 'Observe and review',
            'type' => PlaybookStep::TYPE_TASK,
            'is_required' => true,
        ]);
        $criticalSla = SlaDefinition::query()->create([
            'name' => 'Critical promotion SLA',
            'code' => 'critical-promotion-sla',
            'alert_types' => ['incident.fall'],
            'severities' => ['critical'],
            'sources' => ['incident'],
            'acknowledge_target_minutes' => 3,
            'resolution_target_minutes' => 30,
            'is_active' => true,
        ]);
        $playbook = Playbook::query()->create([
            'name' => 'Critical fall response',
            'code' => 'critical-fall-response',
            'category' => Playbook::CATEGORY_EMERGENCY,
            'version' => 1,
            'is_active' => true,
            'auto_attach' => true,
            'trigger_alert_types' => ['incident.fall'],
            'trigger_severities' => ['critical'],
        ]);
        PlaybookStep::query()->create([
            'playbook_id' => $playbook->id,
            'order' => 0,
            'title' => 'Stabilise and escalate',
            'type' => PlaybookStep::TYPE_TASK,
            'is_required' => true,
        ]);
        $incident = $this->submittedIncidentWithoutEvents($client, $site, $actor, [
            'type' => 'fall',
            'severity' => 'medium',
        ]);
        DB::beginTransaction();
        $initial = app(IncidentJourneyService::class)->ensureAlertForIncident(
            $incident,
            $actor,
            'Medium operator review',
        );
        DB::commit();
        $alert = $initial->alert->fresh();
        $hsEvent = $initial->hsEvent->fresh();
        $mediumRun = $alert->playbookRun()->firstOrFail();
        $this->assertSame($mediumPlaybook->id, $mediumRun->playbook_id);
        $this->assertSame(PlaybookRun::STATUS_IN_PROGRESS, $mediumRun->status);
        $alert->updateQuietly(['status' => ControlRoomAlert::STATUS_TRIAGING]);
        $hsEvent->updateQuietly([
            'handover_status' => HsEvent::HANDOVER_ACCEPTED,
            'accepted_by_user_id' => $acceptor->id,
            'accepted_at' => now()->subMinute(),
            'worksafe_notifiable' => true,
            'worksafe_status' => HsEvent::WORKSAFE_NOTIFIED,
            'worksafe_reference' => 'WS-PROMOTION-CRITICAL',
        ]);

        Notification::fake();
        DB::beginTransaction();
        $promoted = app(IncidentJourneyService::class)->ensureAlertForIncident(
            $incident->fresh(),
            $actor,
            'Critical clinical escalation',
            'critical',
        );
        $retry = app(IncidentJourneyService::class)->ensureAlertForIncident(
            $incident->fresh(),
            $actor,
            'Critical clinical escalation',
            'critical',
        );
        Notification::assertNotSentTo($criticalRecipient, ControlRoomAlertNotification::class);
        DB::commit();

        $alert = $alert->fresh();
        $hsEvent = $hsEvent->fresh();
        $this->assertTrue($retry->alert->is($promoted->alert));
        $this->assertSame('critical', $alert->severity);
        $this->assertSame(ControlRoomAlert::STATUS_TRIAGING, $alert->status);
        $this->assertSame($criticalQueue->id, $alert->queue_id);
        $this->assertSame($criticalSla->id, $alert->sla?->sla_definition_id);
        $this->assertSame(3, $alert->sla?->acknowledge_target_minutes);
        $this->assertSame($playbook->id, $alert->playbookRun?->playbook_id);
        $this->assertSame(PlaybookRun::STATUS_IN_PROGRESS, $alert->playbookRun?->status);
        $criticalRun = $alert->playbookRun;
        $this->assertNotSame($mediumRun->id, $criticalRun?->id);
        $mediumRun->refresh();
        $this->assertSame(PlaybookRun::STATUS_CANCELLED, $mediumRun->status);
        $this->assertSame(
            $criticalRun?->id,
            data_get($mediumRun->context, 'superseded_by_playbook_run_id'),
        );
        $this->assertSame('critical', data_get($mediumRun->context, 'superseded_for_severity'));
        $this->assertDatabaseCount('control_room_playbook_runs', 2);
        $this->assertDatabaseCount('control_room_playbook_run_steps', 2);
        $this->assertSame(2, AlertQueue::query()->where('alert_id', $alert->id)->count());
        $this->assertSame(
            1,
            AlertQueue::query()
                ->where('alert_id', $alert->id)
                ->whereNull('exited_at')
                ->where('queue_id', $criticalQueue->id)
                ->count(),
        );
        $this->assertNotNull(
            AlertQueue::query()
                ->where('alert_id', $alert->id)
                ->where('queue_id', $mediumQueue->id)
                ->value('exited_at'),
        );
        Notification::assertSentToTimes($criticalRecipient, ControlRoomAlertNotification::class, 1);
        $this->assertSame(
            1,
            Communication::query()
                ->where('alert_id', $alert->id)
                ->where('target_user_id', $criticalRecipient->id)
                ->where('purpose', 'notification')
                ->count(),
        );
        $this->assertSame(HsEvent::HANDOVER_ACCEPTED, $hsEvent->handover_status);
        $this->assertSame(HsEvent::WORKSAFE_NOTIFIED, $hsEvent->worksafe_status);
        $this->assertSame('WS-PROMOTION-CRITICAL', $hsEvent->worksafe_reference);
    }

    public function test_severity_routing_change_supersedes_pending_snapshot_before_delivering_the_new_generation(): void
    {
        Queue::fake([DeliverControlRoomAlertNotificationJob::class]);
        Notification::fake();
        $actor = User::factory()->create();
        $mediumRecipient = User::factory()->create();
        $criticalRecipient = User::factory()->create();
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        TriageQueue::query()->create([
            'name' => 'Medium snapshot queue',
            'code' => 'medium-snapshot',
            'tier' => 2,
            'handle_severities' => ['medium'],
            'handle_sources' => ['incident'],
            'handle_alert_types' => ['incident.fall'],
            'assigned_users' => [$mediumRecipient->id],
            'is_active' => true,
        ]);
        TriageQueue::query()->create([
            'name' => 'Critical snapshot queue',
            'code' => 'critical-snapshot',
            'tier' => 1,
            'handle_severities' => ['critical'],
            'handle_sources' => ['incident'],
            'handle_alert_types' => ['incident.fall'],
            'assigned_users' => [$criticalRecipient->id],
            'is_active' => true,
        ]);
        $incident = $this->submittedIncidentWithoutEvents($client, $site, $actor, [
            'type' => 'fall',
            'severity' => 'medium',
        ]);

        DB::beginTransaction();
        $journey = app(IncidentJourneyService::class)->ensureAlertForIncident(
            $incident,
            $actor,
            'Medium snapshot generation',
        );
        DB::commit();

        $mediumDelivery = Communication::query()
            ->where('alert_id', $journey->alert->id)
            ->where('target_user_id', $mediumRecipient->id)
            ->sole();
        $this->assertSame('Alert incident.fall (medium)', $mediumDelivery->content);
        $this->assertSame('medium', data_get($mediumDelivery->notification_payload, 'severity'));
        $this->assertSame('pending', $mediumDelivery->status);

        DB::beginTransaction();
        app(IncidentJourneyService::class)->ensureAlertForIncident(
            $incident->fresh(),
            $actor,
            'Critical snapshot generation',
            'critical',
        );
        DB::commit();

        $mediumDelivery->refresh();
        $criticalDelivery = Communication::query()
            ->where('alert_id', $journey->alert->id)
            ->where('target_user_id', $criticalRecipient->id)
            ->sole();
        $this->assertNotNull($mediumDelivery->superseded_at);
        $this->assertSame('Alert incident.fall (medium)', $mediumDelivery->content);
        $this->assertSame('medium', data_get($mediumDelivery->notification_payload, 'severity'));
        $this->assertSame('Alert incident.fall (critical)', $criticalDelivery->content);
        $this->assertSame('critical', data_get($criticalDelivery->notification_payload, 'severity'));
        $this->assertNotSame($mediumDelivery->template_used, $criticalDelivery->template_used);

        $notifications = app(ControlRoomNotificationService::class);
        $notifications->deliverStagedNotification($mediumDelivery);
        $notifications->deliverStagedNotification($criticalDelivery);

        Notification::assertNotSentTo($mediumRecipient, ControlRoomAlertNotification::class);
        Notification::assertSentTo(
            $criticalRecipient,
            ControlRoomAlertNotification::class,
            fn (ControlRoomAlertNotification $notification): bool => $notification->toArray($criticalRecipient)['severity'] === 'critical',
        );
        $this->assertSame('pending', $mediumDelivery->fresh()->status);
        $this->assertSame('sent', $criticalDelivery->fresh()->status);
        $this->assertDatabaseCount('control_room_communications', 2);
    }

    public function test_delivery_reconciles_a_recipient_configuration_change_into_the_current_outbox_generation(): void
    {
        Queue::fake([DeliverControlRoomAlertNotificationJob::class]);
        Notification::fake();
        $originalRecipient = User::factory()->create();
        $replacementRecipient = User::factory()->create();
        $queue = TriageQueue::query()->create([
            'name' => 'Mutable recipient queue',
            'code' => 'mutable-recipient-queue',
            'tier' => 1,
            'handle_severities' => ['high'],
            'handle_sources' => ['incident'],
            'handle_alert_types' => ['incident.fall'],
            'assigned_users' => [$originalRecipient->id],
            'is_active' => true,
        ]);
        $alert = ControlRoomAlert::factory()->open()->create([
            'source' => 'incident',
            'alert_type' => 'incident.fall',
            'severity' => 'high',
            'queue_id' => $queue->id,
        ]);
        $notifications = app(ControlRoomNotificationService::class);
        $original = $notifications
            ->stageAlertNotifications($alert, null, $queue)
            ->sole();

        $queue->forceFill(['assigned_users' => [$replacementRecipient->id]])->save();
        (new DeliverControlRoomAlertNotificationJob($original->id))
            ->handle($notifications);

        $original->refresh();
        $replacement = Communication::query()
            ->where('alert_id', $alert->id)
            ->where('target_user_id', $replacementRecipient->id)
            ->whereNull('superseded_at')
            ->sole();
        $replacementId = $replacement->id;
        $replacementKey = $replacement->delivery_key;

        $this->assertNotNull($original->superseded_at);
        $this->assertSame('pending', $replacement->status);
        $this->assertNotNull($replacementKey);
        $this->assertNotSame($original->delivery_key, $replacementKey);
        $this->assertSame(
            'control-room-alert-notification-v2:'.data_get($replacement->notification_payload, 'routing_generation'),
            $replacement->template_used,
        );
        Notification::assertNotSentTo($originalRecipient, ControlRoomAlertNotification::class);
        Notification::assertNotSentTo($replacementRecipient, ControlRoomAlertNotification::class);
        Queue::assertPushed(
            DeliverControlRoomAlertNotificationJob::class,
            fn (DeliverControlRoomAlertNotificationJob $job): bool => $job->communicationId === $replacementId,
        );

        (new DeliverControlRoomAlertNotificationJob($original->id))
            ->handle($notifications);

        $this->assertDatabaseCount('control_room_communications', 2);
        $this->assertSame(
            1,
            Communication::query()
                ->where('alert_id', $alert->id)
                ->where('target_user_id', $replacementRecipient->id)
                ->whereNull('superseded_at')
                ->count(),
        );
        $this->assertSame($replacementId, Communication::query()->where('delivery_key', $replacementKey)->value('id'));
        Queue::assertPushedTimes(DeliverControlRoomAlertNotificationJob::class, 1);
    }

    public function test_new_explicit_incident_alert_initialises_queue_sla_and_automation_exactly_once(): void
    {
        Notification::fake();
        $actor = User::factory()->create();
        $assignee = User::factory()->create();
        $notificationRole = Role::query()->create([
            'name' => 'incident_alert_recipient',
            'label' => 'Incident alert recipient',
            'level' => 10,
            'type' => 'custom',
        ]);
        $assignee->roles()->attach($notificationRole);
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $queue = TriageQueue::create([
            'name' => 'Critical incident queue',
            'code' => 'critical-incidents',
            'tier' => 1,
            'handle_severities' => ['critical'],
            'handle_sources' => ['incident'],
            'handle_alert_types' => ['incident.fall'],
            'assigned_users' => [$assignee->id],
            'assigned_roles' => [$notificationRole->name],
            'is_active' => true,
        ]);
        $slaDefinition = SlaDefinition::create([
            'name' => 'Critical incident SLA',
            'code' => 'critical-incident-sla',
            'alert_types' => ['incident.fall'],
            'severities' => ['critical'],
            'sources' => ['incident'],
            'acknowledge_target_minutes' => 5,
            'response_target_minutes' => 10,
            'resolution_target_minutes' => 60,
            'is_active' => true,
        ]);
        $incident = $this->submittedIncidentWithoutEvents($client, $site, $actor, [
            'type' => 'fall',
            'severity' => 'low',
        ]);
        DB::beginTransaction();

        $first = app(IncidentJourneyService::class)->ensureAlertForIncident(
            $incident,
            $actor,
            'Operator critical escalation',
            'critical',
        );
        $retry = app(IncidentJourneyService::class)->ensureAlertForIncident(
            $incident->fresh(),
            $actor,
            'Operator critical escalation',
            'critical',
        );
        $alert = $first->alert->fresh();

        Notification::assertNothingSent();
        $this->assertDatabaseHas('control_room_communications', [
            'alert_id' => $alert->id,
            'target_user_id' => $assignee->id,
            'purpose' => 'notification',
            'status' => 'pending',
        ]);
        DB::commit();

        $this->assertTrue($retry->alert->is($first->alert));
        $this->assertSame('critical', $alert->severity);
        $this->assertSame($queue->id, $alert->queue_id);
        $this->assertSame($assignee->id, $alert->assigned_to_user_id);
        $this->assertTrue((bool) data_get($alert->context, 'auto_assigned'));
        $this->assertSame($slaDefinition->id, $alert->sla?->sla_definition_id);
        $this->assertSame($alert->id, AlertQueue::query()->sole()->alert_id);
        $this->assertDatabaseCount('control_room_alert_queue', 1);
        $this->assertDatabaseCount('control_room_alert_sla', 1);
        $this->assertDatabaseCount('control_room_alerts', 1);
        $this->assertDatabaseCount('hs_events', 1);
        Notification::assertSentTo($assignee, ControlRoomAlertNotification::class);
        Notification::assertCount(1);
        $communication = Communication::query()->sole();
        $this->assertSame($alert->id, $communication->alert_id);
        $this->assertSame($assignee->id, $communication->target_user_id);
        $this->assertSame('notification', $communication->purpose);
        $this->assertSame('sent', $communication->status);
    }

    public function test_initializer_attaches_the_configured_playbook_before_automation_once_on_retry(): void
    {
        $actor = User::factory()->create();
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $playbook = Playbook::query()->create([
            'name' => 'Incident fall response',
            'code' => 'incident-fall-response',
            'category' => Playbook::CATEGORY_SAFETY,
            'version' => 1,
            'is_active' => true,
            'auto_attach' => true,
            'trigger_alert_types' => ['incident.fall'],
            'trigger_severities' => ['high'],
        ]);
        PlaybookStep::query()->create([
            'playbook_id' => $playbook->id,
            'order' => 0,
            'title' => 'Assess immediate harm',
            'type' => PlaybookStep::TYPE_TASK,
            'is_required' => true,
        ]);
        PlaybookStep::query()->create([
            'playbook_id' => $playbook->id,
            'order' => 1,
            'title' => 'Preserve evidence',
            'type' => PlaybookStep::TYPE_EVIDENCE,
            'is_required' => true,
        ]);
        $incident = $this->submittedIncidentWithoutEvents($client, $site, $actor, [
            'type' => 'fall',
            'severity' => 'high',
        ]);

        DB::beginTransaction();
        $first = app(IncidentJourneyService::class)->ensureForSubmittedIncident($incident, $actor);
        $retry = app(IncidentJourneyService::class)->ensureForSubmittedIncident($incident->fresh(), $actor);
        DB::commit();

        $alert = $first->alert->fresh();
        $this->assertTrue($retry->alert->is($first->alert));
        $this->assertSame($playbook->id, $alert->playbookRun?->playbook_id);
        $this->assertSame(PlaybookRun::STATUS_IN_PROGRESS, $alert->playbookRun?->status);
        $this->assertSame(2, $alert->playbookRun?->steps()->count());
        $this->assertDatabaseCount('control_room_playbook_runs', 1);
        $this->assertDatabaseCount('control_room_playbook_run_steps', 2);
    }

    public function test_after_commit_delivery_failure_is_persisted_and_retry_succeeds_without_duplicates_or_request_failure(): void
    {
        $recipient = User::factory()->create();
        $queue = TriageQueue::query()->create([
            'name' => 'Durable notification queue',
            'code' => 'durable-notification',
            'tier' => 1,
            'handle_severities' => ['high'],
            'handle_sources' => ['incident'],
            'handle_alert_types' => ['incident.fall'],
            'assigned_users' => [$recipient->id],
            'is_active' => true,
        ]);
        $dispatcher = \Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('send')
            ->once()
            ->andThrow(new \RuntimeException('Forced post-commit notification failure'));
        $this->app->instance(Dispatcher::class, $dispatcher);
        DB::beginTransaction();
        $alert = ControlRoomAlert::factory()->open()->create([
            'source' => 'incident',
            'alert_type' => 'incident.fall',
            'severity' => 'high',
        ]);
        app(IncidentAlertOperationalInitializer::class)->initialiseNewAlert($alert);
        $this->assertDatabaseHas('control_room_communications', [
            'alert_id' => $alert->id,
            'target_user_id' => $recipient->id,
            'purpose' => 'notification',
            'status' => 'pending',
        ]);
        $commitException = null;

        try {
            DB::commit();
        } catch (\Throwable $caught) {
            $commitException = $caught;
        }

        $this->assertNull($commitException, 'Post-commit delivery must not turn durable alert creation into request failure.');
        $failed = Communication::query()->sole();
        $this->assertSame($alert->id, $failed->alert_id);
        $this->assertSame($recipient->id, $failed->target_user_id);
        $this->assertSame('failed', $failed->status);
        $this->assertSame(1, $failed->retry_count);
        $this->assertStringContainsString('Forced post-commit notification failure', (string) $failed->status_detail);
        $this->assertSame($queue->id, $alert->fresh()->queue_id);

        $notificationFake = Notification::fake();
        $this->app->instance(Dispatcher::class, $notificationFake);
        $this->app->forgetInstance(IncidentAlertOperationalInitializer::class);
        DB::beginTransaction();
        app(IncidentAlertOperationalInitializer::class)->initialiseNewAlert($alert->fresh());
        DB::commit();

        $delivered = $failed->fresh();
        $this->assertSame('sent', $delivered->status);
        $this->assertNotNull($delivered->sent_at);
        $this->assertNull($delivered->status_detail);
        Notification::assertSentToTimes($recipient, ControlRoomAlertNotification::class, 1);

        DB::beginTransaction();
        app(IncidentAlertOperationalInitializer::class)->initialiseNewAlert($alert->fresh());
        DB::commit();

        Notification::assertSentToTimes($recipient, ControlRoomAlertNotification::class, 1);
        $this->assertDatabaseCount('control_room_communications', 1);
        $this->assertDatabaseCount('control_room_alert_queue', 1);
    }

    public function test_recovery_sweep_delivers_a_stranded_outbox_row_exactly_once(): void
    {
        Notification::fake();
        $recipient = User::factory()->create();
        $freshRecipient = User::factory()->create();
        $exhaustedRecipient = User::factory()->create();
        $alert = ControlRoomAlert::factory()->open()->create([
            'source' => 'incident',
            'alert_type' => 'incident.fall',
            'severity' => 'high',
        ]);
        $communication = Communication::query()->create([
            'delivery_key' => hash('sha256', 'stranded-control-room-notification'),
            'alert_id' => $alert->id,
            'channel' => 'in_app',
            'direction' => 'outbound',
            'purpose' => 'notification',
            'target_user_id' => $recipient->id,
            'content' => 'Stranded alert notification',
            'status' => 'failed',
            'status_detail' => 'Queue backend was unavailable',
            'retry_count' => 1,
        ]);
        $freshCommunication = Communication::query()->create([
            'delivery_key' => hash('sha256', 'fresh-control-room-notification'),
            'alert_id' => $alert->id,
            'channel' => 'in_app',
            'direction' => 'outbound',
            'purpose' => 'notification',
            'target_user_id' => $freshRecipient->id,
            'content' => 'Fresh failed alert notification',
            'status' => 'failed',
            'status_detail' => 'A current queue retry owns this row',
            'retry_count' => 1,
        ]);
        $exhaustedCommunication = Communication::query()->create([
            'delivery_key' => hash('sha256', 'exhausted-control-room-notification'),
            'alert_id' => $alert->id,
            'channel' => 'in_app',
            'direction' => 'outbound',
            'purpose' => 'notification',
            'target_user_id' => $exhaustedRecipient->id,
            'content' => 'Exhausted failed alert notification',
            'status' => 'failed',
            'status_detail' => 'Delivery retry budget exhausted',
            'retry_count' => 3,
        ]);
        DB::table('control_room_communications')
            ->where('id', $communication->id)
            ->update(['updated_at' => now()->subMinutes(3)]);
        $deliveryJob = new DeliverControlRoomAlertNotificationJob($communication->id);
        $this->assertInstanceOf(ShouldBeUnique::class, $deliveryJob);
        $this->assertSame((string) $communication->id, $deliveryJob->uniqueId());

        app(RecoverControlRoomAlertNotificationsJob::class)->handle();
        app(RecoverControlRoomAlertNotificationsJob::class)->handle();
        (new DeliverControlRoomAlertNotificationJob($exhaustedCommunication->id))
            ->handle(app(ControlRoomNotificationService::class));

        $communication->refresh();
        $this->assertSame('sent', $communication->status);
        $this->assertSame(1, $communication->retry_count);
        $this->assertNull($communication->status_detail);
        Notification::assertSentToTimes($recipient, ControlRoomAlertNotification::class, 1);
        $this->assertSame('failed', $freshCommunication->fresh()->status);
        $this->assertSame(1, $freshCommunication->fresh()->retry_count);
        Notification::assertNotSentTo($freshRecipient, ControlRoomAlertNotification::class);
        $this->assertSame('failed', $exhaustedCommunication->fresh()->status);
        $this->assertSame(3, $exhaustedCommunication->fresh()->retry_count);
        Notification::assertNotSentTo($exhaustedRecipient, ControlRoomAlertNotification::class);
        $this->assertDatabaseCount('control_room_communications', 3);
    }

    public function test_incident_alert_operational_initialisation_rolls_back_without_notification_leak(): void
    {
        Notification::fake();
        $recipient = User::factory()->create();
        TriageQueue::create([
            'name' => 'Rollback queue',
            'code' => 'rollback-queue',
            'tier' => 1,
            'handle_severities' => ['high'],
            'handle_sources' => ['incident'],
            'handle_alert_types' => ['incident.fall'],
            'assigned_users' => [$recipient->id],
            'is_active' => true,
        ]);
        SlaDefinition::create([
            'name' => 'Rollback SLA',
            'code' => 'rollback-sla',
            'alert_types' => ['incident.fall'],
            'severities' => ['high'],
            'sources' => ['incident'],
            'acknowledge_target_minutes' => 5,
            'is_active' => true,
        ]);

        try {
            DB::transaction(function (): void {
                $alert = ControlRoomAlert::factory()->open()->create([
                    'source' => 'incident',
                    'alert_type' => 'incident.fall',
                    'severity' => 'high',
                ]);
                app(IncidentAlertOperationalInitializer::class)->initialiseNewAlert($alert);

                throw new \RuntimeException('Force operational transaction rollback');
            });
            $this->fail('The operational transaction must roll back.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Force operational transaction rollback', $exception->getMessage());
        }

        $this->assertDatabaseCount('control_room_alerts', 0);
        $this->assertDatabaseCount('control_room_alert_queue', 0);
        $this->assertDatabaseCount('control_room_alert_sla', 0);
        Notification::assertNothingSent();
    }

    public function test_attach_uses_the_sensor_compatible_alert_then_incident_lock_order(): void
    {
        $actor = User::factory()->create();
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $incident = $this->submittedIncidentWithoutEvents($client, $site, $actor, [
            'severity' => 'high',
            'immediate_action_taken' => 'Immediate controls recorded before the lock-order assertion.',
        ]);
        $alert = ControlRoomAlert::factory()->open()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'severity' => 'high',
            'context' => [],
        ]);
        DB::enableQueryLog();

        app(IncidentJourneyService::class)->attachAlertToIncident($incident, $alert, $actor);

        $locks = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $query): bool => str_contains(strtolower($query), 'for update'))
            ->filter(fn (string $query): bool => str_contains($query, 'control_room_alerts')
                || str_contains($query, 'client_incidents'))
            ->values();

        $this->assertStringContainsString('control_room_alerts', $locks->get(0, ''));
        $this->assertStringContainsString('client_incidents', $locks->get(1, ''));
    }

    public function test_reused_alert_context_keeps_existing_nested_operational_provenance_and_fills_missing_journey_defaults(): void
    {
        $actor = User::factory()->create();
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $alert = ControlRoomAlert::factory()->triaging()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'context' => [
                'incident_id' => 999999,
                'description' => 'Medication workflow description must remain.',
                'reason' => 'Medication escalation reason must remain.',
                'provenance' => [
                    'source' => 'medication_workflow',
                    'trace' => [
                        'correlation_id' => 'med-314',
                        'steps' => ['missed-dose', 'operator-review'],
                    ],
                ],
                'operational' => [
                    'channels' => ['dashboard', 'email'],
                    'tasks' => [
                        ['id' => 71, 'status' => 'in_progress'],
                    ],
                ],
            ],
        ]);
        $incident = $this->submittedIncidentWithoutEvents($client, $site, $actor, [
            'severity' => 'high',
            'type' => 'medication_error',
            'description' => 'Canonical incident description.',
            'control_room_alert_id' => $alert->id,
        ]);

        app(IncidentJourneyService::class)->ensureForSubmittedIncident($incident, $actor);
        app(IncidentJourneyService::class)->ensureAlertForIncident(
            $incident->fresh(),
            $actor,
            'Replacement operator reason',
        );

        $context = $alert->fresh()->context;
        $this->assertSame($incident->id, $context['incident_id']);
        $this->assertSame('medication_error', $context['type']);
        $this->assertSame('medication_error', $context['incident_type']);
        $this->assertSame('Medication workflow description must remain.', $context['description']);
        $this->assertSame('Medication escalation reason must remain.', $context['reason']);
        $this->assertSame('medication_workflow', $context['provenance']['source']);
        $this->assertSame(IncidentJourneyService::class, $context['provenance']['service']);
        $this->assertEquals(
            [
                'correlation_id' => 'med-314',
                'steps' => ['missed-dose', 'operator-review'],
            ],
            $context['provenance']['trace'],
        );
        $this->assertEquals(
            [
                'channels' => ['dashboard', 'email'],
                'tasks' => [
                    ['id' => 71, 'status' => 'in_progress'],
                ],
            ],
            $context['operational'],
        );
        $this->assertDatabaseCount('control_room_alerts', 1);
        $this->assertDatabaseCount('client_incidents', 1);
        $this->assertDatabaseCount('hs_events', 1);
    }

    public function test_legacy_idempotency_hs_events_are_reused_and_accepted_handover_is_never_downgraded(): void
    {
        $reporter = User::factory()->create();
        $acceptor = User::factory()->create();
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $shift = Shift::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'user_id' => $reporter->id,
        ]);
        $notifiedAt = now()->subHour();
        $incident = $this->submittedIncidentWithoutEvents($client, $site, $reporter, [
            'severity' => 'medium',
            'shift_id' => $shift->id,
            'is_notifiable' => true,
            'worksafe_notification_status' => HsEvent::WORKSAFE_NOTIFIED,
            'worksafe_notified_at' => $notifiedAt,
            'worksafe_reference' => 'WS-LEGACY-7',
            'site_preserved' => true,
        ]);
        $legacy = HsEvent::factory()->forClientIncident($incident)->create([
            'handover_status' => HsEvent::HANDOVER_NOT_REQUIRED,
            'site_id' => null,
            'client_id' => null,
            'staff_id' => null,
            'shift_id' => null,
            'worksafe_notifiable' => false,
            'worksafe_status' => null,
        ]);

        $journey = app(IncidentJourneyService::class)->ensureForSubmittedIncident($incident, $reporter);

        $this->assertTrue($journey->hsEvent->is($legacy));
        $legacy = $legacy->fresh();
        $this->assertSame($legacy->id, $incident->fresh()->hs_event_id);
        $this->assertSame(HsEvent::HANDOVER_AWAITING_ACCEPTANCE, $legacy->handover_status);
        $this->assertSame($site->id, $legacy->site_id);
        $this->assertSame($client->id, $legacy->client_id);
        $this->assertSame($reporter->id, $legacy->staff_id);
        $this->assertSame($shift->id, $legacy->shift_id);
        $this->assertTrue($legacy->worksafe_notifiable);
        $this->assertSame(HsEvent::WORKSAFE_NOTIFIED, $legacy->worksafe_status);
        $this->assertSame('WS-LEGACY-7', $legacy->worksafe_reference);
        $this->assertSame($notifiedAt->toDateTimeString(), $legacy->worksafe_notified_at->toDateTimeString());
        $this->assertTrue($legacy->worksafe_site_preserved);

        $acceptedIncident = $this->submittedIncidentWithoutEvents($client, $site, $reporter, [
            'severity' => 'low',
            'description' => 'Accepted handover must stay accepted.',
        ]);
        $acceptedAt = now()->subMinutes(15);
        $accepted = HsEvent::factory()->forClientIncident($acceptedIncident)->create([
            'handover_status' => HsEvent::HANDOVER_ACCEPTED,
            'owner_user_id' => $reporter->id,
            'accepted_by_user_id' => $acceptor->id,
            'accepted_at' => $acceptedAt,
            'acceptance_notes' => 'Already handed over.',
        ]);

        $acceptedJourney = app(IncidentJourneyService::class)->ensureForSubmittedIncident($acceptedIncident, $reporter);
        $accepted = $accepted->fresh();

        $this->assertTrue($acceptedJourney->hsEvent->is($accepted));
        $this->assertSame(HsEvent::HANDOVER_ACCEPTED, $accepted->handover_status);
        $this->assertSame($acceptor->id, $accepted->accepted_by_user_id);
        $this->assertSame($acceptedAt->toDateTimeString(), $accepted->accepted_at->toDateTimeString());
        $this->assertSame('Already handed over.', $accepted->acceptance_notes);
        $this->assertDatabaseCount('hs_events', 2);
    }

    public function test_linked_acknowledged_hs_event_is_authoritative_over_stale_incident_worksafe_fields(): void
    {
        $actor = User::factory()->create();
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $alert = ControlRoomAlert::factory()->triaging()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'context' => ['keep' => 'alert-context'],
        ]);
        $incident = $this->submittedIncidentWithoutEvents($client, $site, $actor, [
            'control_room_alert_id' => $alert->id,
            'is_notifiable' => false,
            'worksafe_notification_status' => HsEvent::WORKSAFE_PENDING,
            'worksafe_reference' => 'WS-STALE-INCIDENT',
            'worksafe_notified_at' => now()->subDays(5),
            'site_preserved' => false,
        ]);
        $notifiedAt = now()->subDays(2);
        $acknowledgedAt = now()->subDay();
        $hsEvent = HsEvent::factory()->forClientIncident($incident)->create([
            'control_room_alert_id' => $alert->id,
            'worksafe_notifiable' => true,
            'worksafe_status' => HsEvent::WORKSAFE_ACKNOWLEDGED,
            'worksafe_reference' => 'WS-CANONICAL-42',
            'worksafe_notified_at' => $notifiedAt,
            'worksafe_method' => 'online',
            'worksafe_acknowledged_at' => $acknowledgedAt,
            'worksafe_site_preserved' => true,
        ]);
        $incident->updateQuietly(['hs_event_id' => $hsEvent->id]);
        $worksafeFields = [
            'worksafe_notifiable',
            'worksafe_status',
            'worksafe_reference',
            'worksafe_notified_at',
            'worksafe_method',
            'worksafe_acknowledged_at',
            'worksafe_site_preserved',
        ];
        $before = Arr::only($hsEvent->fresh()->getAttributes(), $worksafeFields);

        $journey = app(IncidentJourneyService::class)->submitFromAlert(
            $alert,
            $this->incidentInput($client, $site, [
                'is_notifiable' => false,
                'worksafe_notification_status' => null,
                'worksafe_reference' => null,
                'worksafe_notified_at' => null,
                'site_preserved' => false,
            ]),
            $actor,
        );

        $this->assertTrue($journey->hsEvent->is($hsEvent));
        $this->assertSame($before, Arr::only($hsEvent->fresh()->getAttributes(), $worksafeFields));
        $this->assertSame($incident->id, $alert->fresh()->context['incident_id']);
        $this->assertDatabaseCount('hs_events', 1);
        $this->assertDatabaseCount('client_incidents', 1);
        $this->assertDatabaseCount('control_room_alerts', 1);
    }

    public function test_direct_links_beat_legacy_context_and_repair_a_safe_hs_tuple(): void
    {
        $actor = User::factory()->create();
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $directAlert = ControlRoomAlert::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'context' => ['incident_id' => 999999, 'keep' => 'direct-context'],
        ]);
        $directEvent = HsEvent::factory()->create([
            'control_room_alert_id' => $directAlert->id,
            'handover_status' => HsEvent::HANDOVER_NOT_REQUIRED,
        ]);
        $incident = $this->submittedIncidentWithoutEvents($client, $site, $actor, [
            'severity' => 'high',
            'control_room_alert_id' => $directAlert->id,
            'hs_event_id' => $directEvent->id,
        ]);
        $legacyCategory = HsEvent::CATEGORY_NEAR_MISS;
        $directEvent->updateQuietly([
            'source_type' => ClientIncident::class,
            'source_id' => $incident->id,
            'event_category' => $legacyCategory,
            'idempotency_key' => HsEvent::buildIdempotencyKey(
                ClientIncident::class,
                $incident->id,
                $legacyCategory,
            ),
        ]);
        $legacyAlert = ControlRoomAlert::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'context' => ['incident_id' => $incident->id, 'legacy' => true],
        ]);

        $readJourney = app(IncidentJourneyService::class)->journeyForIncident($incident);
        $this->assertTrue($readJourney->alert->is($directAlert));
        $this->assertTrue($readJourney->hsEvent->is($directEvent));

        $journey = app(IncidentJourneyService::class)->ensureForSubmittedIncident($incident, $actor);

        $this->assertTrue($journey->alert->is($directAlert));
        $this->assertTrue($journey->hsEvent->is($directEvent));
        $this->assertSame($incident->id, $directAlert->fresh()->context['incident_id']);
        $this->assertSame('direct-context', $directAlert->fresh()->context['keep']);
        $this->assertEquals(['incident_id' => $incident->id, 'legacy' => true], $legacyAlert->fresh()->context);
        $this->assertSame(ClientIncident::class, $directEvent->fresh()->source_type);
        $this->assertSame($incident->id, $directEvent->fresh()->source_id);
        $this->assertSame(HsEvent::CATEGORY_INCIDENT, $directEvent->fresh()->event_category);
        $this->assertSame(
            HsEvent::buildIdempotencyKey(ClientIncident::class, $incident->id, HsEvent::CATEGORY_INCIDENT),
            $directEvent->fresh()->idempotency_key,
        );
        $this->assertTrue($directAlert->fresh()->clientIncident->is($incident));
        $this->assertTrue($directAlert->fresh()->hsEvent->is($directEvent));
        $this->assertDatabaseCount('control_room_alerts', 2);
        $this->assertDatabaseCount('hs_events', 1);
    }

    public function test_direct_hs_event_owned_by_another_source_is_not_reparented_without_a_competing_event(): void
    {
        $actor = User::factory()->create();
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $shift = Shift::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'user_id' => $actor->id,
        ]);
        $alert = ControlRoomAlert::factory()->triaging()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'context' => [
                'incident_id' => 999999,
                'keep' => ['workflow' => 'medication'],
            ],
        ]);
        $directEvent = HsEvent::factory()->create([
            'source_type' => Shift::class,
            'source_id' => $shift->id,
            'event_category' => HsEvent::CATEGORY_INCIDENT,
            'idempotency_key' => HsEvent::buildIdempotencyKey(
                Shift::class,
                $shift->id,
                HsEvent::CATEGORY_INCIDENT,
            ),
            'control_room_alert_id' => $alert->id,
            'handover_status' => HsEvent::HANDOVER_NOT_REQUIRED,
        ]);
        $incident = $this->submittedIncidentWithoutEvents($client, $site, $actor, [
            'severity' => 'high',
            'control_room_alert_id' => $alert->id,
            'hs_event_id' => $directEvent->id,
            'metadata' => ['keep' => 'incident-metadata'],
        ]);
        $incidentBefore = $incident->only(['control_room_alert_id', 'hs_event_id', 'metadata']);
        $alertBefore = $alert->only(['status', 'source', 'notes', 'context']);
        $directBefore = $directEvent->only([
            'source_type',
            'source_id',
            'event_category',
            'idempotency_key',
            'control_room_alert_id',
            'handover_status',
        ]);

        $exception = null;
        try {
            app(IncidentJourneyService::class)->ensureForSubmittedIncident($incident, $actor);
        } catch (\DomainException $caught) {
            $exception = $caught;
        }

        $this->assertNotNull($exception);
        $this->assertStringContainsString('conflict', strtolower($exception->getMessage()));
        $this->assertSame($incidentBefore, $incident->fresh()->only(array_keys($incidentBefore)));
        $this->assertEquals($alertBefore, $alert->fresh()->only(array_keys($alertBefore)));
        $this->assertSame($directBefore, $directEvent->fresh()->only(array_keys($directBefore)));
        $this->assertDatabaseCount('control_room_alerts', 1);
        $this->assertDatabaseCount('client_incidents', 1);
        $this->assertDatabaseCount('hs_events', 1);
    }

    public function test_direct_hs_tuple_conflict_throws_before_any_partial_journey_write(): void
    {
        $actor = User::factory()->create();
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $alert = ControlRoomAlert::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'context' => ['keep' => 'alert-context'],
        ]);
        $directEvent = HsEvent::factory()->create([
            'control_room_alert_id' => $alert->id,
            'handover_status' => HsEvent::HANDOVER_NOT_REQUIRED,
        ]);
        $incident = $this->submittedIncidentWithoutEvents($client, $site, $actor, [
            'severity' => 'high',
            'control_room_alert_id' => $alert->id,
            'hs_event_id' => $directEvent->id,
            'metadata' => ['keep' => 'incident-metadata'],
        ]);
        $legacyCategory = HsEvent::CATEGORY_NEAR_MISS;
        $directEvent->updateQuietly([
            'source_type' => ClientIncident::class,
            'source_id' => $incident->id,
            'event_category' => $legacyCategory,
            'idempotency_key' => HsEvent::buildIdempotencyKey(
                ClientIncident::class,
                $incident->id,
                $legacyCategory,
            ),
        ]);
        $canonicalEvent = HsEvent::factory()->forClientIncident($incident)->create([
            'handover_status' => HsEvent::HANDOVER_NOT_REQUIRED,
        ]);
        $incidentBefore = $incident->only(['control_room_alert_id', 'hs_event_id', 'metadata']);
        $alertBefore = $alert->only(['status', 'source', 'notes', 'context']);
        $directBefore = $directEvent->only([
            'source_type',
            'source_id',
            'event_category',
            'idempotency_key',
            'control_room_alert_id',
            'handover_status',
        ]);

        $exception = null;
        try {
            app(IncidentJourneyService::class)->ensureForSubmittedIncident($incident, $actor);
        } catch (\DomainException $caught) {
            $exception = $caught;
        }

        $this->assertNotNull($exception);
        $this->assertStringContainsString('conflict', strtolower($exception->getMessage()));
        $this->assertSame($incidentBefore, $incident->fresh()->only(array_keys($incidentBefore)));
        $this->assertSame($alertBefore, $alert->fresh()->only(array_keys($alertBefore)));
        $this->assertSame($directBefore, $directEvent->fresh()->only(array_keys($directBefore)));
        $this->assertSame(
            HsEvent::buildIdempotencyKey(ClientIncident::class, $incident->id, HsEvent::CATEGORY_INCIDENT),
            $canonicalEvent->fresh()->idempotency_key,
        );
        $this->assertDatabaseCount('control_room_alerts', 1);
        $this->assertDatabaseCount('client_incidents', 1);
        $this->assertDatabaseCount('hs_events', 2);
    }

    public function test_conflicting_non_null_direct_links_throw_without_partial_writes(): void
    {
        $actor = User::factory()->create();
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $incidentAlert = ControlRoomAlert::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'context' => ['keep' => 'incident-alert'],
        ]);
        $eventAlert = ControlRoomAlert::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'context' => ['keep' => 'event-alert'],
        ]);
        $event = HsEvent::factory()->create([
            'control_room_alert_id' => $eventAlert->id,
            'handover_status' => HsEvent::HANDOVER_NOT_REQUIRED,
        ]);
        $incident = $this->submittedIncidentWithoutEvents($client, $site, $actor, [
            'severity' => 'high',
            'control_room_alert_id' => $incidentAlert->id,
            'hs_event_id' => $event->id,
            'metadata' => ['keep' => 'incident-metadata'],
        ]);

        $exception = null;
        try {
            app(IncidentJourneyService::class)->ensureForSubmittedIncident($incident, $actor);
        } catch (\DomainException $caught) {
            $exception = $caught;
        }

        $this->assertNotNull($exception);
        $this->assertStringContainsString('conflict', strtolower($exception->getMessage()));
        $this->assertSame($incidentAlert->id, $incident->fresh()->control_room_alert_id);
        $this->assertSame($event->id, $incident->fresh()->hs_event_id);
        $this->assertSame(['keep' => 'incident-metadata'], $incident->fresh()->metadata);
        $this->assertSame($eventAlert->id, $event->fresh()->control_room_alert_id);
        $this->assertSame(HsEvent::HANDOVER_NOT_REQUIRED, $event->fresh()->handover_status);
        $this->assertSame(['keep' => 'incident-alert'], $incidentAlert->fresh()->context);
        $this->assertSame(['keep' => 'event-alert'], $eventAlert->fresh()->context);
        $this->assertDatabaseCount('control_room_alerts', 2);
        $this->assertDatabaseCount('hs_events', 1);
    }

    public function test_null_hs_event_service_result_rolls_back_alert_submission_completely(): void
    {
        $actor = User::factory()->create();
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $alert = ControlRoomAlert::factory()->triaging()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'context' => ['keep' => 'unchanged'],
            'notes' => 'Still triaging.',
        ]);
        $before = $alert->only(['status', 'severity', 'notes', 'context']);

        $this->mock(HsEventService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('recordEvent')->once()->andReturnNull();
        });

        $exception = null;
        try {
            app(IncidentJourneyService::class)->submitFromAlert(
                $alert,
                $this->incidentInput($client, $site),
                $actor,
            );
        } catch (\RuntimeException $caught) {
            $exception = $caught;
        }

        $this->assertNotNull($exception);
        $this->assertStringContainsString('h&s', strtolower($exception->getMessage()));
        $this->assertDatabaseCount('client_incidents', 0);
        $this->assertDatabaseCount('hs_events', 0);
        $this->assertDatabaseCount('control_room_alerts', 1);
        $this->assertSame($before, $alert->fresh()->only(array_keys($before)));
    }

    public function test_direct_health_safety_reads_fail_closed_on_client_or_site_mismatch(): void
    {
        $site = Site::factory()->create();
        $otherSite = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $otherClient = Client::factory()->create(['site_id' => $site->id]);

        foreach ([
            ['client_id' => $otherClient->id, 'site_id' => $site->id, 'field' => 'client_id'],
            ['client_id' => $client->id, 'site_id' => $otherSite->id, 'field' => 'site_id'],
        ] as $mismatch) {
            $incident = $this->incidentWithoutEvents([
                'client_id' => $client->id,
                'site_id' => $site->id,
            ]);
            $event = HsEvent::factory()->forClientIncident($incident)->create([
                'client_id' => $mismatch['client_id'],
                'site_id' => $mismatch['site_id'],
            ]);
            $incident->updateQuietly(['hs_event_id' => $event->id]);

            try {
                app(IncidentJourneyService::class)->journeyForIncident($incident->fresh());
                $this->fail("A mismatched direct H&S {$mismatch['field']} was accepted.");
            } catch (\DomainException $exception) {
                $this->assertStringContainsString($mismatch['field'], $exception->getMessage());
            }
        }
    }

    public function test_journey_lookup_uses_legacy_fallbacks_without_creating_or_repairing_records(): void
    {
        $actor = User::factory()->create();
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $incident = $this->incidentWithoutEvents([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'reported_by' => $actor->id,
            'status' => 'draft',
            'submitted_at' => null,
            'hs_event_id' => null,
            'control_room_alert_id' => null,
        ]);
        $alertContext = ['incident_id' => $incident->id, 'legacy' => 'context-only'];
        $alert = ControlRoomAlert::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'context' => $alertContext,
        ]);
        $event = HsEvent::factory()->forClientIncident($incident)->create([
            'handover_status' => HsEvent::HANDOVER_NOT_REQUIRED,
            'control_room_alert_id' => null,
        ]);

        $journey = app(IncidentJourneyService::class)->journeyForIncident($incident);

        $this->assertTrue($journey->incident->is($incident));
        $this->assertTrue($journey->alert->is($alert));
        $this->assertTrue($journey->hsEvent->is($event));
        $this->assertNull($incident->fresh()->control_room_alert_id);
        $this->assertNull($incident->fresh()->hs_event_id);
        $this->assertNull($event->fresh()->control_room_alert_id);
        $this->assertSame(HsEvent::HANDOVER_NOT_REQUIRED, $event->fresh()->handover_status);
        $this->assertEquals($alertContext, $alert->fresh()->context);
        $this->assertDatabaseCount('client_incidents', 1);
        $this->assertDatabaseCount('hs_events', 1);
        $this->assertDatabaseCount('control_room_alerts', 1);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function incidentInput(Client $client, Site $site, array $overrides = []): array
    {
        return array_replace([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'type' => 'fall',
            'severity' => 'high',
            'title' => 'Fall detected by operator',
            'description' => 'Resident found beside the bed.',
            'occurred_at' => now()->subMinutes(10),
            'requires_followup' => true,
            'immediate_action_taken' => 'Made the area safe.',
            'metadata' => [],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function incidentWithoutEvents(array $overrides = []): ClientIncident
    {
        return ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create(array_replace([
            'metadata' => null,
            'hs_event_id' => null,
            'control_room_alert_id' => null,
        ], $overrides)));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function submittedIncidentWithoutEvents(
        Client $client,
        Site $site,
        ?User $reporter,
        array $overrides = [],
    ): ClientIncident {
        return $this->incidentWithoutEvents(array_replace([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'reported_by' => $reporter?->id,
            'status' => 'submitted',
            'submitted_at' => now(),
            'type' => 'fall',
            'severity' => 'low',
            'title' => 'Submitted incident',
            'description' => 'Submitted without model events for journey testing.',
            'occurred_at' => now()->subMinutes(5),
        ], $overrides));
    }
}
