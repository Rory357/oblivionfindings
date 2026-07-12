<?php

namespace Tests\Feature\Incidents;

use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ControlRoom\AlertSla;
use App\Models\ControlRoom\AlertTask;
use App\Models\ControlRoom\EvidencePack;
use App\Models\ControlRoomAlert;
use App\Models\HsEvent;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\HsEventService;
use App\Services\Incidents\IncidentJourney;
use App\Services\Incidents\IncidentJourneyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_direct_links_beat_conflicting_legacy_context_and_idempotency_candidates(): void
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
        $legacyAlert = ControlRoomAlert::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'context' => ['incident_id' => $incident->id, 'legacy' => true],
        ]);
        $legacyEvent = HsEvent::factory()->forClientIncident($incident)->create([
            'handover_status' => HsEvent::HANDOVER_NOT_REQUIRED,
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
        $this->assertSame(HsEvent::HANDOVER_NOT_REQUIRED, $legacyEvent->fresh()->handover_status);
        $this->assertNull($legacyEvent->fresh()->control_room_alert_id);
        $this->assertTrue($directAlert->fresh()->clientIncident->is($incident));
        $this->assertTrue($directAlert->fresh()->hsEvent->is($directEvent));
        $this->assertDatabaseCount('control_room_alerts', 2);
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
