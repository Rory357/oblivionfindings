<?php

namespace Tests\Feature\Domain\Clinical;

use App\Domain\Clinical\Models\ClinicalObservation;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\ClinicalPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientClinicalControllerTest extends TestCase
{
    use RefreshDatabase;

    protected Client $client;

    protected Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(ClinicalPermissionsSeeder::class);
        $this->site = Site::factory()->create(['is_active' => true]);
        $this->client = Client::factory()->create([
            'site_id' => $this->site->id,
            'status' => 'active',
        ]);
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
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => today()->subDay(),
            'end_date' => null,
        ]);

        return $user;
    }

    // ── GET observations ─────────────────────────────────────────────────

    public function test_can_list_observations_for_client(): void
    {
        $user = $this->createUserWithRole('coordinator');

        ClinicalObservation::factory()->vitals()->create(['client_id' => $this->client->id]);
        ClinicalObservation::factory()->weight()->create(['client_id' => $this->client->id]);

        $response = $this->actingAs($user)
            ->getJson("/clients/{$this->client->id}/clinical/observations");

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
    }

    public function test_can_filter_observations_by_type(): void
    {
        $user = $this->createUserWithRole('coordinator');

        ClinicalObservation::factory()->vitals()->create(['client_id' => $this->client->id]);
        ClinicalObservation::factory()->weight()->create(['client_id' => $this->client->id]);
        ClinicalObservation::factory()->vitals()->create(['client_id' => $this->client->id]);

        $response = $this->actingAs($user)
            ->getJson("/clients/{$this->client->id}/clinical/observations?type=vitals");

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
    }

    public function test_observations_exclude_other_clients(): void
    {
        $user = $this->createUserWithRole('coordinator');
        $otherClient = Client::factory()->create([
            'site_id' => $this->site->id,
            'status' => 'active',
        ]);

        ClinicalObservation::factory()->create(['client_id' => $this->client->id]);
        ClinicalObservation::factory()->create(['client_id' => $otherClient->id]);

        $response = $this->actingAs($user)
            ->getJson("/clients/{$this->client->id}/clinical/observations");

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }

    // ── POST store observation ────────────────────────────────────────────

    public function test_support_worker_can_record_basic_observation(): void
    {
        $user = $this->createUserWithRole('support_worker');
        $this->client->supportWorkers()->attach($user); // assign to client

        $response = $this->actingAs($user)
            ->postJson("/clients/{$this->client->id}/clinical/observations", [
                'observation_type' => 'weight',
                'data' => ['weight_kg' => 72.5],
                'notes' => 'Before breakfast',
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('clinical_observations', [
            'client_id' => $this->client->id,
            'observation_type' => 'weight',
            'recorded_by' => $user->id,
        ]);
    }

    public function test_support_worker_cannot_record_clinical_observation(): void
    {
        $user = $this->createUserWithRole('support_worker');
        $this->client->supportWorkers()->attach($user);

        $response = $this->actingAs($user)
            ->postJson("/clients/{$this->client->id}/clinical/observations", [
                'observation_type' => 'vitals',
                'data' => ['systolic' => 120, 'diastolic' => 80, 'pulse' => 72],
            ]);

        $response->assertForbidden();
    }

    public function test_clinical_lead_can_record_clinical_observation(): void
    {
        $user = $this->createUserWithRole('provider_manager'); // has clients.viewAny + clinical perms

        $response = $this->actingAs($user)
            ->postJson("/clients/{$this->client->id}/clinical/observations", [
                'observation_type' => 'vitals',
                'data' => ['systolic' => 120, 'diastolic' => 80, 'pulse' => 72],
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('clinical_observations', [
            'client_id' => $this->client->id,
            'observation_type' => 'vitals',
        ]);
    }

    public function test_rejects_invalid_observation_type(): void
    {
        $user = $this->createUserWithRole('coordinator');

        $response = $this->actingAs($user)
            ->postJson("/clients/{$this->client->id}/clinical/observations", [
                'observation_type' => 'invalid_type',
                'data' => ['foo' => 'bar'],
            ]);

        $response->assertUnprocessable();
    }

    public function test_rejects_missing_required_data_fields(): void
    {
        $user = $this->createUserWithRole('provider_manager');

        $response = $this->actingAs($user)
            ->postJson("/clients/{$this->client->id}/clinical/observations", [
                'observation_type' => 'vitals',
                'data' => ['systolic' => 120], // missing diastolic and pulse
            ]);

        $response->assertUnprocessable();
    }

    public function test_creates_timeline_event_on_observation(): void
    {
        $user = $this->createUserWithRole('coordinator');

        $this->actingAs($user)
            ->postJson("/clients/{$this->client->id}/clinical/observations", [
                'observation_type' => 'weight',
                'data' => ['weight_kg' => 70],
            ]);

        $this->assertDatabaseHas('timeline_events', [
            'type' => 'clinical_observation',
            'client_id' => $this->client->id,
            'actor_user_id' => $user->id,
        ]);
    }

    public function test_general_observation_accepts_empty_data(): void
    {
        $user = $this->createUserWithRole('support_worker');
        $this->client->supportWorkers()->attach($user);

        $response = $this->actingAs($user)
            ->postJson("/clients/{$this->client->id}/clinical/observations", [
                'observation_type' => 'general',
                'data' => [],
                'notes' => 'Client appeared well',
            ]);

        $response->assertCreated();
    }

    public function test_coordinator_can_record_bowel_observation(): void
    {
        $user = $this->createUserWithRole('coordinator');

        $response = $this->actingAs($user)
            ->postJson("/clients/{$this->client->id}/clinical/observations", [
                'observation_type' => 'bowel',
                'data' => ['bristol_type' => 4],
            ]);

        $response->assertCreated();
    }

    public function test_coordinator_can_record_sleep_observation(): void
    {
        $user = $this->createUserWithRole('coordinator');

        $response = $this->actingAs($user)
            ->postJson("/clients/{$this->client->id}/clinical/observations", [
                'observation_type' => 'sleep',
                'data' => ['bed_time' => '22:00', 'wake_time' => '07:00', 'quality' => 'good'],
            ]);

        $response->assertCreated();
    }

    public function test_coordinator_can_record_fluid_intake_observation(): void
    {
        $user = $this->createUserWithRole('coordinator');

        $response = $this->actingAs($user)
            ->postJson("/clients/{$this->client->id}/clinical/observations", [
                'observation_type' => 'fluid_intake',
                'data' => ['amount_ml' => 250, 'fluid_type' => 'water'],
            ]);

        $response->assertCreated();
    }
}
