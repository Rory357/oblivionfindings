<?php

namespace Tests\Feature\ControlRoom;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ControlRoom\OperatorNote;
use App\Models\ControlRoomAlert;
use App\Models\HsEvent;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\ControlRoom\AlertWorkspaceService;
use App\Services\Incidents\IncidentJourneyService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Tests\TestCase;

class ControlRoomIncidentControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $supportWorker;

    protected Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->admin = User::factory()->create([
            'organization_id' => 1,
            'role' => 'admin',
            'approved_at' => now(),
        ]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->supportWorker = User::factory()->create([
            'organization_id' => 1,
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $this->supportWorker->roles()->attach(Role::where('name', 'support_worker')->first());
        $this->site = Site::factory()->create([
            'tenant_id' => 1,
            'type' => 'house',
        ]);
    }

    public function test_index_requires_view_permission(): void
    {
        $stranger = User::factory()->create([
            'organization_id' => 1,
            'approved_at' => now(),
        ]);

        $this->actingAs($stranger)
            ->get('/control-room/incidents')
            ->assertForbidden();
    }

    public function test_index_renders_only_the_canonical_handover_feed(): void
    {
        $response = $this->actingAs($this->admin)
            ->get('/control-room/incidents');

        $response
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('control-room/incidents')
                ->has('journeys.data')
                ->has('stats')
                ->has('filters')
                ->has('sites')
                ->missing('incidents')
                ->missing('clients')
                ->missing('can')
            );

        DB::flushQueryLog();
        DB::enableQueryLog();

        $version = app(HandleInertiaRequests::class)->version(request());
        $this->actingAs($this->admin)
            ->get('/control-room/incidents', [
                'X-Inertia' => 'true',
                'X-Inertia-Version' => $version,
                'X-Inertia-Partial-Component' => 'control-room/incidents',
                'X-Inertia-Partial-Data' => 'journeys,stats,filters,sites',
            ])
            ->assertOk()
            ->assertHeader('X-Inertia', 'true')
            ->assertJsonMissingPath('props.incidents');

        $queries = collect(DB::getQueryLog())->pluck('query')->implode("\n");
        DB::disableQueryLog();

        $this->assertStringNotContainsString('medication_errors', $queries);
        $this->assertStringNotContainsString('safeguarding_concerns', $queries);
    }

    public function test_canonical_handover_feed_resolves_the_client_full_name(): void
    {
        $site = Site::factory()->create(['tenant_id' => 1, 'type' => 'house']);
        $client = Client::factory()->create([
            'organization_id' => 1,
            'site_id' => $site->id,
            'first_name' => 'Aroha',
            'last_name' => 'Kingi',
        ]);

        $alert = ControlRoomAlert::factory()->open()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'severity' => 'low',
            'notes' => 'Canonical feed name check',
        ]);

        $this->actingAs($this->admin)
            ->get('/control-room/incidents')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('control-room/incidents')
                ->where('journeys.data.0.alert.id', $alert->id)
                ->where('journeys.data.0.person.name', 'Aroha Kingi')
            );
    }

    public function test_create_alert_from_incident_requires_create_permission(): void
    {
        $site = Site::factory()->create(['tenant_id' => 1, 'type' => 'house']);
        $client = Client::factory()->create([
            'organization_id' => 1,
            'site_id' => $site->id,
        ]);

        $incident = ClientIncident::create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'reported_by' => $this->admin->id,
            'title' => 'Slip in hallway',
            'type' => 'injury',
            'severity' => 'high',
            'status' => 'submitted',
            'occurred_at' => now()->subHour(),
            'description' => 'Slip in hallway',
        ]);

        $this->actingAs($this->supportWorker)
            ->post('/control-room/incidents/create-alert', [
                'source_type' => 'client_incident',
                'source_id' => $incident->id,
                'severity' => 'high',
            ])
            ->assertForbidden();
    }

    public function test_create_alert_from_incident_validates_source_type(): void
    {
        $this->actingAs($this->admin)
            ->post('/control-room/incidents/create-alert', [
                'source_type' => 'invalid',
                'source_id' => 1,
                'severity' => 'high',
            ])
            ->assertSessionHasErrors('source_type');
    }

    public function test_create_alert_from_submitted_client_incident_reuses_the_canonical_journey_on_first_and_repeated_calls(): void
    {
        $site = Site::factory()->create(['tenant_id' => 1, 'type' => 'house']);
        $client = Client::factory()->create([
            'organization_id' => 1,
            'site_id' => $site->id,
        ]);

        $incident = ClientIncident::create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'reported_by' => $this->admin->id,
            'title' => 'Slip in hallway',
            'type' => 'injury',
            'severity' => 'high',
            'status' => 'submitted',
            'occurred_at' => now()->subHour(),
            'description' => 'Slip in hallway',
        ]);

        $canonicalAlert = ControlRoomAlert::query()->sole();
        $canonicalHsEvent = HsEvent::query()->sole();
        $reason = 'Operator requested Control Room review';
        $payload = [
            'source_type' => 'client_incident',
            'source_id' => $incident->id,
            'severity' => 'medium',
            'notes' => $reason,
        ];

        $this->actingAs($this->admin)
            ->post('/control-room/incidents/create-alert', $payload)
            ->assertRedirect()
            ->assertSessionHas('created_alert_id', $canonicalAlert->id);

        $this->actingAs($this->admin)
            ->post('/control-room/incidents/create-alert', $payload)
            ->assertRedirect()
            ->assertSessionHas('created_alert_id', $canonicalAlert->id);

        $incident->refresh();
        $alert = $canonicalAlert->fresh();
        $hsEvent = $canonicalHsEvent->fresh();

        $this->assertDatabaseCount('control_room_alerts', 1);
        $this->assertDatabaseCount('hs_events', 1);
        $this->assertSame($alert->id, $incident->control_room_alert_id);
        $this->assertSame($hsEvent->id, $incident->hs_event_id);
        $this->assertSame($alert->id, $hsEvent->control_room_alert_id);
        $this->assertSame($incident->id, $alert->context['incident_id']);
        $this->assertSame($reason, $alert->context['reason']);
        $this->assertSame('incident', $alert->source);
        $this->assertSame('incident_journey', $alert->context['provenance']['source']);
        $this->assertSame(IncidentJourneyService::class, $alert->context['provenance']['service']);
        $this->assertSame('high', $alert->severity);
        $this->assertSame('high', $hsEvent->severity);
    }

    public function test_create_alert_from_low_incident_honours_a_critical_operator_escalation(): void
    {
        $site = Site::factory()->create(['tenant_id' => 1, 'type' => 'house']);
        $client = Client::factory()->create([
            'organization_id' => 1,
            'site_id' => $site->id,
        ]);
        $incident = ClientIncident::create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'reported_by' => $this->admin->id,
            'title' => 'Low incident requiring urgent review',
            'type' => 'injury',
            'severity' => 'low',
            'status' => 'submitted',
            'occurred_at' => now()->subHour(),
            'description' => 'The operator has identified critical escalation evidence.',
        ]);

        $this->assertDatabaseCount('control_room_alerts', 0);
        $this->assertDatabaseCount('hs_events', 1);

        $this->actingAs($this->admin)
            ->post('/control-room/incidents/create-alert', [
                'source_type' => 'client_incident',
                'source_id' => $incident->id,
                'severity' => 'critical',
                'notes' => 'Operator identified a critical escalation.',
            ])
            ->assertRedirect();

        $incident->refresh();
        $alert = ControlRoomAlert::query()->sole();
        $hsEvent = HsEvent::query()->sole();

        $this->assertSame('low', $incident->severity);
        $this->assertSame('critical', $alert->severity);
        $this->assertSame('critical', $hsEvent->severity);
        $this->assertSame('critical', data_get($incident->metadata, 'journey.original_alert_severity'));
        $this->assertSame('incident', data_get($incident->metadata, 'journey.original_alert_source'));
        $this->assertSame($alert->id, $incident->control_room_alert_id);
        $this->assertSame($alert->id, $hsEvent->control_room_alert_id);
        $this->assertSame($incident->id, data_get($alert->context, 'incident_id'));
        $this->assertDatabaseCount('control_room_alerts', 1);
        $this->assertDatabaseCount('hs_events', 1);
    }

    public function test_requested_incident_alert_severity_is_monotonic_below_critical(): void
    {
        $site = Site::factory()->create(['tenant_id' => 1, 'type' => 'house']);
        $client = Client::factory()->create([
            'organization_id' => 1,
            'site_id' => $site->id,
        ]);
        $incident = ClientIncident::create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'reported_by' => $this->admin->id,
            'title' => 'Low incident requiring elevated review',
            'type' => 'injury',
            'severity' => 'low',
            'status' => 'submitted',
            'occurred_at' => now()->subHour(),
            'description' => 'The operator has identified high-severity evidence.',
        ]);

        foreach (['high', 'medium'] as $requestedSeverity) {
            $this->actingAs($this->admin)
                ->post('/control-room/incidents/create-alert', [
                    'source_type' => 'client_incident',
                    'source_id' => $incident->id,
                    'severity' => $requestedSeverity,
                    'notes' => 'Operator requested elevated review.',
                ])
                ->assertRedirect();
        }

        $incident->refresh();
        $alert = ControlRoomAlert::query()->sole();
        $hsEvent = HsEvent::query()->sole();

        $this->assertSame('low', $incident->severity);
        $this->assertSame('high', $alert->severity);
        $this->assertSame('high', $hsEvent->severity);
        $this->assertSame('high', data_get($incident->metadata, 'journey.original_alert_severity'));
        $this->assertDatabaseCount('control_room_alerts', 1);
        $this->assertDatabaseCount('hs_events', 1);
    }

    public function test_flag_as_incident_requires_create_permission(): void
    {
        $client = Client::factory()->create([
            'organization_id' => 1,
            'site_id' => $this->site->id,
        ]);

        $this->actingAs($this->supportWorker)
            ->post('/control-room/incidents/flag', [
                'client_id' => $client->id,
                'type' => 'fall',
                'severity' => 'high',
            ])
            ->assertForbidden();
    }

    public function test_operator_notes_persist_supported_purposes_and_reject_unknown_purpose(): void
    {
        $alert = ControlRoomAlert::factory()->open()->create([
            'site_id' => $this->site->id,
        ]);

        foreach ([
            'general',
            'immediate_controls',
            'escalation_handover',
        ] as $purpose) {
            $this->actingAs($this->admin)
                ->post("/control-room/alerts/{$alert->id}/note", [
                    'note' => "Note for {$purpose}",
                    'purpose' => $purpose,
                ])
                ->assertRedirect()
                ->assertSessionDoesntHaveErrors();

            $this->assertDatabaseHas('control_room_operator_notes', [
                'alert_id' => $alert->id,
                'user_id' => $this->admin->id,
                'type' => 'note',
                'purpose' => $purpose,
                'content' => "Note for {$purpose}",
            ]);
        }

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/note", [
                'note' => 'Unknown purpose must not persist.',
                'purpose' => 'free_text_bucket',
            ])
            ->assertSessionHasErrors('purpose');

        $this->assertDatabaseCount('control_room_operator_notes', 3);
        $audit = AuditLog::query()
            ->where('action', 'controlRoom.alert.addNote')
            ->where('auditable_id', $alert->id)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('note', data_get($audit->meta, 'note_type'));
        $this->assertSame(
            'escalation_handover',
            data_get($audit->meta, 'note_purpose'),
        );
        $this->assertNotNull(data_get($audit->meta, 'note_id'));
    }

    public function test_workspace_uses_the_latest_immediate_controls_note_by_created_time_then_id(): void
    {
        $client = Client::factory()->create([
            'organization_id' => 1,
            'site_id' => $this->site->id,
        ]);
        $alert = ControlRoomAlert::factory()->high()->open()->create([
            'client_id' => $client->id,
            'site_id' => $this->site->id,
        ]);
        $at = now()->subMinute()->startOfSecond();

        $first = OperatorNote::unguarded(fn () => OperatorNote::query()->create([
            'alert_id' => $alert->id,
            'type' => 'note',
            'purpose' => 'immediate_controls',
            'content' => 'First controls at the same timestamp.',
            'user_id' => $this->admin->id,
            'created_at' => $at,
            'updated_at' => $at,
        ]));
        $second = OperatorNote::unguarded(fn () => OperatorNote::query()->create([
            'alert_id' => $alert->id,
            'type' => 'note',
            'purpose' => 'immediate_controls',
            'content' => 'Second controls win by note id.',
            'user_id' => $this->admin->id,
            'created_at' => $at,
            'updated_at' => $at,
        ]));
        OperatorNote::unguarded(fn () => OperatorNote::query()->create([
            'alert_id' => $alert->id,
            'type' => 'note',
            'purpose' => 'general',
            'content' => 'A later general update must not replace controls.',
            'user_id' => $this->admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $workspace = app(AlertWorkspaceService::class)->build(
            $this->admin,
            $alert->id,
        );

        $this->assertSame(
            'Second controls win by note id.',
            data_get($workspace, 'incident_defaults.immediate_action_taken'),
        );
        $this->assertSame(
            $second->id,
            data_get($workspace, 'incident_defaults.source_note.id'),
        );
        $this->assertSame(
            $this->admin->name,
            data_get($workspace, 'incident_defaults.source_note.user_name'),
        );
        $this->assertNotSame(
            $first->id,
            data_get($workspace, 'incident_defaults.source_note.id'),
        );
    }

    public function test_serious_alert_incident_requires_immediate_action_and_accepts_an_edited_prefill(): void
    {
        $client = Client::factory()->create([
            'organization_id' => 1,
            'site_id' => $this->site->id,
        ]);
        $alert = ControlRoomAlert::factory()->high()->open()->create([
            'client_id' => $client->id,
            'site_id' => $this->site->id,
        ]);

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/create-incident", [
                'type' => 'fall',
                'description' => 'Resident was found beside the bed.',
            ])
            ->assertSessionHasErrors('immediate_action_taken');

        $this->assertDatabaseCount('client_incidents', 0);

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/create-incident", [
                'type' => 'fall',
                'description' => 'Resident was found beside the bed.',
                'immediate_action_taken' => 'Operator edited the prefill: area isolated and RN called.',
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $this->assertSame(
            'Operator edited the prefill: area isolated and RN called.',
            ClientIncident::query()->sole()->immediate_action_taken,
        );
    }

    public function test_explicit_no_immediate_control_truth_is_accepted_for_a_critical_alert(): void
    {
        $client = Client::factory()->create([
            'organization_id' => 1,
            'site_id' => $this->site->id,
        ]);
        $alert = ControlRoomAlert::factory()->critical()->open()->create([
            'client_id' => $client->id,
            'site_id' => $this->site->id,
        ]);

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/create-incident", [
                'type' => 'injury',
                'immediate_action_taken' => 'No immediate control was possible',
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $this->assertSame(
            'No immediate control was possible',
            ClientIncident::query()->sole()->immediate_action_taken,
        );
    }

    public function test_task12_review_effective_high_incident_severity_requires_immediate_action_even_when_alert_is_low(): void
    {
        $client = Client::factory()->create([
            'organization_id' => 1,
            'site_id' => $this->site->id,
        ]);
        $alert = ControlRoomAlert::factory()->create([
            'severity' => 'low',
            'client_id' => $client->id,
            'site_id' => $this->site->id,
        ]);

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/create-incident", [
                'type' => 'fall',
                'severity' => 'high',
            ])
            ->assertSessionHasErrors('immediate_action_taken');

        $this->assertDatabaseCount('client_incidents', 0);
    }

    public function test_low_and_medium_quick_flags_remain_valid_without_immediate_action(): void
    {
        foreach (['low', 'medium'] as $severity) {
            $client = Client::factory()->create([
                'organization_id' => 1,
                'site_id' => $this->site->id,
            ]);

            $this->actingAs($this->admin)
                ->post('/control-room/incidents/flag', [
                    'client_id' => $client->id,
                    'type' => 'other',
                    'severity' => $severity,
                    'note' => "Routine {$severity} incident.",
                ])
                ->assertRedirect()
                ->assertSessionDoesntHaveErrors();
        }

        $this->assertDatabaseCount('client_incidents', 2);
        $this->assertDatabaseCount('control_room_alerts', 2);
    }

    public function test_serious_quick_flag_requires_immediate_action_and_persists_explicit_truth(): void
    {
        $client = Client::factory()->create([
            'organization_id' => 1,
            'site_id' => $this->site->id,
        ]);

        $this->actingAs($this->admin)
            ->post('/control-room/incidents/flag', [
                'client_id' => $client->id,
                'type' => 'fall',
                'severity' => 'high',
                'note' => 'Resident on the floor.',
            ])
            ->assertSessionHasErrors('immediate_action_taken');

        $this->assertDatabaseCount('client_incidents', 0);
        $this->assertDatabaseCount('control_room_alerts', 0);

        $this->actingAs($this->admin)
            ->post('/control-room/incidents/flag', [
                'client_id' => $client->id,
                'type' => 'fall',
                'severity' => 'critical',
                'note' => 'Resident on the floor.',
                'immediate_action_taken' => 'No immediate control was possible',
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $this->assertSame(
            'No immediate control was possible',
            ClientIncident::query()->sole()->immediate_action_taken,
        );
    }

    public function test_flag_as_incident_creates_linked_incident_and_alert(): void
    {
        $site = Site::factory()->create(['tenant_id' => 1, 'type' => 'house']);
        $client = Client::factory()->create([
            'organization_id' => 1,
            'site_id' => $site->id,
        ]);

        $this->actingAs($this->admin)
            ->post('/control-room/incidents/flag', [
                'client_id' => $client->id,
                'type' => 'fall',
                'severity' => 'high',
                'note' => 'Resident on the floor by the bed',
                'immediate_action_taken' => 'Area isolated and the resident checked.',
            ])
            ->assertRedirect();

        $incident = ClientIncident::where('source', 'control_room')->latest('id')->first();
        $this->assertNotNull($incident);
        $this->assertSame('submitted', $incident->status);
        $this->assertSame($client->id, $incident->client_id);
        $this->assertSame(
            'Area isolated and the resident checked.',
            $incident->immediate_action_taken,
        );
        $this->assertNotNull($incident->control_room_alert_id);

        // Bidirectional link: incident -> alert (FK) and alert -> incident (context).
        $alert = ControlRoomAlert::find($incident->control_room_alert_id);
        $this->assertNotNull($alert);
        $this->assertSame('control_room', $alert->source);
        $this->assertSame('open', $alert->status);
        $this->assertSame($incident->id, $alert->context['incident_id']);
        $event = HsEvent::query()->sole();
        $this->assertSame($event->id, $incident->hs_event_id);
        $this->assertSame($alert->id, $event->control_room_alert_id);
        $this->assertDatabaseCount('control_room_alerts', 1);
    }

    public function test_flag_as_incident_maps_critical_alert_to_high_incident(): void
    {
        $client = Client::factory()->create([
            'organization_id' => 1,
            'site_id' => $this->site->id,
        ]);

        $this->actingAs($this->admin)
            ->post('/control-room/incidents/flag', [
                'client_id' => $client->id,
                'type' => 'injury',
                'severity' => 'critical',
                'immediate_action_taken' => 'Emergency services called and the area secured.',
            ])
            ->assertRedirect();

        $incident = ClientIncident::where('source', 'control_room')->latest('id')->first();
        $alert = ControlRoomAlert::find($incident->control_room_alert_id);

        // Incidents top out at 'high'; the alert keeps the operator's 'critical'.
        $this->assertSame('high', $incident->severity);
        $this->assertSame('critical', $alert->severity);
        $event = HsEvent::query()->sole();
        $this->assertSame('critical', $event->severity);
        $this->assertSame($alert->id, $event->control_room_alert_id);
    }

    public function test_flag_as_incident_rolls_back_when_canonical_journey_attachment_fails(): void
    {
        $client = Client::factory()->create([
            'organization_id' => 1,
            'site_id' => $this->site->id,
        ]);
        $this->partialMock(IncidentJourneyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('attachAlertToIncident')
                ->once()
                ->andThrow(new \RuntimeException('Forced canonical attachment failure'));
        });
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($this->admin)
                ->post('/control-room/incidents/flag', [
                    'client_id' => $client->id,
                    'type' => 'fall',
                    'severity' => 'high',
                    'immediate_action_taken' => 'Resident checked while escalation was attempted.',
                ]);
            $this->fail('Canonical attachment failure must abort incident flagging.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Forced canonical attachment failure', $exception->getMessage());
        }

        $this->assertDatabaseCount('client_incidents', 0);
        $this->assertDatabaseCount('control_room_alerts', 0);
        $this->assertDatabaseCount('hs_events', 0);
    }
}
