<?php

namespace Tests\Feature\Policies;

use App\Domain\Clinical\Models\ClinicalProtocol;
use App\Domain\Clinical\Policies\ClinicalProtocolPolicy;
use App\Models\Client;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\ClinicalPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClinicalProtocolPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected ClinicalProtocolPolicy $policy;

    protected Client $siteClient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(ClinicalPermissionsSeeder::class);
        $this->policy = app(ClinicalProtocolPolicy::class);
        $site = Site::factory()->create(['is_active' => true]);
        $this->siteClient = Client::factory()->create(['site_id' => $site->id]);
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

    public function test_support_worker_cannot_view_protocols(): void
    {
        $user = $this->createUserWithRole('support_worker');
        $this->assertFalse($this->policy->viewAny($user));
    }

    public function test_team_lead_can_view_protocols(): void
    {
        $user = $this->createUserWithRole('team_lead');
        $this->assertTrue($this->policy->viewAny($user));
    }

    public function test_clinical_lead_can_view_protocols(): void
    {
        $user = $this->createUserWithRole('clinical_lead');
        $this->assertTrue($this->policy->viewAny($user));
    }

    // ── create ───────────────────────────────────────────────────────────

    public function test_support_worker_cannot_create_protocols(): void
    {
        $user = $this->createUserWithRole('support_worker');
        $this->assertFalse($this->policy->create($user));
    }

    public function test_team_lead_cannot_create_protocols(): void
    {
        $user = $this->createUserWithRole('team_lead');
        $this->assertFalse($this->policy->create($user));
    }

    public function test_clinical_lead_can_create_protocols(): void
    {
        $user = $this->createUserWithRole('clinical_lead');
        $this->assertTrue($this->policy->create($user));
    }

    public function test_coordinator_can_create_protocols(): void
    {
        $user = $this->createUserWithRole('coordinator');
        $this->assertTrue($this->policy->create($user));
    }

    public function test_provider_manager_can_create_protocols(): void
    {
        $user = $this->createUserWithRole('provider_manager');
        $this->assertTrue($this->policy->create($user));
    }

    // ── update ───────────────────────────────────────────────────────────

    public function test_support_worker_cannot_update_protocols(): void
    {
        $user = $this->createUserWithRole('support_worker');
        $protocol = ClinicalProtocol::factory()->create(['client_id' => $this->siteClient->id]);
        $this->assertFalse($this->policy->update($user, $protocol));
    }

    public function test_clinical_lead_can_update_protocols(): void
    {
        $user = $this->createUserWithRole('clinical_lead');
        $protocol = ClinicalProtocol::factory()->create(['client_id' => $this->siteClient->id]);
        $this->assertTrue($this->policy->update($user, $protocol));
    }

    // ── delete ───────────────────────────────────────────────────────────

    public function test_support_worker_cannot_delete_protocols(): void
    {
        $user = $this->createUserWithRole('support_worker');
        $protocol = ClinicalProtocol::factory()->create(['client_id' => $this->siteClient->id]);
        $this->assertFalse($this->policy->delete($user, $protocol));
    }

    public function test_clinical_lead_can_delete_protocols(): void
    {
        $user = $this->createUserWithRole('clinical_lead');
        $protocol = ClinicalProtocol::factory()->create(['client_id' => $this->siteClient->id]);
        $this->assertTrue($this->policy->delete($user, $protocol));
    }
}
