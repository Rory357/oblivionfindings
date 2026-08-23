<?php

namespace Tests\Feature\Domain\Clinical;

use App\Domain\Clinical\Models\ClinicalEvent;
use App\Domain\Clinical\Models\ClinicalObservation;
use App\Domain\Clinical\Models\ClinicalProtocol;
use App\Domain\Clinical\Models\ClinicalProtocolSchedule;
use App\Models\Client;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\ClinicalPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthClinicalDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(ClinicalPermissionsSeeder::class);
        $this->site = Site::factory()->create(['is_active' => true]);
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

    // ── Access control ───────────────────────────────────────────────────

    public function test_clinical_lead_can_access_dashboard(): void
    {
        $user = $this->createUserWithRole('clinical_lead');

        $response = $this->actingAs($user)->get('/health-clinical');

        $response->assertOk();
    }

    public function test_coordinator_can_access_dashboard(): void
    {
        $user = $this->createUserWithRole('coordinator');

        $response = $this->actingAs($user)->get('/health-clinical');

        $response->assertOk();
    }

    public function test_provider_manager_can_access_dashboard(): void
    {
        $user = $this->createUserWithRole('provider_manager');

        $response = $this->actingAs($user)->get('/health-clinical');

        $response->assertOk();
    }

    public function test_support_worker_cannot_access_dashboard(): void
    {
        $user = $this->createUserWithRole('support_worker');

        $response = $this->actingAs($user)->get('/health-clinical');

        $response->assertForbidden();
    }

    public function test_unauthenticated_user_is_redirected(): void
    {
        $response = $this->get('/health-clinical');

        $response->assertRedirect();
    }

    // ── Dashboard data ───────────────────────────────────────────────────

    public function test_dashboard_returns_kpis(): void
    {
        $user = $this->createUserWithRole('clinical_lead');
        $client = Client::factory()->create(['site_id' => $this->site->id]);

        ClinicalObservation::factory()->vitals()->create([
            'client_id' => $client->id,
            'recorded_at' => now(),
        ]);
        ClinicalProtocol::factory()->create([
            'client_id' => $client->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->get('/health-clinical');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('health-clinical/index')
            ->has('kpis')
            ->where('kpis.protocols_active', 1)
            ->where('kpis.observations_today', 1)
        );
    }

    public function test_dashboard_returns_overdue_items(): void
    {
        $user = $this->createUserWithRole('clinical_lead');
        $client = Client::factory()->create(['site_id' => $this->site->id]);

        $protocol = ClinicalProtocol::factory()->create([
            'client_id' => $client->id,
        ]);

        ClinicalProtocolSchedule::factory()->overdue()->create([
            'clinical_protocol_id' => $protocol->id,
        ]);

        $response = $this->actingAs($user)->get('/health-clinical');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('overdue_items', 1)
            ->where('overdue_items.0.client_id', $client->id)
        );
    }

    public function test_dashboard_returns_recent_events(): void
    {
        $user = $this->createUserWithRole('clinical_lead');
        $client = Client::factory()->create(['site_id' => $this->site->id]);

        ClinicalEvent::factory()->fall()->create([
            'client_id' => $client->id,
            'occurred_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($user)->get('/health-clinical');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('recent_events', 1)
            ->where('recent_events.0.event_type', 'fall')
        );
    }

    public function test_dashboard_returns_recent_observations(): void
    {
        $user = $this->createUserWithRole('clinical_lead');
        $client = Client::factory()->create(['site_id' => $this->site->id]);

        ClinicalObservation::factory()->weight()->create([
            'client_id' => $client->id,
        ]);

        $response = $this->actingAs($user)->get('/health-clinical');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('recent_observations', 1)
            ->where('recent_observations.0.observation_type', 'weight')
        );
    }

    public function test_dashboard_shows_empty_state_with_no_data(): void
    {
        $user = $this->createUserWithRole('clinical_lead');

        $response = $this->actingAs($user)->get('/health-clinical');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('health-clinical/index')
            ->where('kpis.protocols_active', 0)
            ->where('kpis.observations_today', 0)
            ->where('kpis.compliance_rate_30d', 0)
            ->has('overdue_items', 0)
            ->has('recent_events', 0)
            ->has('recent_observations', 0)
        );
    }
}
