<?php

namespace Tests\Feature\ControlRoom;

use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ControlRoomAlert;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ControlRoomIncidentControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $supportWorker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RbacSeeder::class);

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

    public function test_create_alert_from_client_incident_creates_alert(): void
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

        $this->actingAs($this->admin)
            ->post('/control-room/incidents/create-alert', [
                'source_type' => 'client_incident',
                'source_id' => $incident->id,
                'severity' => 'high',
                'notes' => 'Escalating to control room',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('control_room_alerts', [
            'alert_type' => 'client_incident',
            'severity' => 'high',
            'site_id' => $site->id,
            'client_id' => $client->id,
        ]);

        $alert = ControlRoomAlert::where('alert_type', 'client_incident')->first();
        $this->assertSame($incident->id, $alert->context['incident_source_id']);

        // The new alert id is flashed so the UI can open its workspace in one step.
        $this->assertEquals($alert->id, session('created_alert_id'));
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
    }
}
