<?php

namespace Tests\Feature\Domain\Clinical;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\CarePlan;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\ClinicalPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The read-only Care Plans + Restraint lenses (link out to their systems of
 * record; Care Plans are scoped through canonical Client Site access).
 */
class HealthClinicalLensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(ClinicalPermissionsSeeder::class);
    }

    protected function userWithRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        $found = Role::where('name', $role)->first();
        if ($found) {
            $user->roles()->attach($found);
        }

        return $user;
    }

    protected function siteScopedClinicalViewer(Site $site): User
    {
        $viewer = $this->userWithRole('support_worker');
        $role = Role::query()->firstOrCreate(
            ['name' => 'clinical_lens_site_scoped_'.$viewer->id],
            ['label' => 'Clinical Lens Site Scoped', 'level' => 40, 'type' => 'custom'],
        );
        $role->permissions()->sync([
            Permission::query()->where('key', 'clinical.dashboard')->firstOrFail()->id,
        ]);
        $viewer->roles()->syncWithoutDetaching([$role->id]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $viewer->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
        ]);

        return $viewer->fresh(['roles', 'hrEmployeeProfile']);
    }

    public function test_care_plans_lens_renders_only_plans_at_visible_sites(): void
    {
        $visibleSite = Site::factory()->create();
        $outsideSite = Site::factory()->create();
        $lead = $this->siteScopedClinicalViewer($visibleSite);
        $client = Client::factory()->create(['site_id' => $visibleSite->id]);
        CarePlan::create([
            'client_id' => $client->id,
            'title' => 'Skin integrity plan',
            'status' => 'active',
            'plan_type' => 'health_plan',
            'created_by' => $lead->id,
        ]);
        $outsideClient = Client::factory()->create(['site_id' => $outsideSite->id]);
        CarePlan::create([
            'client_id' => $outsideClient->id,
            'title' => 'Outside Site plan',
            'status' => 'active',
            'plan_type' => 'health_plan',
            'created_by' => $lead->id,
        ]);

        $this->actingAs($lead)
            ->get('/health-clinical/care-plans')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('health-clinical/CarePlans')
                ->has('plans', 1)
                ->where('plans.0.title', 'Skin integrity plan')
                ->has('stats')
                ->has('kpis'));
    }

    public function test_care_plans_forbidden_without_dashboard(): void
    {
        $this->actingAs($this->userWithRole('support_worker'))
            ->get('/health-clinical/care-plans')
            ->assertForbidden();
    }

    public function test_behaviour_includes_restraint_lens(): void
    {
        $this->actingAs($this->userWithRole('clinical_lead'))
            ->get('/health-clinical/behaviour')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('health-clinical/Behaviour')
                ->has('restraint.stats')
                ->has('restraint.events'));
    }
}
