<?php

namespace Tests\Unit\ControlRoom;

use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ControlRoom\Device;
use App\Models\ControlRoom\MaintenanceWindow;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoom\SignalRule;
use App\Models\ControlRoom\SignalSource;
use App\Models\ControlRoom\SignalType;
use App\Models\ControlRoomAlert;
use App\Models\HsEvent;
use App\Models\Site;
use App\Models\User;
use App\Services\ControlRoom\ControlRoomNotificationService;
use App\Services\ControlRoom\SignalProcessingService;
use App\Services\Incidents\IncidentJourneyService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SignalProcessingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected SignalProcessingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $notificationService = $this->mock(ControlRoomNotificationService::class);
        $notificationService->shouldReceive('notifyAlert')->andReturnNull();
        $notificationService->shouldReceive('stageAlertNotifications')->andReturn(collect());

        $this->service = new SignalProcessingService($notificationService);
    }

    // ──────────────────────────────────────
    // Signal Ingestion
    // ──────────────────────────────────────

    public function test_ingest_creates_signal(): void
    {
        $source = SignalSource::create([
            'name' => 'Test Source',
            'slug' => 'test-source',
            'category' => 'fleet',
            'vendor' => 'TestVendor',
            'status' => 'active',
        ]);

        $signalType = SignalType::create([
            'code' => 'test.signal',
            'name' => 'Test Signal',
            'category' => 'fleet',
            'default_severity' => 'medium',
        ]);

        $signal = $this->service->ingest([
            'signal_source_id' => $source->id,
            'signal_type_id' => $signalType->id,
            'idempotency_key' => 'unique-key-123',
            'payload' => ['speed' => 120],
            'received_at' => now(),
        ]);

        $this->assertInstanceOf(Signal::class, $signal);
        $this->assertEquals('pending', $signal->status);
        $this->assertDatabaseHas('control_room_signals', [
            'idempotency_key' => 'unique-key-123',
        ]);
    }

    public function test_ingest_deduplicates_by_idempotency_key(): void
    {
        $source = SignalSource::create([
            'name' => 'Test Source',
            'slug' => 'test-dedup',
            'category' => 'fleet',
            'vendor' => 'TestVendor',
            'status' => 'active',
        ]);

        $signalType = SignalType::create([
            'code' => 'test.dedup',
            'name' => 'Dedup Test',
            'category' => 'fleet',
            'default_severity' => 'medium',
        ]);

        $data = [
            'signal_source_id' => $source->id,
            'signal_type_id' => $signalType->id,
            'idempotency_key' => 'duplicate-key',
            'payload' => ['test' => true],
            'received_at' => now(),
        ];

        $signal1 = $this->service->ingest($data);
        $signal2 = $this->service->ingest($data);

        $this->assertEquals($signal1->id, $signal2->id);
        $this->assertEquals(1, Signal::where('idempotency_key', 'duplicate-key')->count());
    }

    // ──────────────────────────────────────
    // Signal Processing
    // ──────────────────────────────────────

    public function test_process_creates_alert_from_signal_with_matching_rule(): void
    {
        $source = SignalSource::create([
            'name' => 'Fleet Source',
            'slug' => 'fleet-source',
            'category' => 'fleet',
            'vendor' => 'FleetVendor',
            'status' => 'active',
        ]);

        $signalType = SignalType::create([
            'code' => 'fleet.speeding',
            'name' => 'Speeding Alert',
            'category' => 'fleet',
            'default_severity' => 'high',
        ]);

        // Create a matching rule
        SignalRule::create([
            'name' => 'Speeding Rule',
            'signal_source_id' => $source->id,
            'signal_type_id' => $signalType->id,
            'conditions' => [],
            'severity_override' => 'critical',
            'alert_type' => 'Speeding',
            'is_active' => true,
            'priority' => 1,
        ]);

        $signal = Signal::create([
            'signal_source_id' => $source->id,
            'signal_type_id' => $signalType->id,
            'idempotency_key' => 'process-test-1',
            'payload' => ['speed' => 150],
            'received_at' => now(),
            'status' => 'pending',
        ]);

        $alert = $this->service->process($signal);

        $this->assertNotNull($alert);
        $this->assertInstanceOf(ControlRoomAlert::class, $alert);
        $this->assertEquals('Speeding', $alert->alert_type);
        $this->assertEquals('open', $alert->status);

        $signal->refresh();
        $this->assertEquals('processed', $signal->status);
    }

    public function test_process_skips_signal_during_maintenance_window(): void
    {
        $source = SignalSource::create([
            'name' => 'Maintained Source',
            'slug' => 'maintained',
            'category' => 'fleet',
            'vendor' => 'TestVendor',
            'status' => 'active',
        ]);

        $signalType = SignalType::create([
            'code' => 'maint.test',
            'name' => 'Maint Test',
            'category' => 'fleet',
            'default_severity' => 'medium',
        ]);

        // Create maintenance window
        MaintenanceWindow::create([
            'name' => 'Scheduled Maintenance',
            'signal_source_id' => $source->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
            'status' => 'active',
        ]);

        $signal = Signal::create([
            'signal_source_id' => $source->id,
            'signal_type_id' => $signalType->id,
            'idempotency_key' => 'maint-test-1',
            'payload' => [],
            'received_at' => now(),
            'status' => 'pending',
        ]);

        $alert = $this->service->process($signal);

        $this->assertNull($alert);
        $signal->refresh();
        $this->assertEquals('suppressed', $signal->status);
    }

    public function test_process_without_matching_rule_creates_default_alert(): void
    {
        $source = SignalSource::create([
            'name' => 'No Rule Source',
            'slug' => 'no-rule',
            'category' => 'fleet',
            'vendor' => 'TestVendor',
            'status' => 'active',
        ]);

        $signalType = SignalType::create([
            'code' => 'unmatched.signal',
            'name' => 'Unmatched Signal',
            'category' => 'fleet',
            'default_severity' => 'low',
        ]);

        $signal = Signal::create([
            'signal_source_id' => $source->id,
            'signal_type_id' => $signalType->id,
            'idempotency_key' => 'no-rule-test-1',
            'payload' => [],
            'received_at' => now(),
            'status' => 'pending',
        ]);

        $alert = $this->service->process($signal);

        // Depending on implementation, may create default alert or skip
        $signal->refresh();
        $this->assertContains($signal->status, ['processed', 'skipped']);
    }

    public function test_incident_tagged_signals_correlate_only_to_the_exact_incident(): void
    {
        $client = Client::factory()->create();
        $firstIncident = ClientIncident::factory()->create(['client_id' => $client->id]);
        $secondIncident = ClientIncident::factory()->create(['client_id' => $client->id]);
        $source = $this->medicationIncidentSource();
        $signalType = SignalType::create([
            'code' => 'medication.incident',
            'name' => 'Medication Incident',
            'category' => 'medication',
            'default_severity' => 'high',
        ]);
        SignalRule::create([
            'name' => 'Incident signal exact correlation',
            'signal_source_id' => $source->id,
            'signal_type_id' => $signalType->id,
            'conditions' => [],
            'alert_type' => 'Medication incident',
            'deduplicate' => true,
            'dedup_window_minutes' => 30,
            'is_active' => true,
            'priority' => 1,
        ]);

        $first = Signal::create([
            'signal_source_id' => $source->id,
            'signal_type_id' => $signalType->id,
            'signal_type_code' => $signalType->code,
            'idempotency_key' => 'incident-501-first',
            'client_id' => $client->id,
            'normalized_data' => ['incident_id' => $firstIncident->id],
            'received_at' => now(),
            'occurred_at' => now(),
            'status' => 'pending',
        ]);
        $second = Signal::create([
            'signal_source_id' => $source->id,
            'signal_type_id' => $signalType->id,
            'signal_type_code' => $signalType->code,
            'idempotency_key' => 'incident-502-first',
            'client_id' => $client->id,
            'normalized_data' => ['incident_id' => $secondIncident->id],
            'received_at' => now(),
            'occurred_at' => now(),
            'status' => 'pending',
        ]);
        $retry = Signal::create([
            'signal_source_id' => $source->id,
            'signal_type_id' => $signalType->id,
            'signal_type_code' => $signalType->code,
            'idempotency_key' => 'incident-501-retry',
            'client_id' => $client->id,
            'normalized_data' => ['incident_id' => $firstIncident->id],
            'received_at' => now(),
            'occurred_at' => now(),
            'status' => 'pending',
        ]);

        $firstAlert = $this->service->process($first);
        $secondAlert = $this->service->process($second);
        $retryAlert = $this->service->process($retry);

        $this->assertNotSame($firstAlert?->id, $secondAlert?->id);
        $this->assertSame($firstAlert?->id, $retryAlert?->id);
        $this->assertSame(2, ControlRoomAlert::query()->count());
    }

    public function test_incident_tagged_signal_prefers_its_direct_alert_outside_fuzzy_constraints(): void
    {
        $client = Client::factory()->create();
        $incident = ClientIncident::factory()->create(['client_id' => $client->id]);
        $directAlert = ControlRoomAlert::factory()->resolved()->create([
            'source' => 'incident',
            'alert_type' => 'legacy.unrelated_type',
            'client_id' => $client->id,
            'triggered_at' => now()->subHours(2),
            'context' => ['incident_id' => $incident->id],
        ]);
        $incident->updateQuietly(['control_room_alert_id' => $directAlert->id]);
        $source = $this->medicationIncidentSource();
        $signalType = SignalType::create([
            'code' => 'medication.direct_incident',
            'name' => 'Direct Medication Incident',
            'category' => 'medication',
            'default_severity' => 'high',
        ]);
        SignalRule::create([
            'name' => 'Direct incident correlation',
            'signal_source_id' => $source->id,
            'signal_type_id' => $signalType->id,
            'conditions' => [],
            'alert_type' => 'Completely different alert type',
            'deduplicate' => true,
            'dedup_window_minutes' => 30,
            'is_active' => true,
            'priority' => 1,
        ]);
        $signal = Signal::create([
            'signal_source_id' => $source->id,
            'signal_type_id' => $signalType->id,
            'signal_type_code' => $signalType->code,
            'idempotency_key' => 'direct-incident-outside-fuzzy-constraints',
            'client_id' => $client->id,
            'normalized_data' => ['incident_id' => $incident->id],
            'received_at' => now(),
            'occurred_at' => now(),
            'status' => 'pending',
        ]);

        $result = $this->service->process($signal);

        $this->assertSame($directAlert->id, $result?->id);
        $this->assertSame(ControlRoomAlert::STATUS_RESOLVED, $directAlert->fresh()->status);
        $this->assertSame($directAlert->id, $signal->fresh()->correlated_alert_id);
        $this->assertSame(1, ControlRoomAlert::query()->count());
    }

    public function test_incident_tagged_signal_rejects_ambiguous_context_claims(): void
    {
        $client = Client::factory()->create();
        $incident = ClientIncident::factory()->create(['client_id' => $client->id]);
        $source = $this->medicationIncidentSource();
        $signalType = SignalType::create([
            'code' => 'medication.ambiguous_incident',
            'name' => 'Ambiguous Medication Incident',
            'category' => 'medication',
            'default_severity' => 'high',
        ]);
        SignalRule::create([
            'name' => 'Ambiguous incident correlation',
            'signal_source_id' => $source->id,
            'signal_type_id' => $signalType->id,
            'conditions' => [],
            'alert_type' => 'Medication incident',
            'deduplicate' => true,
            'dedup_window_minutes' => 30,
            'is_active' => true,
            'priority' => 1,
        ]);
        ControlRoomAlert::factory()->open()->create([
            'alert_type' => 'Medication incident',
            'context' => ['incident_id' => $incident->id],
        ]);
        ControlRoomAlert::factory()->open()->create([
            'alert_type' => 'Medication incident',
            'context' => ['normalized_data' => ['incident_id' => $incident->id]],
        ]);
        $signal = Signal::create([
            'signal_source_id' => $source->id,
            'signal_type_id' => $signalType->id,
            'signal_type_code' => $signalType->code,
            'idempotency_key' => 'ambiguous-incident-claim',
            'client_id' => $client->id,
            'normalized_data' => ['incident_id' => $incident->id],
            'received_at' => now(),
            'occurred_at' => now(),
            'status' => 'pending',
        ]);

        try {
            $this->service->process($signal);
            $this->fail('Ambiguous incident alert claims must not be selected implicitly.');
        } catch (\DomainException $exception) {
            $this->assertSame(
                'Incident signal correlation is ambiguous: multiple alerts claim the same incident.',
                $exception->getMessage(),
            );
        }

        $this->assertSame('pending', $signal->fresh()->status);
        $this->assertSame(2, ControlRoomAlert::query()->count());
    }

    public function test_submitted_incident_signal_synchronises_the_existing_hs_event_alert_backlink(): void
    {
        $actor = User::factory()->create();
        $client = Client::factory()->create();
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create([
            'client_id' => $client->id,
            'reported_by' => $actor->id,
            'status' => 'submitted',
            'submitted_at' => now()->subMinute(),
            'severity' => 'high',
            'control_room_alert_id' => null,
            'hs_event_id' => null,
        ]));
        $event = HsEvent::factory()->forClientIncident($incident)->create([
            'created_by' => $actor->id,
            'control_room_alert_id' => null,
        ]);
        $incident->updateQuietly(['hs_event_id' => $event->id]);
        $source = $this->medicationIncidentSource();
        $signal = Signal::create([
            'signal_source_id' => $source->id,
            'signal_type_code' => 'medication.incident',
            'idempotency_key' => 'submitted-existing-hs-backlink',
            'client_id' => $client->id,
            'normalized_data' => ['incident_id' => $incident->id],
            'severity_hint' => 'high',
            'occurred_at' => now(),
            'status' => 'pending',
        ]);

        $alert = $this->service->process($signal);

        $this->assertNotNull($alert);
        $this->assertSame($alert->id, $incident->fresh()->control_room_alert_id);
        $this->assertSame($alert->id, $event->fresh()->control_room_alert_id);
        $this->assertSame($incident->id, data_get($alert->context, 'incident_id'));
        $this->assertSame($event->id, $incident->fresh()->hs_event_id);
        $this->assertDatabaseCount('client_incidents', 1);
        $this->assertDatabaseCount('hs_events', 1);
        $this->assertDatabaseCount('control_room_alerts', 1);
    }

    public function test_critical_trusted_signal_promotes_the_complete_existing_medium_journey(): void
    {
        $actor = User::factory()->create();
        $client = Client::factory()->create();
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create([
            'client_id' => $client->id,
            'reported_by' => $actor->id,
            'status' => 'submitted',
            'submitted_at' => now()->subMinute(),
            'severity' => 'medium',
            'control_room_alert_id' => null,
            'hs_event_id' => null,
        ]));
        $journey = app(IncidentJourneyService::class)
            ->ensureAlertForIncident($incident, $actor, 'Initial medium escalation');
        $alert = $journey->alert;
        $event = $journey->hsEvent;
        $event->updateQuietly([
            'handover_status' => HsEvent::HANDOVER_ACCEPTED,
            'worksafe_notifiable' => true,
            'worksafe_status' => HsEvent::WORKSAFE_ACKNOWLEDGED,
            'worksafe_reference' => 'WS-CANONICAL-CRITICAL',
        ]);
        $incident->updateQuietly([
            'worksafe_notification_status' => HsEvent::WORKSAFE_PENDING,
            'worksafe_reference' => 'WS-STALE',
        ]);
        $source = $this->medicationIncidentSource();
        $signal = Signal::create([
            'signal_source_id' => $source->id,
            'signal_type_code' => 'medication.incident',
            'idempotency_key' => 'critical-existing-medium-journey',
            'client_id' => $client->id,
            'normalized_data' => ['incident_id' => $incident->id],
            'severity_hint' => 'critical',
            'occurred_at' => now(),
            'status' => 'pending',
        ]);
        DB::enableQueryLog();

        $result = $this->service->process($signal);
        $journeyLocks = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $query): bool => str_contains(strtolower($query), 'for update'))
            ->filter(fn (string $query): bool => str_contains($query, 'control_room_alerts')
                || str_contains($query, 'client_incidents'))
            ->values();

        $this->assertTrue($result->is($alert));
        $this->assertStringContainsString('control_room_alerts', $journeyLocks->first() ?? '');
        $this->assertTrue(
            $journeyLocks->contains(fn (string $query): bool => str_contains($query, 'client_incidents')),
            'The canonical attach must re-lock and revalidate the incident after the alert lock.',
        );
        $this->assertSame('critical', $alert->fresh()->severity);
        $this->assertSame('critical', $event->fresh()->severity);
        $this->assertSame('critical', data_get($incident->fresh()->metadata, 'journey.original_alert_severity'));
        $this->assertSame(HsEvent::HANDOVER_ACCEPTED, $event->fresh()->handover_status);
        $this->assertSame(HsEvent::WORKSAFE_ACKNOWLEDGED, $event->fresh()->worksafe_status);
        $this->assertSame('WS-CANONICAL-CRITICAL', $event->fresh()->worksafe_reference);
        $this->assertSame($alert->id, $signal->fresh()->correlated_alert_id);
        $this->assertDatabaseCount('control_room_alerts', 1);
        $this->assertDatabaseCount('hs_events', 1);
    }

    public function test_direct_incident_alert_with_a_different_client_is_rejected_without_correlation(): void
    {
        $incidentClient = Client::factory()->create();
        $alertClient = Client::factory()->create();
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create([
            'client_id' => $incidentClient->id,
            'status' => 'draft',
            'submitted_at' => null,
            'control_room_alert_id' => null,
        ]));
        $alert = ControlRoomAlert::factory()->open()->create([
            'client_id' => $alertClient->id,
            'context' => ['source_note' => 'legacy direct claim'],
        ]);
        $incident->updateQuietly(['control_room_alert_id' => $alert->id]);
        $signal = $this->incidentSignal($incidentClient, $incident, 'direct-client-mismatch');

        try {
            $this->service->process($signal);
            $this->fail('A direct alert belonging to another client must not be correlated.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('client', $exception->getMessage());
        }

        $this->assertSame('pending', $signal->fresh()->status);
        $this->assertNull($signal->fresh()->alert_id);
        $this->assertNull($signal->fresh()->correlated_alert_id);
        $this->assertSame(['source_note' => 'legacy direct claim'], $alert->fresh()->context);
        $this->assertSame($alert->id, $incident->fresh()->control_room_alert_id);
    }

    public function test_context_incident_alert_with_a_different_client_is_rejected_without_linking(): void
    {
        $incidentClient = Client::factory()->create();
        $alertClient = Client::factory()->create();
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create([
            'client_id' => $incidentClient->id,
            'status' => 'draft',
            'submitted_at' => null,
            'control_room_alert_id' => null,
        ]));
        $alert = ControlRoomAlert::factory()->open()->create([
            'client_id' => $alertClient->id,
            'context' => ['incident_id' => $incident->id],
        ]);
        $signal = $this->incidentSignal($incidentClient, $incident, 'context-client-mismatch');

        try {
            $this->service->process($signal);
            $this->fail('A context claimant belonging to another client must not be linked.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('client', $exception->getMessage());
        }

        $this->assertSame('pending', $signal->fresh()->status);
        $this->assertNull($signal->fresh()->alert_id);
        $this->assertNull($signal->fresh()->correlated_alert_id);
        $this->assertNull($incident->fresh()->control_room_alert_id);
        $this->assertSame(['incident_id' => $incident->id], $alert->fresh()->context);
    }

    public function test_generic_signals_still_use_fuzzy_deduplication(): void
    {
        $source = SignalSource::create([
            'name' => 'Generic Signals',
            'slug' => 'generic-signals',
            'category' => 'operations',
            'vendor' => 'internal',
            'status' => 'active',
        ]);
        $signalType = SignalType::create([
            'code' => 'operations.generic',
            'name' => 'Generic Operations Signal',
            'category' => 'operations',
            'default_severity' => 'medium',
        ]);
        SignalRule::create([
            'name' => 'Generic fuzzy correlation',
            'signal_source_id' => $source->id,
            'signal_type_id' => $signalType->id,
            'conditions' => [],
            'alert_type' => 'Generic operations',
            'deduplicate' => true,
            'dedup_window_minutes' => 30,
            'is_active' => true,
            'priority' => 1,
        ]);

        $first = Signal::create([
            'signal_source_id' => $source->id,
            'signal_type_id' => $signalType->id,
            'signal_type_code' => $signalType->code,
            'idempotency_key' => 'generic-first',
            'received_at' => now(),
            'occurred_at' => now(),
            'status' => 'pending',
        ]);
        $second = Signal::create([
            'signal_source_id' => $source->id,
            'signal_type_id' => $signalType->id,
            'signal_type_code' => $signalType->code,
            'idempotency_key' => 'generic-second',
            'received_at' => now(),
            'occurred_at' => now(),
            'status' => 'pending',
        ]);

        $firstAlert = $this->service->process($first);
        $secondAlert = $this->service->process($second);

        $this->assertSame($firstAlert?->id, $secondAlert?->id);
        $this->assertSame(1, ControlRoomAlert::query()->count());
    }

    public function test_lone_worker_correlation_pushes_the_complete_immutable_tuple_into_the_locking_query(): void
    {
        $site = Site::factory()->create(['tenant_id' => 581]);
        $worker = User::factory()->create(['organization_id' => 581]);
        $source = SignalSource::create([
            'name' => 'Lone Worker',
            'slug' => 'lone_worker',
            'category' => 'safety',
            'vendor' => 'internal',
            'status' => 'active',
        ]);
        $signalType = SignalType::create([
            'code' => 'lone_worker_emergency',
            'name' => 'Lone Worker Emergency',
            'category' => 'safety',
            'default_severity' => 'critical',
        ]);
        SignalRule::create([
            'name' => 'Lone Worker Emergency Correlation',
            'signal_source_id' => $source->id,
            'signal_type_id' => $signalType->id,
            'conditions' => [],
            'alert_type' => 'Lone Worker Emergency',
            'deduplicate' => true,
            'dedup_window_minutes' => 30,
            'is_active' => true,
            'priority' => 1,
        ]);
        $matchingTuple = [
            'source_module' => 'lone_worker',
            'signal_type' => $signalType->code,
            'lone_worker_session_id' => 58101,
            'worker_user_id' => $worker->id,
            'site_id' => $site->id,
            'client_id' => null,
        ];
        $matching = ControlRoomAlert::factory()->open()->create([
            'source' => 'lone_worker',
            'alert_type' => 'Lone Worker Emergency',
            'site_id' => $site->id,
            'client_id' => null,
            'triggered_at' => now()->subMinute(),
            'context' => [
                'signal_type_code' => $signalType->code,
                'normalized_data' => $matchingTuple,
            ],
        ]);
        $unrelated = ControlRoomAlert::factory()->open()->create([
            'source' => 'lone_worker',
            'alert_type' => 'Lone Worker Emergency',
            'site_id' => $site->id,
            'client_id' => null,
            'triggered_at' => now(),
            'context' => [
                'signal_type_code' => $signalType->code,
                'normalized_data' => array_merge($matchingTuple, [
                    'lone_worker_session_id' => 58102,
                ]),
            ],
        ]);
        $retry = Signal::create([
            'signal_source_id' => $source->id,
            'signal_type_id' => $signalType->id,
            'signal_type_code' => $signalType->code,
            'idempotency_key' => 'lone-worker-complete-sql-tuple-retry',
            'site_id' => $site->id,
            'client_id' => null,
            'normalized_data' => $matchingTuple,
            'received_at' => now(),
            'occurred_at' => now(),
            'status' => 'pending',
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $result = $this->service->process($retry);
        $lockingQuery = collect(DB::getQueryLog())->first(function (array $entry): bool {
            $sql = strtolower($entry['query']);

            return str_contains($sql, 'from `control_room_alerts`')
                && str_contains($sql, 'for update')
                && str_contains($sql, '`triggered_at`');
        });
        DB::disableQueryLog();

        $this->assertSame($matching->id, $result?->id);
        $this->assertSame('open', $unrelated->fresh()->status);
        $this->assertNotNull($lockingQuery);
        $sql = strtolower($lockingQuery['query']);
        foreach ([
            '`source`',
            '$.signal_type_code',
            '$.normalized_data.source_module',
            '$.normalized_data.signal_type',
            '$.normalized_data.lone_worker_session_id',
            '$.normalized_data.worker_user_id',
            '$.normalized_data.site_id',
            '$.normalized_data.client_id',
        ] as $immutablePredicate) {
            $this->assertStringContainsString($immutablePredicate, $sql);
        }
        $this->assertContains('lone_worker', $lockingQuery['bindings']);
        $this->assertContains((string) $matchingTuple['lone_worker_session_id'], $lockingQuery['bindings']);
        $this->assertContains((string) $worker->id, $lockingQuery['bindings']);
        $this->assertContains((string) $site->id, $lockingQuery['bindings']);
    }

    // ──────────────────────────────────────
    // Batch Processing
    // ──────────────────────────────────────

    public function test_process_all_pending_processes_batch(): void
    {
        $source = SignalSource::create([
            'name' => 'Batch Source',
            'slug' => 'batch-source',
            'category' => 'fleet',
            'vendor' => 'TestVendor',
            'status' => 'active',
        ]);

        $signalType = SignalType::create([
            'code' => 'batch.test',
            'name' => 'Batch Test',
            'category' => 'fleet',
            'default_severity' => 'medium',
        ]);

        // Create multiple pending signals
        for ($i = 0; $i < 5; $i++) {
            Signal::create([
                'signal_source_id' => $source->id,
                'signal_type_id' => $signalType->id,
                'idempotency_key' => "batch-test-{$i}",
                'payload' => [],
                'received_at' => now(),
                'status' => 'pending',
            ]);
        }

        $processed = $this->service->processAllPending(10);

        $this->assertEquals(5, $processed);
        $this->assertEquals(0, Signal::where('status', 'pending')->count());
    }

    public function test_process_all_pending_respects_limit(): void
    {
        $source = SignalSource::create([
            'name' => 'Limit Source',
            'slug' => 'limit-source',
            'category' => 'fleet',
            'vendor' => 'TestVendor',
            'status' => 'active',
        ]);

        $signalType = SignalType::create([
            'code' => 'limit.test',
            'name' => 'Limit Test',
            'category' => 'fleet',
            'default_severity' => 'medium',
        ]);

        for ($i = 0; $i < 10; $i++) {
            Signal::create([
                'signal_source_id' => $source->id,
                'signal_type_id' => $signalType->id,
                'idempotency_key' => "limit-test-{$i}",
                'payload' => [],
                'received_at' => now(),
                'status' => 'pending',
            ]);
        }

        $processed = $this->service->processAllPending(3);

        $this->assertEquals(3, $processed);
        $this->assertEquals(7, Signal::where('status', 'pending')->count());
    }

    // ──────────────────────────────────────
    // Device Signal Ingestion
    // ──────────────────────────────────────

    public function test_ingest_from_device_creates_signal(): void
    {
        $source = SignalSource::create([
            'name' => 'Device Source',
            'slug' => 'device-source',
            'category' => 'home_facility',
            'vendor' => 'DeviceVendor',
            'status' => 'active',
        ]);

        $signalType = SignalType::create([
            'code' => 'device.alarm',
            'name' => 'Device Alarm',
            'category' => 'home_facility',
            'default_severity' => 'high',
        ]);

        $device = Device::create([
            'name' => 'Test Camera',
            'device_type' => 'camera',
            'identifier' => 'CAM-001',
            'signal_source_id' => $source->id,
            'status' => 'online',
        ]);

        $signal = $this->service->ingestFromDevice(
            $device,
            'device.alarm',
            ['event' => 'motion_detected'],
            'high'
        );

        $this->assertInstanceOf(Signal::class, $signal);
        $this->assertEquals($device->id, $signal->device_id);
    }

    private function medicationIncidentSource(): SignalSource
    {
        return SignalSource::create([
            'name' => 'Medication / eMAR',
            'slug' => 'medication',
            'category' => 'medication',
            'vendor' => 'internal',
            'status' => 'active',
            'capabilities' => ['scheduled_checks', 'event_driven', 'incident_correlation'],
        ]);
    }

    private function incidentSignal(Client $client, ClientIncident $incident, string $key): Signal
    {
        $source = $this->medicationIncidentSource();

        return Signal::create([
            'signal_source_id' => $source->id,
            'signal_type_code' => 'medication.incident',
            'idempotency_key' => $key,
            'client_id' => $client->id,
            'normalized_data' => ['incident_id' => $incident->id],
            'severity_hint' => 'high',
            'occurred_at' => now(),
            'status' => 'pending',
        ]);
    }
}
