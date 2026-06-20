<?php

namespace Tests\Feature\Domain\Clinical;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\ClinicalPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HealthClinicalMonitoringTest extends TestCase
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

    public function test_monitoring_rollup_renders(): void
    {
        // Exercises all four per-client rollup queries (fluid/bowel/seizure/sleep)
        // — a column/scope error would surface here.
        $this->actingAs($this->userWithRole('clinical_lead'))
            ->get('/health-clinical/health-monitoring')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('health-clinical/HealthMonitoring')
                ->has('rollup.stats')
                ->has('rollup.recent_seizures')
                ->has('rollup.recent_sleep')
                ->has('clients')
                ->has('kpis'));
    }

    public function test_monitoring_forbidden_without_permission(): void
    {
        $this->actingAs($this->userWithRole('support_worker'))
            ->get('/health-clinical/health-monitoring')
            ->assertForbidden();
    }
}
