<?php

namespace Tests\Feature\Domain\Clinical;

use App\Domain\Clinical\Enums\ObservationType;
use App\Domain\Clinical\Models\ClinicalObservation;
use App\Models\Client;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\ClinicalPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ObservationRegisterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(ClinicalPermissionsSeeder::class);
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

    public function test_clinical_lead_can_access_observation_register(): void
    {
        $user = $this->createUserWithRole('clinical_lead');

        $this->actingAs($user)
            ->get('/health-clinical/observations')
            ->assertOk();
    }

    public function test_coordinator_can_access_observation_register(): void
    {
        $user = $this->createUserWithRole('coordinator');

        $this->actingAs($user)
            ->get('/health-clinical/observations')
            ->assertOk();
    }

    public function test_support_worker_cannot_access_observation_register(): void
    {
        $user = $this->createUserWithRole('support_worker');

        $this->actingAs($user)
            ->get('/health-clinical/observations')
            ->assertForbidden();
    }

    public function test_unauthenticated_user_is_redirected(): void
    {
        $this->get('/health-clinical/observations')
            ->assertRedirect('/login');
    }

    // ── Page rendering ──────────────────────────────────────────────────

    public function test_register_renders_with_correct_props(): void
    {
        $user = $this->createUserWithRole('clinical_lead');

        $this->actingAs($user)
            ->get('/health-clinical/observations')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('health-clinical/observations')
                ->has('observations')
                ->has('stats')
                ->has('filters')
                ->has('filter_options')
                ->has('filter_options.clients')
                ->has('filter_options.sites')
                ->has('filter_options.staff')
                ->has('filter_options.observation_types')
            );
    }

    public function test_register_shows_observations(): void
    {
        $user = $this->createUserWithRole('clinical_lead');
        $client = Client::factory()->create();

        ClinicalObservation::factory()->count(3)->create([
            'client_id' => $client->id,
            'recorded_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->get('/health-clinical/observations')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('observations.data', 3)
            );
    }

    // ── Filtering ───────────────────────────────────────────────────────

    public function test_filter_by_client(): void
    {
        $user = $this->createUserWithRole('clinical_lead');
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();

        ClinicalObservation::factory()->create([
            'client_id' => $clientA->id,
            'recorded_by' => $user->id,
        ]);
        ClinicalObservation::factory()->create([
            'client_id' => $clientB->id,
            'recorded_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->get('/health-clinical/observations?client_id=' . $clientA->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('observations.data', 1)
            );
    }

    public function test_filter_by_observation_type(): void
    {
        $user = $this->createUserWithRole('clinical_lead');
        $client = Client::factory()->create();

        ClinicalObservation::factory()->create([
            'client_id' => $client->id,
            'recorded_by' => $user->id,
            'observation_type' => ObservationType::Vitals,
        ]);
        ClinicalObservation::factory()->create([
            'client_id' => $client->id,
            'recorded_by' => $user->id,
            'observation_type' => ObservationType::Weight,
        ]);

        $this->actingAs($user)
            ->get('/health-clinical/observations?observation_type=vitals')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('observations.data', 1)
            );
    }

    public function test_filter_by_date_range(): void
    {
        $user = $this->createUserWithRole('clinical_lead');
        $client = Client::factory()->create();

        ClinicalObservation::factory()->create([
            'client_id' => $client->id,
            'recorded_by' => $user->id,
            'recorded_at' => now()->subDays(10),
        ]);
        ClinicalObservation::factory()->create([
            'client_id' => $client->id,
            'recorded_by' => $user->id,
            'recorded_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/health-clinical/observations?date_from=' . now()->subDays(2)->toDateString() . '&date_to=' . now()->toDateString())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('observations.data', 1)
            );
    }

    public function test_filter_by_site(): void
    {
        $user = $this->createUserWithRole('clinical_lead');
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $siteA->id]);

        ClinicalObservation::factory()->create([
            'client_id' => $client->id,
            'recorded_by' => $user->id,
            'site_id' => $siteA->id,
        ]);
        ClinicalObservation::factory()->create([
            'client_id' => $client->id,
            'recorded_by' => $user->id,
            'site_id' => $siteB->id,
        ]);

        $this->actingAs($user)
            ->get('/health-clinical/observations?site_id=' . $siteA->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('observations.data', 1)
            );
    }

    public function test_filter_by_recorder(): void
    {
        $lead = $this->createUserWithRole('clinical_lead');
        $workerA = $this->createUserWithRole('support_worker');
        $workerB = $this->createUserWithRole('support_worker');
        $client = Client::factory()->create();

        ClinicalObservation::factory()->create([
            'client_id' => $client->id,
            'recorded_by' => $workerA->id,
        ]);
        ClinicalObservation::factory()->create([
            'client_id' => $client->id,
            'recorded_by' => $workerB->id,
        ]);

        $this->actingAs($lead)
            ->get('/health-clinical/observations?recorded_by=' . $workerA->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('observations.data', 1)
            );
    }

    public function test_invalid_observation_type_filter_rejected(): void
    {
        $user = $this->createUserWithRole('clinical_lead');

        $this->actingAs($user)
            ->get('/health-clinical/observations?observation_type=not_a_real_type')
            ->assertSessionHasErrors('observation_type');
    }

    // ── Pagination ──────────────────────────────────────────────────────

    public function test_register_paginates_at_25(): void
    {
        $user = $this->createUserWithRole('clinical_lead');
        $client = Client::factory()->create();

        ClinicalObservation::factory()->count(30)->create([
            'client_id' => $client->id,
            'recorded_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->get('/health-clinical/observations')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('observations.data', 25)
                ->where('observations.total', 30)
                ->where('observations.last_page', 2)
            );
    }

    // ── Stats ───────────────────────────────────────────────────────────

    public function test_stats_include_counts(): void
    {
        $user = $this->createUserWithRole('clinical_lead');
        $client = Client::factory()->create();

        ClinicalObservation::factory()->count(3)->create([
            'client_id' => $client->id,
            'recorded_by' => $user->id,
            'recorded_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/health-clinical/observations')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.total_7d', 3)
                ->where('stats.total_30d', 3)
                ->has('stats.by_type')
            );
    }
}
