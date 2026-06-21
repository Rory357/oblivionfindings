<?php

namespace Tests\Feature\Domain\Clinical;

use App\Models\CarePlan;
use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ClinicalPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The read-only Care Plans + Restraint lenses (link out to their systems of
 * record; org-scoped so no cross-tenant leak).
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

    public function test_care_plans_lens_renders_org_scoped_plans(): void
    {
        $lead = $this->userWithRole('clinical_lead');
        $client = Client::factory()->create(['organization_id' => $lead->organization_id]);
        CarePlan::create([
            'organization_id' => $lead->organization_id,
            'client_id' => $client->id,
            'title' => 'Skin integrity plan',
            'status' => 'active',
            'plan_type' => 'health_plan',
            'created_by' => $lead->id,
        ]);
        // A plan in another org must NOT appear.
        CarePlan::create([
            'organization_id' => $lead->organization_id + 999,
            'client_id' => $client->id,
            'title' => 'Other-org plan',
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
