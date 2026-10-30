<?php

namespace Tests\Feature\HealthSafety;

use App\Http\Controllers\HealthSafety\HealthSafetyDashboardController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * G3/G4 — the command-centre index payload + period/site/lens params.
 */
class HealthSafetyDashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    protected Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['tenant_id' => 1]);
    }

    protected function hsOfficer(): User
    {
        $user = User::factory()->create([
            'role' => 'health_safety_officer',
            'organization_id' => 1,
            'approved_at' => now(),
        ]);
        if ($role = Role::where('name', 'health_safety_officer')->first()) {
            $user->roles()->attach($role);
        }

        return $user;
    }

    public function test_dashboard_renders_command_centre_payload(): void
    {
        $officer = $this->hsOfficer();
        $this->actingAs($officer)
            ->get('/health-safety')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('health-safety/dashboard')
                ->has('leading_lagging.lagging')
                ->has('leading_lagging.leading')
                ->has('frequency_trends')
                ->has('worklists.overdue_corrective_actions')
                ->has('worklists.open_investigations')
                ->has('worklists.notifiable_events')
                ->has('worklists.expiring')
                ->has('sites')
                // The start follows the role (approved drawing D1).
                ->where('filters.lens', HealthSafetyDashboardController::startFor($officer))
            );
    }

    public function test_dashboard_echoes_lens_and_site_params(): void
    {
        $site = Site::factory()->create(['tenant_id' => 1]);

        $this->actingAs($this->hsOfficer())
            ->get('/health-safety?lens=governance&site='.$site->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.lens', 'governance')
                ->where('filters.site', $site->id)
                ->where('lens', 'governance')
            );
    }

    public function test_invalid_lens_falls_back_to_the_role_start(): void
    {
        $officer = $this->hsOfficer();
        $this->actingAs($officer)
            ->get('/health-safety?lens=bogus')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('filters.lens', HealthSafetyDashboardController::startFor($officer)));
    }

    public function test_the_start_follows_the_role(): void
    {
        $start = fn (array $keys) => HealthSafetyDashboardController::startFor(tap(User::factory()->create(['approved_at' => now()]), function (User $u) use ($keys): void {
            // governance.view is seeded by the governance seeder, not RbacSeeder.
            $ids = collect($keys)->map(fn (string $key) => Permission::firstOrCreate(['key' => $key], ['description' => $key])->id);
            $u->permissionOverrides()->sync($ids->mapWithKeys(fn ($id) => [$id => ['allowed' => true]]));
        })->fresh());
        $this->assertSame('governance', $start(['hazards.view', 'healthSafety.viewAllSites']));
        $this->assertSame('governance', $start(['hazards.view', 'governance.view']));
        $this->assertSame('manager', $start(['hazards.view', 'hazards.manage']));
        $this->assertSame('frontline', $start(['hazards.view']));
    }

    public function test_dashboard_requires_hazards_view_permission(): void
    {
        $user = User::factory()->create([
            'role' => 'support_worker',
            'organization_id' => 1,
            'approved_at' => now(),
        ]);
        if ($role = Role::where('name', 'support_worker')->first()) {
            $user->roles()->attach($role);
        }

        $this->actingAs($user)->get('/health-safety')->assertForbidden();
    }
}
