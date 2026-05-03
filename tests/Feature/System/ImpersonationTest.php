<?php

namespace Tests\Feature\System;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImpersonationTest extends TestCase
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

    public function test_impersonation_requires_permission(): void
    {
        $target = User::factory()->create(['approved_at' => now()]);

        $this->actingAs($this->supportWorker)
            ->post("/system/users/{$target->id}/impersonate")
            ->assertForbidden();

        $this->assertDatabaseMissing('audit_logs', ['action' => 'user.impersonate.start']);
    }

    public function test_cannot_impersonate_self(): void
    {
        $this->actingAs($this->admin)
            ->post("/system/users/{$this->admin->id}/impersonate")
            ->assertForbidden();
    }

    public function test_cannot_impersonate_admin(): void
    {
        $targetAdmin = $this->userWithRole('admin');

        $this->actingAs($this->admin)
            ->post("/system/users/{$targetAdmin->id}/impersonate")
            ->assertForbidden();
    }

    public function test_start_impersonation_writes_audit_log(): void
    {
        $this->actingAs($this->admin)
            ->post("/system/users/{$this->supportWorker->id}/impersonate")
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->admin->id,
            'auditable_type' => $this->supportWorker->getMorphClass(),
            'auditable_id' => $this->supportWorker->id,
            'action' => 'user.impersonate.start',
        ]);
    }

    public function test_stop_impersonation_writes_audit_log(): void
    {
        $this->actingAs($this->admin)
            ->post("/system/users/{$this->supportWorker->id}/impersonate")
            ->assertRedirect(route('dashboard', absolute: false));

        $this->post('/system/users/stop-impersonating')
            ->assertRedirect(route('system.users.index', absolute: false));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.impersonate.stop',
        ]);
    }

    public function test_stop_impersonation_requires_active_impersonation_session(): void
    {
        $this->actingAs($this->admin)
            ->post('/system/users/stop-impersonating')
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
