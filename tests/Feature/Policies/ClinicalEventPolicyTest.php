<?php

namespace Tests\Feature\Policies;

use App\Domain\Clinical\Policies\ClinicalEventPolicy;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ClinicalPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClinicalEventPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected ClinicalEventPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(ClinicalPermissionsSeeder::class);
        $this->policy = app(ClinicalEventPolicy::class);
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

    // ── viewAny ──────────────────────────────────────────────────────────

    public function test_support_worker_can_view_events(): void
    {
        $user = $this->createUserWithRole('support_worker');
        $this->assertTrue($this->policy->viewAny($user));
    }

    public function test_clinical_lead_can_view_events(): void
    {
        $user = $this->createUserWithRole('clinical_lead');
        $this->assertTrue($this->policy->viewAny($user));
    }

    // ── create ───────────────────────────────────────────────────────────

    public function test_support_worker_can_record_events(): void
    {
        $user = $this->createUserWithRole('support_worker');
        $this->assertTrue($this->policy->create($user));
    }

    public function test_team_lead_can_record_events(): void
    {
        $user = $this->createUserWithRole('team_lead');
        $this->assertTrue($this->policy->create($user));
    }

    // ── review ───────────────────────────────────────────────────────────

    public function test_support_worker_cannot_review_events(): void
    {
        $user = $this->createUserWithRole('support_worker');
        $this->assertFalse($this->policy->review($user));
    }

    public function test_team_lead_cannot_review_events(): void
    {
        $user = $this->createUserWithRole('team_lead');
        $this->assertFalse($this->policy->review($user));
    }

    public function test_clinical_lead_can_review_events(): void
    {
        $user = $this->createUserWithRole('clinical_lead');
        $this->assertTrue($this->policy->review($user));
    }

    public function test_coordinator_can_review_events(): void
    {
        $user = $this->createUserWithRole('coordinator');
        $this->assertTrue($this->policy->review($user));
    }

    public function test_provider_manager_can_review_events(): void
    {
        $user = $this->createUserWithRole('provider_manager');
        $this->assertTrue($this->policy->review($user));
    }
}
