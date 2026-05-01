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
    }
}
