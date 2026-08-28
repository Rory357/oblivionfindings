<?php

namespace Tests\Feature\System;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AccessControlControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $supportWorker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->admin = $this->userWithRole('admin');
        $this->supportWorker = $this->userWithRole('support_worker');
    }

    public function test_access_dashboard_renders_for_admin(): void
    {
        $this->actingAs($this->admin)
            ->get('/system/access')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('system/access/Dashboard')
                ->has('stats')
                ->has('roles')
            );
    }

    public function test_access_dashboard_denies_support_worker(): void
    {
        $this->actingAs($this->supportWorker)
            ->get('/system/access')
            ->assertForbidden();
    }

    public function test_roles_page_renders_roles_and_permissions(): void
    {
        $this->actingAs($this->admin)
            ->get('/system/access/roles')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('system/access/Roles')
                ->has('systemRoles')
                ->has('customRoles')
                ->has('permissions')
                ->has('permissionGroups')
            );
    }

    public function test_custom_role_can_be_created_updated_and_delete_is_fail_closed(): void
    {
        $permission = Permission::where('key', 'settings.access.manage')->firstOrFail();

        $this->actingAs($this->admin)
            ->post('/system/access/roles', [
                'name' => 'care_scheduler',
                'label' => 'Care Scheduler',
                'description' => 'Schedules care visits.',
                'level' => 55,
                'permission_keys' => [$permission->key],
            ])
            ->assertRedirect();

        $role = Role::where('name', 'care_scheduler')->firstOrFail();
        $this->assertTrue($role->permissions()->whereKey($permission->id)->exists());

        $this->actingAs($this->admin)
            ->put("/system/access/roles/{$role->id}", [
                'label' => 'Care Scheduling Lead',
                'description' => 'Schedules and supervises care visits.',
                'permission_keys' => [],
            ])
            ->assertRedirect();

        $role->refresh();
        $this->assertSame('Care Scheduling Lead', $role->label);
        $this->assertFalse($role->permissions()->whereKey($permission->id)->exists());

        $this->actingAs($this->admin)
            ->delete("/system/access/roles/{$role->id}")
            ->assertRedirect()
            ->assertSessionHasErrors('role');

        $this->assertDatabaseHas('roles', [
            'id' => $role->id,
            'name' => 'care_scheduler',
            'type' => 'custom',
        ]);
    }

    public function test_system_role_cannot_be_deleted(): void
    {
        $adminRole = Role::where('name', 'admin')->firstOrFail();

        $this->actingAs($this->admin)
            ->delete("/system/access/roles/{$adminRole->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('roles', ['id' => $adminRole->id]);
    }

    public function test_assignments_update_syncs_roles_and_legacy_role_field(): void
    {
        $target = $this->userWithRole('support_worker');
        $coordinatorRole = Role::where('name', 'coordinator')->firstOrFail();

        $this->actingAs($this->admin)
            ->put("/system/access/assignments/{$target->id}", [
                'role_ids' => [$coordinatorRole->id],
            ])
            ->assertRedirect();

        $target->refresh();
        $this->assertTrue($target->roles()->whereKey($coordinatorRole->id)->exists());
        $this->assertSame('coordinator', $target->role);
    }

    private function userWithRole(string $roleName): User
    {
        $role = Role::where('name', $roleName)->firstOrFail();

        $user = User::factory()->create([
            'role' => $roleName,
            'approved_at' => now(),
        ]);
        $user->roles()->attach($role);

        return $user;
    }
}
