<?php

namespace Tests\Feature\Domain\Clinical;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\ClinicalPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientClinicalEventControllerTest extends TestCase
{
    use RefreshDatabase;

    protected Client $client;

    protected Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(ClinicalPermissionsSeeder::class);
        $this->site = Site::factory()->create();
        $this->client = Client::factory()->create(['site_id' => $this->site->id]);
    }

    protected function createUserWithRole(string $roleName): User
    {
        $user = User::factory()->create([
            'role' => $roleName,
            'approved_at' => now(),
        ]);
        $role = Role::where('name', $roleName)->first();
        if ($role) {
            $user->roles()->attach($role);
        }

        return $user;
    }

    public function test_support_worker_can_record_event_for_assigned_client(): void
    {
        $user = $this->createUserWithRole('support_worker');
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
        ]);
        $this->client->supportWorkers()->attach($user);

        $response = $this->actingAs($user)
            ->postJson("/clients/{$this->client->id}/clinical/events", [
                'event_type' => 'other',
                'severity' => 'medium',
                'occurred_at' => now()->toDateTimeString(),
                'description' => 'Client became briefly unsteady while mobilising.',
                'immediate_action_taken' => 'Staff assisted the client to sit down.',
                'outcome' => 'Client settled after a short rest.',
                'requires_followup' => true,
                'followup_notes' => 'Review hydration and monitor through the day.',
            ]);

        $response->assertCreated();

        $this->assertDatabaseHas('clinical_events', [
            'client_id' => $this->client->id,
            'reported_by' => $user->id,
            'event_type' => 'other',
            'severity' => 'medium',
            'requires_followup' => true,
            'followup_notes' => 'Review hydration and monitor through the day.',
            'immediate_action_taken' => 'Staff assisted the client to sit down.',
            'outcome' => 'Client settled after a short rest.',
        ]);
    }

    public function test_client_event_creates_timeline_event(): void
    {
        $user = $this->createUserWithRole('coordinator');

        $this->actingAs($user)
            ->postJson("/clients/{$this->client->id}/clinical/events", [
                'event_type' => 'deterioration',
                'severity' => 'medium',
                'occurred_at' => now()->toDateTimeString(),
                'description' => 'Client appeared more fatigued than usual.',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('timeline_events', [
            'type' => 'clinical_event',
            'client_id' => $this->client->id,
            'actor_user_id' => $user->id,
            'shift_id' => null,
        ]);
    }

    public function test_user_without_event_permission_cannot_record_client_event(): void
    {
        $user = User::factory()->create(['approved_at' => now()]);

        $this->actingAs($user)
            ->postJson("/clients/{$this->client->id}/clinical/events", [
                'event_type' => 'other',
                'severity' => 'medium',
                'occurred_at' => now()->toDateTimeString(),
                'description' => 'Test event',
            ])
            ->assertForbidden();
    }

    public function test_client_event_rejects_invalid_event_type(): void
    {
        $user = $this->createUserWithRole('coordinator');

        $this->actingAs($user)
            ->postJson("/clients/{$this->client->id}/clinical/events", [
                'event_type' => 'not_real',
                'severity' => 'medium',
                'occurred_at' => now()->toDateTimeString(),
                'description' => 'Test event',
            ])
            ->assertUnprocessable();
    }
}
