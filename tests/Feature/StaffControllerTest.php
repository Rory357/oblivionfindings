<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $coordinator;
    protected User $supportWorker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RbacSeeder::class);

        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->coordinator = User::factory()->create(['role' => 'coordinator', 'approved_at' => now()]);
        $this->coordinator->roles()->attach(Role::where('name', 'coordinator')->first());

        $this->supportWorker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $this->supportWorker->roles()->attach(Role::where('name', 'support_worker')->first());
    }

    // ──────────────────────────────────────
    // Index - Authentication & Authorization
    // ──────────────────────────────────────

    public function test_staff_index_requires_authentication(): void
    {
        $this->get('/staff')->assertRedirect('/login');
    }

    public function test_staff_index_accessible_by_admin(): void
    {
        $this->actingAs($this->admin)
            ->get('/staff')
            ->assertOk();
    }

    public function test_staff_index_accessible_by_coordinator(): void
    {
        $this->actingAs($this->coordinator)
            ->get('/staff')
            ->assertOk();
    }

    public function test_staff_index_blocked_for_support_worker(): void
    {
        $this->actingAs($this->supportWorker)
            ->get('/staff')
            ->assertForbidden();
    }

    public function test_staff_index_blocked_for_user_without_permission(): void
    {
        $noPermUser = User::factory()->create(['approved_at' => now()]);

        $this->actingAs($noPermUser)
            ->get('/staff')
            ->assertForbidden();
    }

    // ──────────────────────────────────────
    // Index - Data
    // ──────────────────────────────────────

    public function test_staff_index_returns_inertia_page(): void
    {
        $this->actingAs($this->admin)
            ->get('/staff')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('staff/index')
                ->has('users')
                ->has('filters')
            );
    }

    public function test_staff_index_paginates_results(): void
    {
        User::factory()->count(25)->create(['role' => 'support_worker', 'approved_at' => now()]);

        $this->actingAs($this->admin)
            ->get('/staff')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('users.data')
            );
    }

    public function test_staff_index_search_by_name(): void
    {
        User::factory()->create(['name' => 'John Unique Smith', 'role' => 'support_worker', 'approved_at' => now()]);
        User::factory()->create(['name' => 'Jane Doe', 'role' => 'support_worker', 'approved_at' => now()]);

        $this->actingAs($this->admin)
            ->get('/staff?q=Unique')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('users.data', 1)
            );
    }

    public function test_staff_index_search_by_email(): void
    {
        User::factory()->create(['email' => 'unique-test@example.com', 'role' => 'support_worker', 'approved_at' => now()]);
        User::factory()->create(['name' => 'Jane Doe', 'role' => 'support_worker', 'approved_at' => now()]);

        $this->actingAs($this->admin)
            ->get('/staff?q=unique-test')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('users.data', 1)
            );
    }

    public function test_staff_index_preserves_search_filter(): void
    {
        $this->actingAs($this->admin)
            ->get('/staff?q=test')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.q', 'test')
            );
    }

    // ──────────────────────────────────────
    // Show
    // ──────────────────────────────────────

    public function test_staff_show_requires_authentication(): void
    {
        $staff = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $this->get("/staff/{$staff->id}")->assertRedirect('/login');
    }

    public function test_staff_show_accessible_by_admin(): void
    {
        $staff = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $staff->roles()->attach(Role::where('name', 'support_worker')->first());

        $this->actingAs($this->admin)
            ->get("/staff/{$staff->id}")
            ->assertOk();
    }

    public function test_staff_can_view_own_profile(): void
    {
        $this->actingAs($this->supportWorker)
            ->get("/staff/{$this->supportWorker->id}")
            ->assertOk();
    }

    public function test_support_worker_cannot_view_other_staff(): void
    {
        $otherStaff = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $otherStaff->roles()->attach(Role::where('name', 'support_worker')->first());

        $this->actingAs($this->supportWorker)
            ->get("/staff/{$otherStaff->id}")
            ->assertForbidden();
    }

    public function test_staff_show_returns_inertia_page(): void
    {
        $staff = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $staff->roles()->attach(Role::where('name', 'support_worker')->first());

        $this->actingAs($this->admin)
            ->get("/staff/{$staff->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('staff/show')
                ->has('user')
                ->has('todayShifts')
                ->has('upcomingShifts')
                ->has('myDayItems')
            );
    }

    public function test_staff_show_excludes_portal_users(): void
    {
        $clientUser = User::factory()->create(['role' => 'client', 'approved_at' => now()]);
        $clientUser->roles()->attach(Role::where('name', 'client')->first());

        $this->actingAs($this->admin)
            ->get("/staff/{$clientUser->id}")
            ->assertNotFound();
    }

    // ──────────────────────────────────────
    // Edit
    // ──────────────────────────────────────

    public function test_staff_edit_requires_authentication(): void
    {
        $staff = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $this->get("/staff/{$staff->id}/edit")->assertRedirect('/login');
    }

    public function test_staff_edit_accessible_by_admin(): void
    {
        $staff = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $staff->roles()->attach(Role::where('name', 'support_worker')->first());

        $this->actingAs($this->admin)
            ->get("/staff/{$staff->id}/edit")
            ->assertOk();
    }

    public function test_staff_edit_blocked_for_support_worker(): void
    {
        $staff = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $staff->roles()->attach(Role::where('name', 'support_worker')->first());

        $this->actingAs($this->supportWorker)
            ->get("/staff/{$staff->id}/edit")
            ->assertForbidden();
    }

    public function test_staff_edit_returns_roles(): void
    {
        $staff = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $staff->roles()->attach(Role::where('name', 'support_worker')->first());

        $this->actingAs($this->admin)
            ->get("/staff/{$staff->id}/edit")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('staff/edit')
                ->has('user')
                ->has('roles')
            );
    }

    public function test_staff_edit_excludes_portal_users(): void
    {
        $clientUser = User::factory()->create(['role' => 'client', 'approved_at' => now()]);
        $clientUser->roles()->attach(Role::where('name', 'client')->first());

        $this->actingAs($this->admin)
            ->get("/staff/{$clientUser->id}/edit")
            ->assertNotFound();
    }

    // ──────────────────────────────────────
    // Update
    // ──────────────────────────────────────

    public function test_staff_update_requires_authentication(): void
    {
        $staff = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $this->put("/staff/{$staff->id}")->assertRedirect('/login');
    }

    public function test_staff_update_successful(): void
    {
        $staff = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $staff->roles()->attach(Role::where('name', 'support_worker')->first());

        $this->actingAs($this->admin)
            ->put("/staff/{$staff->id}", [
                'name' => 'Updated Name',
                'email' => 'updated@example.com',
            ])
            ->assertRedirect("/staff/{$staff->id}");

        $staff->refresh();
        $this->assertEquals('Updated Name', $staff->name);
        $this->assertEquals('updated@example.com', $staff->email);
    }

    public function test_staff_update_blocked_for_support_worker(): void
    {
        $staff = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $staff->roles()->attach(Role::where('name', 'support_worker')->first());

        $this->actingAs($this->supportWorker)
            ->put("/staff/{$staff->id}", [
                'name' => 'Updated Name',
                'email' => 'updated@example.com',
            ])
            ->assertForbidden();
    }

    public function test_staff_update_validates_required_fields(): void
    {
        $staff = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $staff->roles()->attach(Role::where('name', 'support_worker')->first());

        $this->actingAs($this->admin)
            ->put("/staff/{$staff->id}", [
                'name' => '',
                'email' => '',
            ])
            ->assertSessionHasErrors(['name', 'email']);
    }

    public function test_staff_update_validates_email_format(): void
    {
        $staff = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $staff->roles()->attach(Role::where('name', 'support_worker')->first());

        $this->actingAs($this->admin)
            ->put("/staff/{$staff->id}", [
                'name' => 'Valid Name',
                'email' => 'not-an-email',
            ])
            ->assertSessionHasErrors(['email']);
    }

    public function test_staff_update_syncs_roles(): void
    {
        $staff = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $staff->roles()->attach(Role::where('name', 'support_worker')->first());

        $coordinatorRole = Role::where('name', 'coordinator')->first();

        $this->actingAs($this->admin)
            ->put("/staff/{$staff->id}", [
                'name' => $staff->name,
                'email' => $staff->email,
                'role_ids' => [$coordinatorRole->id],
            ])
            ->assertRedirect("/staff/{$staff->id}");

        $staff->refresh();
        $this->assertTrue($staff->roles->contains($coordinatorRole));
    }

    public function test_staff_update_creates_staff_profile(): void
    {
        $staff = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $staff->roles()->attach(Role::where('name', 'support_worker')->first());

        $this->actingAs($this->admin)
            ->put("/staff/{$staff->id}", [
                'name' => $staff->name,
                'email' => $staff->email,
                'profile' => [
                    'phone' => '021 123 4567',
                    'job_title' => 'Senior Support Worker',
                    'employment_type' => 'full_time',
                    'is_active' => true,
                ],
            ])
            ->assertRedirect("/staff/{$staff->id}");

        $this->assertDatabaseHas('staff_profiles', [
            'user_id' => $staff->id,
            'phone' => '021 123 4567',
            'job_title' => 'Senior Support Worker',
        ]);
    }

    public function test_staff_update_excludes_portal_users(): void
    {
        $clientUser = User::factory()->create(['role' => 'client', 'approved_at' => now()]);
        $clientUser->roles()->attach(Role::where('name', 'client')->first());

        $this->actingAs($this->admin)
            ->put("/staff/{$clientUser->id}", [
                'name' => 'Hacked Name',
                'email' => 'hacked@example.com',
            ])
            ->assertNotFound();
    }
}
