<?php

namespace Tests\Feature\ControlRoom;

use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ControlRoomAlert;
use App\Models\HsEvent;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Incidents\IncidentJourneyService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class ControlRoomIncidentControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $supportWorker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->supportWorker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $this->supportWorker->roles()->attach(Role::where('name', 'support_worker')->first());
    }

    public function test_index_requires_view_permission(): void
    {
        $stranger = User::factory()->create(['approved_at' => now()]);

        $this->actingAs($stranger)
            ->get('/control-room/incidents')
            ->assertForbidden();
    }

    public function test_index_renders_unified_feed(): void
    {
        $this->actingAs($this->admin)
            ->get('/control-room/incidents')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('control-room/incidents')
                ->has('incidents.data')
                ->has('stats')
                ->has('filters')
                ->has('sites')
                ->has('clients')
                ->has('can')
            );
    }

    public function test_feed_resolves_the_client_full_name(): void
    {
        // Regression: the feed read Client->name (which doesn't exist — the
        // model appends full_name), so every client incident showed "Unknown".
        $site = Site::factory()->create(['type' => 'house']);
        $client = Client::factory()->create([
            'site_id' => $site->id,
            'first_name' => 'Aroha',
            'last_name' => 'Kingi',
        ]);

        ClientIncident::create([
            'client_id' => $client->id,
            'reported_by' => $this->admin->id,
            'title' => 'Feed name check',
            'type' => 'behaviour',
            'severity' => 'low',
            'status' => 'submitted',
            'occurred_at' => now()->subHour(),
            'description' => 'Feed name check',
        ]);

        $this->actingAs($this->admin)
            ->get('/control-room/incidents')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('control-room/incidents')
                ->where('incidents.data.0.client_name', 'Aroha Kingi')
            );
    }

    public function test_create_alert_from_incident_requires_create_permission(): void
    {
        $site = Site::factory()->create(['type' => 'house']);
        $client = Client::factory()->create(['site_id' => $site->id]);

        $incident = ClientIncident::create([
            'client_id' => $client->id,
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
        $site = Site::factory()->create(['type' => 'house']);
        $client = Client::factory()->create(['site_id' => $site->id]);

        $incident = ClientIncident::create([
            'client_id' => $client->id,
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
        $site = Site::factory()->create(['type' => 'house']);
        $client = Client::factory()->create(['site_id' => $site->id]);
        $incident = ClientIncident::create([
            'client_id' => $client->id,
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
        $site = Site::factory()->create(['type' => 'house']);
        $client = Client::factory()->create(['site_id' => $site->id]);
        $incident = ClientIncident::create([
            'client_id' => $client->id,
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
        $client = Client::factory()->create();

        $this->actingAs($this->supportWorker)
            ->post('/control-room/incidents/flag', [
                'client_id' => $client->id,
                'type' => 'fall',
                'severity' => 'high',
            ])
            ->assertForbidden();
    }

    public function test_flag_as_incident_creates_linked_incident_and_alert(): void
    {
        $site = Site::factory()->create(['type' => 'house']);
        $client = Client::factory()->create(['site_id' => $site->id]);

        $this->actingAs($this->admin)
            ->post('/control-room/incidents/flag', [
                'client_id' => $client->id,
                'type' => 'fall',
                'severity' => 'high',
                'note' => 'Resident on the floor by the bed',
            ])
            ->assertRedirect();

        $incident = ClientIncident::where('source', 'control_room')->latest('id')->first();
        $this->assertNotNull($incident);
        $this->assertSame('submitted', $incident->status);
        $this->assertSame($client->id, $incident->client_id);
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
        $client = Client::factory()->create();

        $this->actingAs($this->admin)
            ->post('/control-room/incidents/flag', [
                'client_id' => $client->id,
                'type' => 'injury',
                'severity' => 'critical',
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
        $client = Client::factory()->create();
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
