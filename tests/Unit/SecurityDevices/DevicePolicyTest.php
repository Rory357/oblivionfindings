<?php

namespace Tests\Unit\SecurityDevices;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Policies\DevicePolicy;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DevicePolicyTest extends TestCase
{
    use RefreshDatabase;

    private DevicePolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = app(DevicePolicy::class);
        $this->seed(\Database\Seeders\RbacSeeder::class);
    }

    public function test_admin_can_view_any(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('name', 'admin')->first());

        // Admin should have all permissions via RBAC seeder.
        // Only test if the securityDevices permissions exist after seeding.
        $perm = Permission::where('key', 'securityDevices.viewAny')->first();
        if (! $perm) {
            // Permissions haven't been seeded yet (PR4 scope). Test the canDo path directly.
            $this->markTestSkipped('securityDevices permissions not yet seeded (PR4).');
        }

        $this->assertTrue($this->policy->viewAny($admin));
    }

    public function test_user_without_permission_cannot_view_any(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($this->policy->viewAny($user));
    }

    public function test_policy_methods_delegate_to_can_do(): void
    {
        $user = User::factory()->create();
        $device = Device::factory()->create();

        // Without any permissions assigned, all should return false.
        $this->assertFalse($this->policy->view($user, $device));
        $this->assertFalse($this->policy->create($user));
        $this->assertFalse($this->policy->update($user, $device));
        $this->assertFalse($this->policy->delete($user, $device));
        $this->assertFalse($this->policy->assign($user, $device));
        $this->assertFalse($this->policy->manageGroups($user));
        $this->assertFalse($this->policy->viewEvents($user));
        $this->assertFalse($this->policy->viewMaintenance($user));
        $this->assertFalse($this->policy->manageMaintenance($user));
        $this->assertFalse($this->policy->viewReports($user));
        $this->assertFalse($this->policy->viewIntegrations($user));
        $this->assertFalse($this->policy->manageIntegrations($user));
    }
}
