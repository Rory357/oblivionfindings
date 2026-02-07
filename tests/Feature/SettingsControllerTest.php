<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\NotificationEscalationRule;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleNotificationPreference;
use App\Models\ServiceContext;
use App\Models\Site;
use App\Models\User;
use App\Models\UserNotificationPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RbacSeeder::class);

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'approved_at' => now(),
        ]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->staff = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $this->staff->roles()->attach(Role::where('name', 'support_worker')->first());
    }

    // ========================================================================
    // AUTHENTICATION: every settings route requires auth
    // ========================================================================

    public function test_profile_edit_requires_authentication(): void
    {
        $this->get('/settings/profile')->assertRedirect('/login');
    }

    public function test_profile_update_requires_authentication(): void
    {
        $this->patch('/settings/profile')->assertRedirect('/login');
    }

    public function test_profile_photo_upload_requires_authentication(): void
    {
        $this->post('/settings/profile/photo')->assertRedirect('/login');
    }

    public function test_profile_photo_delete_requires_authentication(): void
    {
        $this->delete('/settings/profile/photo')->assertRedirect('/login');
    }

    public function test_profile_destroy_requires_authentication(): void
    {
        $this->delete('/settings/profile')->assertRedirect('/login');
    }

    public function test_password_edit_requires_authentication(): void
    {
        $this->get('/settings/password')->assertRedirect('/login');
    }

    public function test_password_update_requires_authentication(): void
    {
        $this->put('/settings/password')->assertRedirect('/login');
    }

    public function test_access_index_requires_authentication(): void
    {
        $this->get('/settings/access')->assertRedirect('/login');
    }

    public function test_access_update_requires_authentication(): void
    {
        $user = User::factory()->create();
        $this->put("/settings/access/{$user->id}")->assertRedirect('/login');
    }

    public function test_access_approve_requires_authentication(): void
    {
        $user = User::factory()->create();
        $this->post("/settings/access/{$user->id}/approve")->assertRedirect('/login');
    }

    public function test_roles_index_requires_authentication(): void
    {
        $this->get('/settings/roles')->assertRedirect('/login');
    }

    public function test_roles_create_requires_authentication(): void
    {
        $this->get('/settings/roles/create')->assertRedirect('/login');
    }

    public function test_roles_store_requires_authentication(): void
    {
        $this->post('/settings/roles')->assertRedirect('/login');
    }

    public function test_terminology_edit_requires_authentication(): void
    {
        $this->get('/settings/terminology')->assertRedirect('/login');
    }

    public function test_terminology_update_requires_authentication(): void
    {
        $this->put('/settings/terminology')->assertRedirect('/login');
    }

    public function test_branding_edit_requires_authentication(): void
    {
        $this->get('/settings/branding')->assertRedirect('/login');
    }

    public function test_branding_update_requires_authentication(): void
    {
        $this->post('/settings/branding')->assertRedirect('/login');
    }

    public function test_service_contexts_index_requires_authentication(): void
    {
        $this->get('/settings/service-contexts')->assertRedirect('/login');
    }

    public function test_service_contexts_store_requires_authentication(): void
    {
        $this->post('/settings/service-contexts')->assertRedirect('/login');
    }

    public function test_service_contexts_set_default_requires_authentication(): void
    {
        $this->post('/settings/service-contexts/default')->assertRedirect('/login');
    }

    public function test_notifications_index_requires_authentication(): void
    {
        $this->get('/settings/notifications')->assertRedirect('/login');
    }

    public function test_notifications_update_requires_authentication(): void
    {
        $this->put('/settings/notifications')->assertRedirect('/login');
    }

    public function test_notification_roles_requires_authentication(): void
    {
        $this->get('/settings/notifications/roles')->assertRedirect('/login');
    }

    public function test_notification_roles_update_requires_authentication(): void
    {
        $this->put('/settings/notifications/roles')->assertRedirect('/login');
    }

    public function test_escalations_index_requires_authentication(): void
    {
        $this->get('/settings/notifications/escalations')->assertRedirect('/login');
    }

    public function test_escalations_update_requires_authentication(): void
    {
        $this->put('/settings/notifications/escalations')->assertRedirect('/login');
    }

    // ========================================================================
    // AUTHORIZATION: permission-protected routes deny unprivileged users
    // ========================================================================

    public function test_access_index_denied_for_support_worker(): void
    {
        $this->actingAs($this->staff)->get('/settings/access')->assertForbidden();
    }

    public function test_access_update_denied_for_support_worker(): void
    {
        $target = User::factory()->create();
        $this->actingAs($this->staff)
            ->put("/settings/access/{$target->id}", ['role_ids' => []])
            ->assertForbidden();
    }

    public function test_access_approve_denied_for_support_worker(): void
    {
        $target = User::factory()->create(['approved_at' => null]);
        $roleId = Role::where('name', 'support_worker')->first()->id;
        $this->actingAs($this->staff)
            ->post("/settings/access/{$target->id}/approve", ['role_ids' => [$roleId]])
            ->assertForbidden();
    }

    public function test_roles_index_denied_for_support_worker(): void
    {
        $this->actingAs($this->staff)->get('/settings/roles')->assertForbidden();
    }

    public function test_roles_create_denied_for_support_worker(): void
    {
        $this->actingAs($this->staff)->get('/settings/roles/create')->assertForbidden();
    }

    public function test_roles_store_denied_for_support_worker(): void
    {
        $this->actingAs($this->staff)
            ->post('/settings/roles', [
                'name' => 'test_role',
                'label' => 'Test Role',
            ])
            ->assertForbidden();
    }

    public function test_terminology_edit_denied_for_support_worker(): void
    {
        $this->actingAs($this->staff)->get('/settings/terminology')->assertForbidden();
    }

    public function test_terminology_update_denied_for_support_worker(): void
    {
        $this->actingAs($this->staff)
            ->put('/settings/terminology', ['labels' => ['client.singular' => 'Patient']])
            ->assertForbidden();
    }

    public function test_branding_edit_denied_for_support_worker(): void
    {
        $this->actingAs($this->staff)->get('/settings/branding')->assertForbidden();
    }

    public function test_branding_update_denied_for_support_worker(): void
    {
        $this->actingAs($this->staff)
            ->post('/settings/branding', ['branding' => ['name' => 'Acme']])
            ->assertForbidden();
    }

    public function test_service_contexts_index_denied_for_support_worker(): void
    {
        $this->actingAs($this->staff)->get('/settings/service-contexts')->assertForbidden();
    }

    public function test_service_contexts_store_denied_for_support_worker(): void
    {
        $this->actingAs($this->staff)
            ->post('/settings/service-contexts', ['name' => 'Test', 'type' => 'residential'])
            ->assertForbidden();
    }

    public function test_notification_roles_denied_for_support_worker(): void
    {
        $this->actingAs($this->staff)->get('/settings/notifications/roles')->assertForbidden();
    }

    public function test_notification_roles_update_denied_for_support_worker(): void
    {
        $this->actingAs($this->staff)
            ->put('/settings/notifications/roles', ['matrix' => []])
            ->assertForbidden();
    }

    public function test_escalations_index_denied_for_support_worker(): void
    {
        $this->actingAs($this->staff)
            ->get('/settings/notifications/escalations')
            ->assertForbidden();
    }

    public function test_escalations_update_denied_for_support_worker(): void
    {
        $this->actingAs($this->staff)
            ->put('/settings/notifications/escalations', ['rules' => []])
            ->assertForbidden();
    }

    // ========================================================================
    // PROFILE: view, update, photo, delete account
    // ========================================================================

    public function test_profile_edit_renders_page(): void
    {
        $this->actingAs($this->admin)
            ->get('/settings/profile')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/profile')
                ->has('mustVerifyEmail')
            );
    }

    public function test_profile_update_changes_name(): void
    {
        $this->actingAs($this->admin)
            ->patch('/settings/profile', [
                'name' => 'Updated Name',
                'email' => $this->admin->email,
            ])
            ->assertRedirect(route('profile.edit'));

        $this->assertDatabaseHas('users', [
            'id' => $this->admin->id,
            'name' => 'Updated Name',
        ]);
    }

    public function test_profile_update_changes_email(): void
    {
        $this->actingAs($this->admin)
            ->patch('/settings/profile', [
                'name' => $this->admin->name,
                'email' => 'newemail@example.com',
            ])
            ->assertRedirect(route('profile.edit'));

        $this->assertDatabaseHas('users', [
            'id' => $this->admin->id,
            'email' => 'newemail@example.com',
        ]);
    }

    public function test_profile_update_unverifies_email_on_change(): void
    {
        $this->assertNotNull($this->admin->email_verified_at);

        $this->actingAs($this->admin)
            ->patch('/settings/profile', [
                'name' => $this->admin->name,
                'email' => 'different@example.com',
            ]);

        $this->admin->refresh();
        $this->assertNull($this->admin->email_verified_at);
    }

    public function test_profile_update_keeps_verification_when_email_unchanged(): void
    {
        $originalVerified = $this->admin->email_verified_at;

        $this->actingAs($this->admin)
            ->patch('/settings/profile', [
                'name' => 'Changed Name Only',
                'email' => $this->admin->email,
            ]);

        $this->admin->refresh();
        $this->assertNotNull($this->admin->email_verified_at);
    }

    public function test_profile_update_validates_name_required(): void
    {
        $this->actingAs($this->admin)
            ->patch('/settings/profile', [
                'name' => '',
                'email' => $this->admin->email,
            ])
            ->assertSessionHasErrors(['name']);
    }

    public function test_profile_update_validates_email_required(): void
    {
        $this->actingAs($this->admin)
            ->patch('/settings/profile', [
                'name' => $this->admin->name,
                'email' => '',
            ])
            ->assertSessionHasErrors(['email']);
    }

    public function test_profile_update_validates_email_unique(): void
    {
        $other = User::factory()->create();

        $this->actingAs($this->admin)
            ->patch('/settings/profile', [
                'name' => $this->admin->name,
                'email' => $other->email,
            ])
            ->assertSessionHasErrors(['email']);
    }

    public function test_profile_update_validates_email_format(): void
    {
        $this->actingAs($this->admin)
            ->patch('/settings/profile', [
                'name' => $this->admin->name,
                'email' => 'not-an-email',
            ])
            ->assertSessionHasErrors(['email']);
    }

    public function test_profile_photo_upload_succeeds(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin)
            ->post('/settings/profile/photo', [
                'photo' => UploadedFile::fake()->image('avatar.jpg', 200, 200)->size(100),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->admin->refresh();
        $this->assertNotNull($this->admin->profile_photo_path);
    }

    public function test_profile_photo_upload_rejects_oversized_file(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin)
            ->post('/settings/profile/photo', [
                'photo' => UploadedFile::fake()->image('avatar.jpg', 200, 200)->size(6000), // 6MB > 5MB
            ])
            ->assertSessionHasErrors(['photo']);
    }

    public function test_profile_photo_upload_rejects_non_image(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin)
            ->post('/settings/profile/photo', [
                'photo' => UploadedFile::fake()->create('document.pdf', 100, 'application/pdf'),
            ])
            ->assertSessionHasErrors(['photo']);
    }

    public function test_profile_photo_upload_replaces_existing(): void
    {
        Storage::fake('public');

        // Upload first photo
        $this->actingAs($this->admin)
            ->post('/settings/profile/photo', [
                'photo' => UploadedFile::fake()->image('first.jpg', 200, 200)->size(100),
            ]);

        $this->admin->refresh();
        $firstPath = $this->admin->profile_photo_path;
        $this->assertNotNull($firstPath);

        // Upload second photo
        $this->actingAs($this->admin)
            ->post('/settings/profile/photo', [
                'photo' => UploadedFile::fake()->image('second.jpg', 200, 200)->size(100),
            ]);

        $this->admin->refresh();
        $this->assertNotEquals($firstPath, $this->admin->profile_photo_path);
    }

    public function test_profile_photo_delete_removes_photo(): void
    {
        Storage::fake('public');

        // First upload a photo
        $this->actingAs($this->admin)
            ->post('/settings/profile/photo', [
                'photo' => UploadedFile::fake()->image('avatar.jpg', 200, 200)->size(100),
            ]);

        $this->admin->refresh();
        $this->assertNotNull($this->admin->profile_photo_path);

        // Then delete it
        $this->actingAs($this->admin)
            ->delete('/settings/profile/photo')
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->admin->refresh();
        $this->assertNull($this->admin->profile_photo_path);
    }

    public function test_profile_photo_delete_when_no_photo_exists(): void
    {
        $user = User::factory()->create(['profile_photo_path' => null, 'approved_at' => now()]);

        $this->actingAs($user)
            ->delete('/settings/profile/photo')
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    public function test_account_deletion_requires_password(): void
    {
        $this->actingAs($this->admin)
            ->delete('/settings/profile', [
                'password' => '',
            ])
            ->assertSessionHasErrors(['password']);

        $this->assertDatabaseHas('users', ['id' => $this->admin->id]);
    }

    public function test_account_deletion_rejects_wrong_password(): void
    {
        $this->actingAs($this->admin)
            ->delete('/settings/profile', [
                'password' => 'wrong-password',
            ])
            ->assertSessionHasErrors(['password']);

        $this->assertDatabaseHas('users', ['id' => $this->admin->id]);
    }

    public function test_account_deletion_succeeds_with_correct_password(): void
    {
        $userId = $this->admin->id;

        $this->actingAs($this->admin)
            ->delete('/settings/profile', [
                'password' => 'password',
            ])
            ->assertRedirect('/');

        $this->assertDatabaseMissing('users', ['id' => $userId]);
    }

    // ========================================================================
    // PASSWORD
    // ========================================================================

    public function test_password_edit_renders_page(): void
    {
        $this->actingAs($this->admin)
            ->get('/settings/password')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('settings/password'));
    }

    public function test_password_update_succeeds_with_valid_data(): void
    {
        $this->actingAs($this->admin)
            ->put('/settings/password', [
                'current_password' => 'password',
                'password' => 'new-secure-password',
                'password_confirmation' => 'new-secure-password',
            ])
            ->assertRedirect();

        $this->admin->refresh();
        $this->assertTrue(Hash::check('new-secure-password', $this->admin->password));
    }

    public function test_password_update_rejects_wrong_current_password(): void
    {
        $this->actingAs($this->admin)
            ->put('/settings/password', [
                'current_password' => 'wrong-password',
                'password' => 'new-secure-password',
                'password_confirmation' => 'new-secure-password',
            ])
            ->assertSessionHasErrors(['current_password']);
    }

    public function test_password_update_requires_confirmation(): void
    {
        $this->actingAs($this->admin)
            ->put('/settings/password', [
                'current_password' => 'password',
                'password' => 'new-secure-password',
                'password_confirmation' => 'mismatched-password',
            ])
            ->assertSessionHasErrors(['password']);
    }

    public function test_password_update_requires_current_password(): void
    {
        $this->actingAs($this->admin)
            ->put('/settings/password', [
                'current_password' => '',
                'password' => 'new-secure-password',
                'password_confirmation' => 'new-secure-password',
            ])
            ->assertSessionHasErrors(['current_password']);
    }

    public function test_password_update_is_throttled(): void
    {
        // Make 6 valid attempts to hit the throttle
        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($this->admin)
                ->put('/settings/password', [
                    'current_password' => 'password',
                    'password' => 'new-secure-password',
                    'password_confirmation' => 'new-secure-password',
                ]);
        }

        // 7th attempt should be throttled
        $this->actingAs($this->admin)
            ->put('/settings/password', [
                'current_password' => 'new-secure-password',
                'password' => 'another-password',
                'password_confirmation' => 'another-password',
            ])
            ->assertStatus(429);
    }

    // ========================================================================
    // ACCESS CONTROL: list, update, approve
    // ========================================================================

    public function test_access_index_renders_for_admin(): void
    {
        $this->actingAs($this->admin)
            ->get('/settings/access')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/access')
                ->has('users')
                ->has('roles')
                ->has('permissions')
                ->has('userOverrides')
            );
    }

    public function test_access_update_syncs_roles(): void
    {
        $target = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $coordinatorRole = Role::where('name', 'coordinator')->first();

        $this->actingAs($this->admin)
            ->put("/settings/access/{$target->id}", [
                'role_ids' => [$coordinatorRole->id],
                'overrides' => [],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $target->refresh();
        $this->assertTrue($target->roles->contains('id', $coordinatorRole->id));
    }

    public function test_access_update_syncs_multiple_roles(): void
    {
        $target = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $coordinatorRole = Role::where('name', 'coordinator')->first();
        $financeRole = Role::where('name', 'finance')->first();

        $this->actingAs($this->admin)
            ->put("/settings/access/{$target->id}", [
                'role_ids' => [$coordinatorRole->id, $financeRole->id],
                'overrides' => [],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $target->refresh();
        $this->assertCount(2, $target->roles);
        $this->assertTrue($target->roles->contains('id', $coordinatorRole->id));
        $this->assertTrue($target->roles->contains('id', $financeRole->id));
    }

    public function test_access_update_sets_permission_override_allow(): void
    {
        $target = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $permission = Permission::where('key', 'settings.terminology.manage')->first();

        $this->actingAs($this->admin)
            ->put("/settings/access/{$target->id}", [
                'role_ids' => [],
                'overrides' => [$permission->id => 'allow'],
            ])
            ->assertRedirect();

        $this->assertTrue($target->permissionOverrides()->where('permissions.id', $permission->id)->wherePivot('allowed', true)->exists());
    }

    public function test_access_update_sets_permission_override_deny(): void
    {
        $target = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $permission = Permission::where('key', 'settings.terminology.manage')->first();

        $this->actingAs($this->admin)
            ->put("/settings/access/{$target->id}", [
                'role_ids' => [],
                'overrides' => [$permission->id => 'deny'],
            ])
            ->assertRedirect();

        $this->assertTrue($target->permissionOverrides()->where('permissions.id', $permission->id)->wherePivot('allowed', false)->exists());
    }

    public function test_access_update_sets_permission_override_inherit_removes_override(): void
    {
        $target = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $permission = Permission::where('key', 'settings.terminology.manage')->first();

        // First set an explicit allow override
        $target->permissionOverrides()->attach($permission->id, ['allowed' => true]);
        $this->assertTrue($target->permissionOverrides()->where('permissions.id', $permission->id)->exists());

        // Then set to inherit
        $this->actingAs($this->admin)
            ->put("/settings/access/{$target->id}", [
                'role_ids' => [],
                'overrides' => [$permission->id => 'inherit'],
            ])
            ->assertRedirect();

        $this->assertFalse($target->permissionOverrides()->where('permissions.id', $permission->id)->exists());
    }

    public function test_access_update_validates_role_ids_exist(): void
    {
        $target = User::factory()->create(['approved_at' => now()]);

        $this->actingAs($this->admin)
            ->put("/settings/access/{$target->id}", [
                'role_ids' => [99999],
                'overrides' => [],
            ])
            ->assertSessionHasErrors(['role_ids.0']);
    }

    public function test_access_update_validates_override_values(): void
    {
        $target = User::factory()->create(['approved_at' => now()]);
        $permission = Permission::where('key', 'settings.access.manage')->first();

        $this->actingAs($this->admin)
            ->put("/settings/access/{$target->id}", [
                'role_ids' => [],
                'overrides' => [$permission->id => 'invalid_value'],
            ])
            ->assertSessionHasErrors(["overrides.{$permission->id}"]);
    }

    // ========================================================================
    // USER APPROVAL WORKFLOW
    // ========================================================================

    public function test_approve_sets_approved_at_and_approved_by(): void
    {
        $target = User::factory()->create([
            'approved_at' => null,
            'approved_by' => null,
        ]);
        $roleId = Role::where('name', 'support_worker')->first()->id;

        $this->actingAs($this->admin)
            ->post("/settings/access/{$target->id}/approve", [
                'role_ids' => [$roleId],
                'overrides' => [],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $target->refresh();
        $this->assertNotNull($target->approved_at);
        $this->assertEquals($this->admin->id, $target->approved_by);
    }

    public function test_approve_syncs_roles(): void
    {
        $target = User::factory()->create(['approved_at' => null]);
        $coordinatorRole = Role::where('name', 'coordinator')->first();

        $this->actingAs($this->admin)
            ->post("/settings/access/{$target->id}/approve", [
                'role_ids' => [$coordinatorRole->id],
                'overrides' => [],
            ])
            ->assertRedirect();

        $target->refresh();
        $this->assertTrue($target->roles->contains('id', $coordinatorRole->id));
    }

    public function test_approve_requires_at_least_one_role(): void
    {
        $target = User::factory()->create(['approved_at' => null]);

        $this->actingAs($this->admin)
            ->post("/settings/access/{$target->id}/approve", [
                'role_ids' => [],
            ])
            ->assertSessionHasErrors(['role_ids']);
    }

    public function test_approve_does_not_overwrite_existing_approval(): void
    {
        $originalApprover = User::factory()->create(['approved_at' => now()]);
        $originalDate = now()->subDays(5);

        $target = User::factory()->create([
            'approved_at' => $originalDate,
            'approved_by' => $originalApprover->id,
        ]);

        $roleId = Role::where('name', 'support_worker')->first()->id;

        $this->actingAs($this->admin)
            ->post("/settings/access/{$target->id}/approve", [
                'role_ids' => [$roleId],
                'overrides' => [],
            ])
            ->assertRedirect();

        $target->refresh();
        // Should retain original approval info
        $this->assertEquals($originalApprover->id, $target->approved_by);
    }

    public function test_approve_syncs_permission_overrides(): void
    {
        $target = User::factory()->create(['approved_at' => null]);
        $roleId = Role::where('name', 'support_worker')->first()->id;
        $permission = Permission::where('key', 'settings.branding.manage')->first();

        $this->actingAs($this->admin)
            ->post("/settings/access/{$target->id}/approve", [
                'role_ids' => [$roleId],
                'overrides' => [$permission->id => 'allow'],
            ])
            ->assertRedirect();

        $target->refresh();
        $this->assertTrue(
            $target->permissionOverrides()
                ->where('permissions.id', $permission->id)
                ->wherePivot('allowed', true)
                ->exists()
        );
    }

    // ========================================================================
    // PERMISSION OVERRIDE PRECEDENCE: deny > allow > role
    // ========================================================================

    public function test_deny_override_beats_role_permission(): void
    {
        // Admin role has settings.terminology.manage, but we deny via override
        $permission = Permission::where('key', 'settings.terminology.manage')->first();
        $this->admin->permissionOverrides()->attach($permission->id, ['allowed' => false]);

        $this->assertFalse($this->admin->canDo('settings.terminology.manage'));
    }

    public function test_allow_override_grants_permission_not_in_role(): void
    {
        // Support worker doesn't have settings.branding.manage
        $permission = Permission::where('key', 'settings.branding.manage')->first();
        $this->staff->permissionOverrides()->attach($permission->id, ['allowed' => true]);

        $this->assertTrue($this->staff->canDo('settings.branding.manage'));
    }

    public function test_deny_override_beats_allow_via_role(): void
    {
        // Create a user with a role that has terminology manage
        $user = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $user->roles()->attach(Role::where('name', 'admin')->first());

        $permission = Permission::where('key', 'settings.terminology.manage')->first();
        $user->permissionOverrides()->attach($permission->id, ['allowed' => false]);

        $this->assertFalse($user->canDo('settings.terminology.manage'));
    }

    public function test_role_permission_works_without_overrides(): void
    {
        $this->assertTrue($this->admin->canDo('settings.access.manage'));
        $this->assertFalse($this->staff->canDo('settings.access.manage'));
    }

    // ========================================================================
    // ROLES CRUD
    // ========================================================================

    public function test_roles_index_renders_for_admin(): void
    {
        $this->actingAs($this->admin)
            ->get('/settings/roles')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/roles/index')
                ->has('roles')
                ->has('permissions')
            );
    }

    public function test_roles_create_page_renders_for_admin(): void
    {
        $this->actingAs($this->admin)
            ->get('/settings/roles/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/roles/edit')
                ->where('mode', 'create')
                ->has('permissions')
            );
    }

    public function test_role_store_creates_new_role(): void
    {
        $this->actingAs($this->admin)
            ->post('/settings/roles', [
                'name' => 'custom_role',
                'label' => 'Custom Role',
                'permission_keys' => [],
            ])
            ->assertRedirect(route('settings.roles.index'));

        $this->assertDatabaseHas('roles', [
            'name' => 'custom_role',
            'label' => 'Custom Role',
        ]);
    }

    public function test_role_store_syncs_permissions(): void
    {
        $permKeys = ['shifts.viewAny', 'clients.viewAny'];

        $this->actingAs($this->admin)
            ->post('/settings/roles', [
                'name' => 'custom_with_perms',
                'label' => 'Custom With Perms',
                'permission_keys' => $permKeys,
            ])
            ->assertRedirect(route('settings.roles.index'));

        $role = Role::where('name', 'custom_with_perms')->first();
        $this->assertNotNull($role);
        $this->assertEquals(2, $role->permissions()->count());
        $this->assertTrue($role->permissions()->where('key', 'shifts.viewAny')->exists());
        $this->assertTrue($role->permissions()->where('key', 'clients.viewAny')->exists());
    }

    public function test_role_store_validates_unique_name(): void
    {
        $this->actingAs($this->admin)
            ->post('/settings/roles', [
                'name' => 'admin', // already exists
                'label' => 'Another Admin',
                'permission_keys' => [],
            ])
            ->assertSessionHasErrors(['name']);
    }

    public function test_role_store_validates_name_format(): void
    {
        $this->actingAs($this->admin)
            ->post('/settings/roles', [
                'name' => 'Invalid Name!', // must be lowercase alphanum + underscores
                'label' => 'Test',
                'permission_keys' => [],
            ])
            ->assertSessionHasErrors(['name']);
    }

    public function test_role_store_validates_name_max_length(): void
    {
        $this->actingAs($this->admin)
            ->post('/settings/roles', [
                'name' => str_repeat('a', 51),
                'label' => 'Test',
                'permission_keys' => [],
            ])
            ->assertSessionHasErrors(['name']);
    }

    public function test_role_store_validates_label_required(): void
    {
        $this->actingAs($this->admin)
            ->post('/settings/roles', [
                'name' => 'new_role',
                'label' => '',
                'permission_keys' => [],
            ])
            ->assertSessionHasErrors(['label']);
    }

    public function test_role_store_validates_permission_keys_exist(): void
    {
        $this->actingAs($this->admin)
            ->post('/settings/roles', [
                'name' => 'new_role',
                'label' => 'New Role',
                'permission_keys' => ['nonexistent.permission'],
            ])
            ->assertSessionHasErrors(['permission_keys.0']);
    }

    public function test_role_edit_page_renders(): void
    {
        $role = Role::where('name', 'coordinator')->first();

        $this->actingAs($this->admin)
            ->get("/settings/roles/{$role->id}/edit")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/roles/edit')
                ->where('mode', 'edit')
                ->has('role')
                ->has('permissions')
            );
    }

    public function test_role_update_modifies_role(): void
    {
        $role = Role::create(['name' => 'test_update', 'label' => 'Test Update']);

        $this->actingAs($this->admin)
            ->put("/settings/roles/{$role->id}", [
                'name' => 'test_updated',
                'label' => 'Test Updated Label',
                'permission_keys' => [],
            ])
            ->assertRedirect(route('settings.roles.index'));

        $this->assertDatabaseHas('roles', [
            'id' => $role->id,
            'name' => 'test_updated',
            'label' => 'Test Updated Label',
        ]);
    }

    public function test_role_update_syncs_permissions(): void
    {
        $role = Role::create(['name' => 'test_perm_sync', 'label' => 'Test']);
        $role->permissions()->sync(
            Permission::whereIn('key', ['shifts.viewAny'])->pluck('id')
        );

        $this->actingAs($this->admin)
            ->put("/settings/roles/{$role->id}", [
                'name' => 'test_perm_sync',
                'label' => 'Test',
                'permission_keys' => ['clients.viewAny', 'timesheets.viewAny'],
            ])
            ->assertRedirect(route('settings.roles.index'));

        $role->refresh();
        $this->assertEquals(2, $role->permissions()->count());
        $this->assertFalse($role->permissions()->where('key', 'shifts.viewAny')->exists());
        $this->assertTrue($role->permissions()->where('key', 'clients.viewAny')->exists());
        $this->assertTrue($role->permissions()->where('key', 'timesheets.viewAny')->exists());
    }

    public function test_role_update_validates_unique_name_ignoring_self(): void
    {
        $role = Role::create(['name' => 'my_role', 'label' => 'My Role']);

        // Update with same name should succeed
        $this->actingAs($this->admin)
            ->put("/settings/roles/{$role->id}", [
                'name' => 'my_role',
                'label' => 'Updated Label',
                'permission_keys' => [],
            ])
            ->assertRedirect(route('settings.roles.index'));

        // Update with another existing name should fail
        $this->actingAs($this->admin)
            ->put("/settings/roles/{$role->id}", [
                'name' => 'admin',
                'label' => 'Conflict',
                'permission_keys' => [],
            ])
            ->assertSessionHasErrors(['name']);
    }

    // ========================================================================
    // TERMINOLOGY
    // ========================================================================

    public function test_terminology_edit_renders_for_admin(): void
    {
        $this->actingAs($this->admin)
            ->get('/settings/terminology')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/terminology')
                ->has('defaults')
                ->has('overrides')
            );
    }

    public function test_terminology_update_creates_overrides(): void
    {
        $this->actingAs($this->admin)
            ->put('/settings/terminology', [
                'labels' => ['client.singular' => 'Patient'],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('app_settings', [
            'key' => 'labels.client.singular',
        ]);

        $row = AppSetting::where('key', 'labels.client.singular')->first();
        $this->assertEquals('Patient', $row->value);
    }

    public function test_terminology_update_removes_blank_overrides(): void
    {
        AppSetting::create(['key' => 'labels.client.singular', 'value' => 'Patient']);

        $this->actingAs($this->admin)
            ->put('/settings/terminology', [
                'labels' => ['client.singular' => ''],
            ])
            ->assertRedirect();

        $this->assertDatabaseMissing('app_settings', [
            'key' => 'labels.client.singular',
        ]);
    }

    public function test_terminology_update_removes_default_matching_overrides(): void
    {
        AppSetting::create(['key' => 'labels.client.singular', 'value' => 'Patient']);

        $this->actingAs($this->admin)
            ->put('/settings/terminology', [
                'labels' => ['client.singular' => 'Client'], // matches config default
            ])
            ->assertRedirect();

        $this->assertDatabaseMissing('app_settings', [
            'key' => 'labels.client.singular',
        ]);
    }

    public function test_terminology_update_validates_max_length(): void
    {
        $this->actingAs($this->admin)
            ->put('/settings/terminology', [
                'labels' => ['client.singular' => str_repeat('X', 81)],
            ])
            ->assertSessionHasErrors(['labels.client.singular']);
    }

    public function test_terminology_update_validates_labels_required(): void
    {
        $this->actingAs($this->admin)
            ->put('/settings/terminology', [])
            ->assertSessionHasErrors(['labels']);
    }

    // ========================================================================
    // BRANDING
    // ========================================================================

    public function test_branding_edit_renders_for_admin(): void
    {
        $this->actingAs($this->admin)
            ->get('/settings/branding')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/branding')
                ->has('allowedVars')
                ->has('theme')
                ->has('branding')
            );
    }

    public function test_branding_update_saves_name(): void
    {
        $this->actingAs($this->admin)
            ->post('/settings/branding', [
                'branding' => ['name' => 'Acme Care'],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $row = AppSetting::where('key', 'branding.name')->first();
        $this->assertNotNull($row);
        $this->assertEquals('Acme Care', $row->value);
    }

    public function test_branding_update_clears_name_when_blank(): void
    {
        AppSetting::create(['key' => 'branding.name', 'value' => 'Old Name']);

        $this->actingAs($this->admin)
            ->post('/settings/branding', [
                'branding' => ['name' => ''],
            ])
            ->assertRedirect();

        $this->assertDatabaseMissing('app_settings', ['key' => 'branding.name']);
    }

    public function test_branding_update_saves_theme_css_variables(): void
    {
        $this->actingAs($this->admin)
            ->post('/settings/branding', [
                'theme' => [
                    'light' => ['--primary' => '220 90% 56%', '--radius' => '0.5rem'],
                    'dark' => ['--primary' => '220 90% 40%'],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $light = AppSetting::where('key', 'theme.light')->first();
        $this->assertNotNull($light);
        $this->assertIsArray($light->value);
        $this->assertEquals('220 90% 56%', $light->value['--primary']);
        $this->assertEquals('0.5rem', $light->value['--radius']);

        $dark = AppSetting::where('key', 'theme.dark')->first();
        $this->assertNotNull($dark);
        $this->assertIsArray($dark->value);
        $this->assertEquals('220 90% 40%', $dark->value['--primary']);
    }

    public function test_branding_update_filters_disallowed_css_variables(): void
    {
        $this->actingAs($this->admin)
            ->post('/settings/branding', [
                'theme' => [
                    'light' => [
                        '--primary' => '220 90% 56%',
                        '--evil-var' => 'malicious',
                    ],
                ],
            ])
            ->assertRedirect();

        $light = AppSetting::where('key', 'theme.light')->first();
        $this->assertNotNull($light);
        $this->assertArrayHasKey('--primary', $light->value);
        $this->assertArrayNotHasKey('--evil-var', $light->value);
    }

    public function test_branding_update_uploads_logo(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin)
            ->post('/settings/branding', [
                'logo' => UploadedFile::fake()->image('logo.png', 200, 200)->size(500),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $logoPath = AppSetting::where('key', 'branding.logo_path')->first();
        $this->assertNotNull($logoPath);
        Storage::disk('public')->assertExists($logoPath->value);
    }

    public function test_branding_update_removes_logo(): void
    {
        Storage::fake('public');

        // First upload a logo
        $path = UploadedFile::fake()->image('logo.png', 100, 100)->store('branding', 'public');
        AppSetting::create(['key' => 'branding.logo_path', 'value' => $path]);

        $this->actingAs($this->admin)
            ->post('/settings/branding', [
                'remove_logo' => true,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('app_settings', ['key' => 'branding.logo_path']);
    }

    public function test_branding_update_rejects_oversized_logo(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin)
            ->post('/settings/branding', [
                'logo' => UploadedFile::fake()->image('logo.png', 200, 200)->size(3000), // > 2048KB
            ])
            ->assertSessionHasErrors(['logo']);
    }

    public function test_branding_update_rejects_non_image_logo(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin)
            ->post('/settings/branding', [
                'logo' => UploadedFile::fake()->create('document.pdf', 100, 'application/pdf'),
            ])
            ->assertSessionHasErrors(['logo']);
    }

    public function test_branding_update_validates_name_max_length(): void
    {
        $this->actingAs($this->admin)
            ->post('/settings/branding', [
                'branding' => ['name' => str_repeat('X', 81)],
            ])
            ->assertSessionHasErrors(['branding.name']);
    }

    // ========================================================================
    // SERVICE CONTEXTS
    // ========================================================================

    public function test_service_context_index_renders_for_admin(): void
    {
        $this->actingAs($this->admin)
            ->get('/settings/service-contexts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/service-contexts')
                ->has('contexts')
                ->has('types')
                ->has('sites')
            );
    }

    public function test_service_context_store_creates_context(): void
    {
        $this->actingAs($this->admin)
            ->post('/settings/service-contexts', [
                'name' => 'New Residential',
                'type' => 'residential',
                'description' => 'A new residential context',
                'is_active' => true,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('service_contexts', [
            'name' => 'New Residential',
            'type' => 'residential',
        ]);
    }

    public function test_service_context_store_with_site(): void
    {
        $site = Site::factory()->create();

        $this->actingAs($this->admin)
            ->post('/settings/service-contexts', [
                'name' => 'Site Linked Context',
                'type' => 'home_support',
                'site_id' => $site->id,
                'is_active' => true,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('service_contexts', [
            'name' => 'Site Linked Context',
            'site_id' => $site->id,
        ]);
    }

    public function test_service_context_store_validates_name_required(): void
    {
        $this->actingAs($this->admin)
            ->post('/settings/service-contexts', [
                'name' => '',
                'type' => 'residential',
            ])
            ->assertSessionHasErrors(['name']);
    }

    public function test_service_context_store_validates_type_required(): void
    {
        $this->actingAs($this->admin)
            ->post('/settings/service-contexts', [
                'name' => 'Test',
                'type' => '',
            ])
            ->assertSessionHasErrors(['type']);
    }

    public function test_service_context_store_validates_type_enum(): void
    {
        $this->actingAs($this->admin)
            ->post('/settings/service-contexts', [
                'name' => 'Test',
                'type' => 'invalid_type',
            ])
            ->assertSessionHasErrors(['type']);
    }

    public function test_service_context_store_validates_site_exists(): void
    {
        $this->actingAs($this->admin)
            ->post('/settings/service-contexts', [
                'name' => 'Test',
                'type' => 'residential',
                'site_id' => 99999,
            ])
            ->assertSessionHasErrors(['site_id']);
    }

    public function test_service_context_update_modifies_context(): void
    {
        $context = ServiceContext::factory()->create(['name' => 'Old Name', 'type' => 'residential']);

        $this->actingAs($this->admin)
            ->put("/settings/service-contexts/{$context->id}", [
                'name' => 'Updated Name',
                'type' => 'home_support',
                'is_active' => true,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('service_contexts', [
            'id' => $context->id,
            'name' => 'Updated Name',
            'type' => 'home_support',
        ]);
    }

    public function test_service_context_update_can_deactivate(): void
    {
        $context = ServiceContext::factory()->create(['is_active' => true]);

        $this->actingAs($this->admin)
            ->put("/settings/service-contexts/{$context->id}", [
                'name' => $context->name,
                'type' => $context->type->value,
                'is_active' => false,
            ])
            ->assertRedirect();

        $context->refresh();
        $this->assertFalse($context->is_active);
    }

    public function test_service_context_set_default_succeeds_for_active_context(): void
    {
        $context = ServiceContext::factory()->create(['is_active' => true]);

        $this->actingAs($this->admin)
            ->post('/settings/service-contexts/default', [
                'default_id' => $context->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('app_settings', [
            'key' => 'service_context.default_id',
        ]);

        $setting = AppSetting::where('key', 'service_context.default_id')->first();
        $this->assertEquals($context->id, $setting->value);
    }

    public function test_service_context_set_default_rejects_inactive_context(): void
    {
        $context = ServiceContext::factory()->create(['is_active' => false]);

        $this->actingAs($this->admin)
            ->post('/settings/service-contexts/default', [
                'default_id' => $context->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_service_context_set_default_clears_when_null(): void
    {
        AppSetting::create(['key' => 'service_context.default_id', 'value' => 1]);

        $this->actingAs($this->admin)
            ->post('/settings/service-contexts/default', [
                'default_id' => null,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('app_settings', ['key' => 'service_context.default_id']);
    }

    public function test_service_context_default_id_returns_null_for_inactive(): void
    {
        $context = ServiceContext::factory()->create(['is_active' => false]);
        AppSetting::create(['key' => 'service_context.default_id', 'value' => $context->id]);

        $this->assertNull(ServiceContext::defaultId());
    }

    // ========================================================================
    // NOTIFICATION PREFERENCES: user own
    // ========================================================================

    public function test_notification_preferences_index_renders(): void
    {
        $this->actingAs($this->admin)
            ->get('/settings/notifications')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/notifications')
                ->has('groups')
                ->has('userPrefs')
                ->has('roleDefaults')
                ->has('canManageRoleDefaults')
            );
    }

    public function test_notification_preferences_any_authenticated_user_can_view(): void
    {
        $this->actingAs($this->staff)
            ->get('/settings/notifications')
            ->assertOk();
    }

    public function test_notification_preferences_update_saves_user_prefs(): void
    {
        $this->actingAs($this->staff)
            ->put('/settings/notifications', [
                'prefs' => [
                    'timesheets.created' => true,
                    'timesheets.approved' => false,
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('user_notification_preferences', [
            'user_id' => $this->staff->id,
            'key' => 'timesheets.created',
            'enabled' => true,
        ]);

        $this->assertDatabaseHas('user_notification_preferences', [
            'user_id' => $this->staff->id,
            'key' => 'timesheets.approved',
            'enabled' => false,
        ]);
    }

    public function test_notification_preferences_update_validates_prefs_required(): void
    {
        $this->actingAs($this->staff)
            ->put('/settings/notifications', [])
            ->assertSessionHasErrors(['prefs']);
    }

    public function test_notification_preferences_update_validates_prefs_boolean(): void
    {
        $this->actingAs($this->staff)
            ->put('/settings/notifications', [
                'prefs' => ['timesheets.created' => 'not_a_boolean'],
            ])
            ->assertSessionHasErrors(['prefs.timesheets.created']);
    }

    public function test_notification_preferences_update_overwrites_existing(): void
    {
        UserNotificationPreference::create([
            'user_id' => $this->staff->id,
            'key' => 'timesheets.created',
            'enabled' => false,
        ]);

        $this->actingAs($this->staff)
            ->put('/settings/notifications', [
                'prefs' => ['timesheets.created' => true],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('user_notification_preferences', [
            'user_id' => $this->staff->id,
            'key' => 'timesheets.created',
            'enabled' => true,
        ]);

        // Should not create duplicate
        $this->assertEquals(1, UserNotificationPreference::where('user_id', $this->staff->id)->where('key', 'timesheets.created')->count());
    }

    // ========================================================================
    // NOTIFICATION PREFERENCES: role defaults (admin only)
    // ========================================================================

    public function test_notification_role_defaults_renders_for_admin(): void
    {
        $this->actingAs($this->admin)
            ->get('/settings/notifications/roles')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/notification-defaults')
                ->has('groups')
                ->has('roles')
                ->has('matrix')
            );
    }

    public function test_notification_role_defaults_update_saves_matrix(): void
    {
        $role = Role::where('name', 'coordinator')->first();

        $this->actingAs($this->admin)
            ->put('/settings/notifications/roles', [
                'matrix' => [
                    $role->id => [
                        'timesheets.created' => true,
                        'timesheets.approved' => false,
                    ],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('role_notification_preferences', [
            'role_id' => $role->id,
            'key' => 'timesheets.created',
            'enabled' => true,
        ]);

        $this->assertDatabaseHas('role_notification_preferences', [
            'role_id' => $role->id,
            'key' => 'timesheets.approved',
            'enabled' => false,
        ]);
    }

    public function test_notification_role_defaults_update_validates_matrix_required(): void
    {
        $this->actingAs($this->admin)
            ->put('/settings/notifications/roles', [])
            ->assertSessionHasErrors(['matrix']);
    }

    public function test_notification_role_defaults_overwrites_existing(): void
    {
        $role = Role::where('name', 'coordinator')->first();

        RoleNotificationPreference::create([
            'role_id' => $role->id,
            'key' => 'timesheets.created',
            'enabled' => false,
        ]);

        $this->actingAs($this->admin)
            ->put('/settings/notifications/roles', [
                'matrix' => [
                    $role->id => ['timesheets.created' => true],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('role_notification_preferences', [
            'role_id' => $role->id,
            'key' => 'timesheets.created',
            'enabled' => true,
        ]);
    }

    // ========================================================================
    // NOTIFICATION ESCALATION RULES
    // ========================================================================

    public function test_escalations_index_renders_for_admin(): void
    {
        $this->actingAs($this->admin)
            ->get('/settings/notifications/escalations')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/notification-escalations')
                ->has('groups')
                ->has('rules')
                ->has('availableRoleGroups')
            );
    }

    public function test_escalations_update_saves_rules(): void
    {
        $this->actingAs($this->admin)
            ->put('/settings/notifications/escalations', [
                'rules' => [
                    'timesheets.submitted' => [
                        'enabled' => true,
                        'require_ack' => true,
                        'must_ack_before_close' => true,
                        'force_delivery' => false,
                        'remind_after_minutes' => 30,
                        'repeat_every_minutes' => 15,
                        'max_reminders' => 5,
                        'escalate_to_role_groups' => ['managers', 'coordinators'],
                        'tiers' => [
                            ['from_reminder' => 3, 'role_groups' => ['managers']],
                        ],
                    ],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('notification_escalation_rules', [
            'event_key' => 'timesheets.submitted',
            'enabled' => true,
            'require_ack' => true,
            'must_ack_before_close' => true,
            'force_delivery' => false,
            'remind_after_minutes' => 30,
            'repeat_every_minutes' => 15,
            'max_reminders' => 5,
        ]);

        $rule = NotificationEscalationRule::where('event_key', 'timesheets.submitted')->first();
        $this->assertContains('managers', $rule->escalate_to_role_groups);
        $this->assertContains('coordinators', $rule->escalate_to_role_groups);
        $this->assertCount(1, $rule->tiers);
        $this->assertEquals(3, $rule->tiers[0]['from_reminder']);
    }

    public function test_escalations_update_must_ack_before_close_requires_require_ack(): void
    {
        $this->actingAs($this->admin)
            ->put('/settings/notifications/escalations', [
                'rules' => [
                    'timesheets.submitted' => [
                        'enabled' => true,
                        'require_ack' => false,
                        'must_ack_before_close' => true, // should be forced to false
                        'force_delivery' => false,
                        'remind_after_minutes' => 60,
                        'repeat_every_minutes' => 60,
                        'max_reminders' => 3,
                        'escalate_to_role_groups' => [],
                        'tiers' => [],
                    ],
                ],
            ])
            ->assertRedirect();

        $rule = NotificationEscalationRule::where('event_key', 'timesheets.submitted')->first();
        $this->assertFalse($rule->must_ack_before_close);
    }

    public function test_escalations_update_enforces_minimum_minutes(): void
    {
        $this->actingAs($this->admin)
            ->put('/settings/notifications/escalations', [
                'rules' => [
                    'incidents.submitted' => [
                        'enabled' => true,
                        'require_ack' => false,
                        'must_ack_before_close' => false,
                        'force_delivery' => false,
                        'remind_after_minutes' => -5,  // should become 1
                        'repeat_every_minutes' => 0,   // should become 1
                        'max_reminders' => -1,          // should become 0
                        'escalate_to_role_groups' => [],
                        'tiers' => [],
                    ],
                ],
            ])
            ->assertRedirect();

        $rule = NotificationEscalationRule::where('event_key', 'incidents.submitted')->first();
        $this->assertGreaterThanOrEqual(1, $rule->remind_after_minutes);
        $this->assertGreaterThanOrEqual(1, $rule->repeat_every_minutes);
        $this->assertGreaterThanOrEqual(0, $rule->max_reminders);
    }

    public function test_escalations_update_filters_invalid_tiers(): void
    {
        $this->actingAs($this->admin)
            ->put('/settings/notifications/escalations', [
                'rules' => [
                    'incidents.submitted' => [
                        'enabled' => true,
                        'require_ack' => false,
                        'must_ack_before_close' => false,
                        'force_delivery' => false,
                        'remind_after_minutes' => 60,
                        'repeat_every_minutes' => 60,
                        'max_reminders' => 3,
                        'escalate_to_role_groups' => [],
                        'tiers' => [
                            ['from_reminder' => 0, 'role_groups' => ['managers']], // invalid: from_reminder <= 0
                            ['from_reminder' => 2, 'role_groups' => []], // invalid: empty role_groups
                            ['from_reminder' => 3, 'role_groups' => ['coordinators']], // valid
                        ],
                    ],
                ],
            ])
            ->assertRedirect();

        $rule = NotificationEscalationRule::where('event_key', 'incidents.submitted')->first();
        $this->assertCount(1, $rule->tiers);
        $this->assertEquals(3, $rule->tiers[0]['from_reminder']);
    }

    public function test_escalations_update_validates_rules_required(): void
    {
        $this->actingAs($this->admin)
            ->put('/settings/notifications/escalations', [])
            ->assertSessionHasErrors(['rules']);
    }

    public function test_escalations_update_overwrites_existing_rule(): void
    {
        NotificationEscalationRule::create([
            'event_key' => 'timesheets.created',
            'enabled' => false,
            'require_ack' => false,
            'must_ack_before_close' => false,
            'force_delivery' => false,
            'remind_after_minutes' => 60,
            'repeat_every_minutes' => 60,
            'max_reminders' => 3,
            'escalate_to_role_groups' => [],
            'tiers' => [],
        ]);

        $this->actingAs($this->admin)
            ->put('/settings/notifications/escalations', [
                'rules' => [
                    'timesheets.created' => [
                        'enabled' => true,
                        'require_ack' => true,
                        'must_ack_before_close' => false,
                        'force_delivery' => true,
                        'remind_after_minutes' => 30,
                        'repeat_every_minutes' => 30,
                        'max_reminders' => 10,
                        'escalate_to_role_groups' => ['auditors'],
                        'tiers' => [],
                    ],
                ],
            ])
            ->assertRedirect();

        $rule = NotificationEscalationRule::where('event_key', 'timesheets.created')->first();
        $this->assertTrue($rule->enabled);
        $this->assertTrue($rule->require_ack);
        $this->assertTrue($rule->force_delivery);
        $this->assertEquals(30, $rule->remind_after_minutes);
        $this->assertEquals(10, $rule->max_reminders);
        $this->assertContains('auditors', $rule->escalate_to_role_groups);

        // Should not create duplicate
        $this->assertEquals(1, NotificationEscalationRule::where('event_key', 'timesheets.created')->count());
    }

    // ========================================================================
    // PROVIDER MANAGER: limited settings permissions
    // ========================================================================

    public function test_provider_manager_can_access_terminology(): void
    {
        $pm = User::factory()->create(['role' => 'provider_manager', 'approved_at' => now()]);
        $pm->roles()->attach(Role::where('name', 'provider_manager')->first());

        $this->actingAs($pm)->get('/settings/terminology')->assertOk();
    }

    public function test_provider_manager_can_access_service_contexts(): void
    {
        $pm = User::factory()->create(['role' => 'provider_manager', 'approved_at' => now()]);
        $pm->roles()->attach(Role::where('name', 'provider_manager')->first());

        $this->actingAs($pm)->get('/settings/service-contexts')->assertOk();
    }

    public function test_provider_manager_cannot_access_access_control(): void
    {
        $pm = User::factory()->create(['role' => 'provider_manager', 'approved_at' => now()]);
        $pm->roles()->attach(Role::where('name', 'provider_manager')->first());

        $this->actingAs($pm)->get('/settings/access')->assertForbidden();
    }

    public function test_provider_manager_cannot_access_branding(): void
    {
        $pm = User::factory()->create(['role' => 'provider_manager', 'approved_at' => now()]);
        $pm->roles()->attach(Role::where('name', 'provider_manager')->first());

        $this->actingAs($pm)->get('/settings/branding')->assertForbidden();
    }

    // ========================================================================
    // EDGE CASES & CROSS-CUTTING
    // ========================================================================

    public function test_settings_root_redirects_to_profile(): void
    {
        $this->actingAs($this->admin)
            ->get('/settings')
            ->assertRedirect('/settings/profile');
    }

    public function test_access_update_syncs_legacy_role_column(): void
    {
        $target = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $coordinatorRole = Role::where('name', 'coordinator')->first();

        $this->actingAs($this->admin)
            ->put("/settings/access/{$target->id}", [
                'role_ids' => [$coordinatorRole->id],
                'overrides' => [],
            ])
            ->assertRedirect();

        $target->refresh();
        $this->assertEquals('coordinator', $target->role);
    }

    public function test_user_can_update_own_notification_prefs_regardless_of_role(): void
    {
        // Even a basic support worker can manage their own notification preferences
        $this->actingAs($this->staff)
            ->put('/settings/notifications', [
                'prefs' => ['incidents.submitted' => false],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('user_notification_preferences', [
            'user_id' => $this->staff->id,
            'key' => 'incidents.submitted',
            'enabled' => false,
        ]);
    }

    public function test_branding_theme_clears_when_all_values_empty(): void
    {
        AppSetting::create(['key' => 'theme.light', 'value' => ['--primary' => '220 90% 56%']]);

        $this->actingAs($this->admin)
            ->post('/settings/branding', [
                'theme' => [
                    'light' => ['--primary' => ''], // empty string should be filtered
                ],
            ])
            ->assertRedirect();

        // The theme.light row should be deleted since all values are empty
        $this->assertDatabaseMissing('app_settings', ['key' => 'theme.light']);
    }

    public function test_service_context_store_respite_type(): void
    {
        $this->actingAs($this->admin)
            ->post('/settings/service-contexts', [
                'name' => 'Emergency Respite',
                'type' => 'respite',
                'is_active' => true,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('service_contexts', [
            'name' => 'Emergency Respite',
            'type' => 'respite',
        ]);
    }

    public function test_multiple_escalation_rules_in_one_request(): void
    {
        $this->actingAs($this->admin)
            ->put('/settings/notifications/escalations', [
                'rules' => [
                    'timesheets.submitted' => [
                        'enabled' => true,
                        'require_ack' => true,
                        'must_ack_before_close' => false,
                        'force_delivery' => false,
                        'remind_after_minutes' => 30,
                        'repeat_every_minutes' => 30,
                        'max_reminders' => 5,
                        'escalate_to_role_groups' => ['managers'],
                        'tiers' => [],
                    ],
                    'incidents.high_severity_alert' => [
                        'enabled' => true,
                        'require_ack' => true,
                        'must_ack_before_close' => true,
                        'force_delivery' => true,
                        'remind_after_minutes' => 10,
                        'repeat_every_minutes' => 10,
                        'max_reminders' => 10,
                        'escalate_to_role_groups' => ['managers', 'coordinators'],
                        'tiers' => [
                            ['from_reminder' => 2, 'role_groups' => ['managers_core']],
                        ],
                    ],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('notification_escalation_rules', [
            'event_key' => 'timesheets.submitted',
            'enabled' => true,
        ]);

        $this->assertDatabaseHas('notification_escalation_rules', [
            'event_key' => 'incidents.high_severity_alert',
            'enabled' => true,
            'force_delivery' => true,
        ]);
    }

    public function test_role_edit_denied_for_support_worker(): void
    {
        $role = Role::where('name', 'coordinator')->first();

        $this->actingAs($this->staff)
            ->get("/settings/roles/{$role->id}/edit")
            ->assertForbidden();
    }

    public function test_role_update_denied_for_support_worker(): void
    {
        $role = Role::where('name', 'coordinator')->first();

        $this->actingAs($this->staff)
            ->put("/settings/roles/{$role->id}", [
                'name' => 'hacked',
                'label' => 'Hacked',
                'permission_keys' => [],
            ])
            ->assertForbidden();
    }

    public function test_service_context_update_denied_for_support_worker(): void
    {
        $context = ServiceContext::factory()->create();

        $this->actingAs($this->staff)
            ->put("/settings/service-contexts/{$context->id}", [
                'name' => 'Hacked',
                'type' => 'residential',
            ])
            ->assertForbidden();
    }

    public function test_service_context_set_default_denied_for_support_worker(): void
    {
        $context = ServiceContext::factory()->create(['is_active' => true]);

        $this->actingAs($this->staff)
            ->post('/settings/service-contexts/default', [
                'default_id' => $context->id,
            ])
            ->assertForbidden();
    }
}
