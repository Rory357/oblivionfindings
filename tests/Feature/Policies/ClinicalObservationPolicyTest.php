<?php

namespace Tests\Feature\Policies;

use App\Domain\Clinical\Models\ClinicalObservation;
use App\Domain\Clinical\Policies\ClinicalObservationPolicy;
use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ClinicalPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClinicalObservationPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected ClinicalObservationPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(ClinicalPermissionsSeeder::class);
        $this->policy = new ClinicalObservationPolicy();
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

    public function test_support_worker_can_view_observations(): void
    {
        $user = $this->createUserWithRole('support_worker');
        $this->assertTrue($this->policy->viewAny($user));
    }

    public function test_team_lead_can_view_observations(): void
    {
        $user = $this->createUserWithRole('team_lead');
        $this->assertTrue($this->policy->viewAny($user));
    }

    public function test_clinical_lead_can_view_observations(): void
    {
        $user = $this->createUserWithRole('clinical_lead');
        $this->assertTrue($this->policy->viewAny($user));
    }

    // ── create (basic recording) ─────────────────────────────────────────

    public function test_support_worker_can_record_basic_observations(): void
    {
        $user = $this->createUserWithRole('support_worker');
        $this->assertTrue($this->policy->create($user));
    }

    public function test_team_lead_can_record_observations(): void
    {
        $user = $this->createUserWithRole('team_lead');
        $this->assertTrue($this->policy->create($user));
    }

    // ── recordClinical (vitals, pain) ────────────────────────────────────

    public function test_support_worker_cannot_record_clinical_observations(): void
    {
        $user = $this->createUserWithRole('support_worker');
        $this->assertFalse($this->policy->recordClinical($user));
    }

    public function test_team_lead_can_record_clinical_observations(): void
    {
        $user = $this->createUserWithRole('team_lead');
        $this->assertTrue($this->policy->recordClinical($user));
    }

    public function test_clinical_lead_can_record_clinical_observations(): void
    {
        $user = $this->createUserWithRole('clinical_lead');
        $this->assertTrue($this->policy->recordClinical($user));
    }

    public function test_coordinator_can_record_clinical_observations(): void
    {
        $user = $this->createUserWithRole('coordinator');
        $this->assertTrue($this->policy->recordClinical($user));
    }

    // ── correct ──────────────────────────────────────────────────────────

    public function test_support_worker_cannot_correct_observations(): void
    {
        $user = $this->createUserWithRole('support_worker');
        $this->assertFalse($this->policy->correct($user));
    }

    public function test_clinical_lead_can_correct_observations(): void
    {
        $user = $this->createUserWithRole('clinical_lead');
        $this->assertTrue($this->policy->correct($user));
    }

    public function test_provider_manager_can_correct_observations(): void
    {
        $user = $this->createUserWithRole('provider_manager');
        $this->assertTrue($this->policy->correct($user));
    }
}
