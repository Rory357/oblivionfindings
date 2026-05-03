<?php

namespace Tests\Feature\System;

use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UsersControllerTest extends TestCase
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

    public function test_system_users_index_renders_for_admin(): void
    {
        $this->actingAs($this->admin)
            ->get('/system/users')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/users/index')
                ->has('users')
                ->has('filters')
                ->has('roles')
                ->has('stats')
            );
    }

    public function test_system_users_index_denies_support_worker(): void
    {
        $this->actingAs($this->supportWorker)
            ->get('/system/users')
            ->assertForbidden();
    }

    public function test_store_creates_staff_user_and_writes_audit_log(): void
    {
        $role = Role::where('name', 'support_worker')->firstOrFail();

        $this->actingAs($this->admin)
            ->post('/system/users', [
                'name' => 'Taylor Support',
                'email' => 'taylor.support@example.test',
                'password' => 'temporary-pass',
                'user_type' => 'staff',
                'role_ids' => [$role->id],
                'staff' => [
                    'job_title' => 'Support Worker',
                    'department' => 'Residential',
                    'employee_id' => 'EMP-9001',
                ],
            ])
            ->assertRedirect(route('system.users.index', absolute: false));

        $created = User::where('email', 'taylor.support@example.test')->firstOrFail();
        $this->assertTrue($created->roles()->whereKey($role->id)->exists());
        $this->assertDatabaseHas('staff', [
            'user_id' => $created->id,
            'employee_id' => 'EMP-9001',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->admin->id,
            'auditable_type' => $created->getMorphClass(),
            'auditable_id' => $created->id,
            'action' => 'user.created',
        ]);
    }

    public function test_update_user_writes_audit_log(): void
    {
        $target = $this->userWithRole('support_worker');

        $this->actingAs($this->admin)
            ->put("/system/users/{$target->id}", [
                'name' => 'Updated Name',
                'email' => 'updated.user@example.test',
            ])
            ->assertRedirect();

        $target->refresh();
        $this->assertSame('Updated Name', $target->name);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->admin->id,
            'auditable_type' => $target->getMorphClass(),
            'auditable_id' => $target->id,
            'action' => 'user.updated',
        ]);
    }

    public function test_approve_user_writes_audit_log(): void
    {
        $target = User::factory()->create([
            'approved_at' => null,
            'approved_by' => null,
        ]);
        $role = Role::where('name', 'support_worker')->firstOrFail();

        $this->actingAs($this->admin)
            ->post("/system/users/{$target->id}/approve", [
                'role_ids' => [$role->id],
            ])
            ->assertRedirect();

        $target->refresh();
        $this->assertNotNull($target->approved_at);
        $this->assertSame($this->admin->id, $target->approved_by);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->admin->id,
            'auditable_type' => $target->getMorphClass(),
            'auditable_id' => $target->id,
            'action' => 'user.approved',
        ]);
    }

    public function test_suspend_user_writes_audit_log_and_suspends_staff_profile(): void
    {
        $target = $this->userWithRole('support_worker');
        Staff::factory()->create([
            'user_id' => $target->id,
            'status' => 'active',
        ]);

        $this->actingAs($this->admin)
            ->post("/system/users/{$target->id}/suspend")
            ->assertRedirect();

        $target->refresh();
        $this->assertNull($target->approved_at);
        $this->assertDatabaseHas('staff', [
            'user_id' => $target->id,
            'status' => 'suspended',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->admin->id,
            'auditable_type' => $target->getMorphClass(),
            'auditable_id' => $target->id,
            'action' => 'user.suspended',
        ]);
    }

    public function test_destroy_user_writes_audit_log(): void
    {
        $target = $this->userWithRole('support_worker');

        $this->actingAs($this->admin)
            ->delete("/system/users/{$target->id}")
            ->assertRedirect(route('system.users.index', absolute: false));

        $this->assertDatabaseMissing('users', ['id' => $target->id]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->admin->id,
            'auditable_type' => $target->getMorphClass(),
            'auditable_id' => $target->id,
            'action' => 'user.deleted',
        ]);
    }

    public function test_session_termination_writes_audit_logs(): void
    {
        $target = $this->userWithRole('support_worker');
        DB::table('sessions')->insert([
            'id' => 'target-session',
            'user_id' => $target->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Feature test',
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);

        $this->actingAs($this->admin)
            ->delete("/settings/users/{$target->id}/sessions/target-session")
            ->assertRedirect();

        $this->assertDatabaseMissing('sessions', ['id' => 'target-session']);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->admin->id,
            'auditable_type' => $target->getMorphClass(),
            'auditable_id' => $target->id,
            'action' => 'user.session.terminated',
        ]);

        DB::table('sessions')->insert([
            'id' => 'other-session',
            'user_id' => $target->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Feature test',
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);

        $this->actingAs($this->admin)
            ->delete("/settings/users/{$target->id}/sessions")
            ->assertRedirect();

        $this->assertDatabaseMissing('sessions', ['id' => 'other-session']);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->admin->id,
            'auditable_type' => $target->getMorphClass(),
            'auditable_id' => $target->id,
            'action' => 'user.sessions.terminated',
        ]);
    }

    public function test_user_cannot_delete_or_suspend_self(): void
    {
        $this->actingAs($this->admin)
            ->delete("/system/users/{$this->admin->id}")
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->post("/system/users/{$this->admin->id}/suspend")
            ->assertForbidden();
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
