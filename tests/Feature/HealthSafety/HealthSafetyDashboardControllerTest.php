<?php

namespace Tests\Feature\HealthSafety;

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

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    protected function hsOfficer(): User
    {
        $user = User::factory()->create([
            'role' => 'health_safety_officer',
            'approved_at' => now(),
        ]);
        if ($role = Role::where('name', 'health_safety_officer')->first()) {
            $user->roles()->attach($role);
        }

        return $user;
    }

    public function test_dashboard_renders_command_centre_payload(): void
    {
        $this->actingAs($this->hsOfficer())
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
                ->where('filters.lens', 'manager')
            );
    }

    public function test_dashboard_echoes_lens_and_site_params(): void
    {
        $site = Site::factory()->create();

        $this->actingAs($this->hsOfficer())
            ->get('/health-safety?lens=governance&site='.$site->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.lens', 'governance')
                ->where('filters.site', $site->id)
                ->where('lens', 'governance')
            );
    }

    public function test_invalid_lens_falls_back_to_manager(): void
    {
        $this->actingAs($this->hsOfficer())
            ->get('/health-safety?lens=bogus')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('filters.lens', 'manager'));
    }

    public function test_dashboard_requires_hazards_view_permission(): void
    {
        $user = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        if ($role = Role::where('name', 'support_worker')->first()) {
            $user->roles()->attach($role);
        }

        $this->actingAs($user)->get('/health-safety')->assertForbidden();
    }
}
