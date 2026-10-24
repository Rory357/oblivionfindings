<?php

namespace Tests\Feature;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Staff records (/staff/{user}) are limited to staff in the viewer's own
 * Sites (Stephan, 24 Oct 2026). A record in another Site reads as not found;
 * sites.viewAll sees every Site; everyone can open their own record. The
 * edit form and the save follow the same rule.
 */
class StaffRecordSiteScopeTest extends TestCase
{
    use RefreshDatabase;

    private Site $ownSite;

    private Site $otherSite;

    /** staff.viewAny + staff.update, no sites.viewAll, works at ownSite. */
    private User $manager;

    private Role $staffRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->ownSite = Site::factory()->create();
        $this->otherSite = Site::factory()->create();
        $this->staffRole = Role::where('name', 'support_worker')->firstOrFail();

        $manager = $this->roleWith('zz_site_staff_manager', ['staff.viewAny', 'staff.update']);
        $this->manager = $this->person($manager, $this->ownSite);

        $this->assertTrue($this->manager->canDo('staff.viewAny'));
        $this->assertFalse($this->manager->canDo('sites.viewAll'));
    }

    /** @param  list<string>  $permissionKeys */
    private function roleWith(string $name, array $permissionKeys): Role
    {
        $role = Role::create(['name' => $name, 'label' => $name]);
        $role->permissions()->sync(Permission::whereIn('key', $permissionKeys)->pluck('id'));

        return $role;
    }

    /** @param  array<string, mixed>  $profile */
    private function person(Role $role, ?Site $site, array $profile = []): User
    {
        $user = User::factory()->create(['role' => $role->name, 'approved_at' => now()]);
        $user->roles()->attach($role);

        if ($site) {
            HrEmployeeProfile::factory()->create(array_merge([
                'user_id' => $user->id,
                'primary_site_id' => $site->id,
                'is_active' => true,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ], $profile));
        }

        return $user;
    }

    private function staffAt(?Site $site, array $profile = []): User
    {
        return $this->person($this->staffRole, $site, $profile);
    }

    // ── Opening a record ──────────────────────────────────────────────

    public function test_a_manager_opens_a_staff_record_in_their_own_site(): void
    {
        $colleague = $this->staffAt($this->ownSite);

        $this->actingAs($this->manager)
            ->get("/staff/{$colleague->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('staff/show')
                ->where('user.id', $colleague->id));
    }

    public function test_a_staff_record_in_another_site_reads_as_not_found(): void
    {
        $elsewhere = $this->staffAt($this->otherSite);

        $this->actingAs($this->manager)
            ->get("/staff/{$elsewhere->id}")
            ->assertNotFound();
    }

    public function test_a_second_site_shared_with_the_manager_counts(): void
    {
        $shared = $this->staffAt($this->otherSite, ['secondary_site_ids' => [$this->ownSite->id]]);

        $this->actingAs($this->manager)
            ->get("/staff/{$shared->id}")
            ->assertOk();
    }

    public function test_a_former_staff_member_of_the_managers_site_stays_readable(): void
    {
        $former = $this->staffAt($this->ownSite, [
            'is_active' => false,
            'end_date' => now()->subMonth()->toDateString(),
        ]);

        $this->actingAs($this->manager)
            ->get("/staff/{$former->id}")
            ->assertOk();
    }

    public function test_a_staff_member_with_no_site_is_not_found_for_a_site_limited_manager(): void
    {
        $noSite = $this->staffAt(null);

        $this->actingAs($this->manager)
            ->get("/staff/{$noSite->id}")
            ->assertNotFound();
    }

    public function test_sites_view_all_opens_staff_records_at_every_site(): void
    {
        $everySite = $this->person(
            $this->roleWith('zz_all_sites_staff_viewer', ['staff.viewAny', 'sites.viewAll']),
            $this->ownSite,
        );
        $elsewhere = $this->staffAt($this->otherSite);
        $noSite = $this->staffAt(null);

        $this->actingAs($everySite)->get("/staff/{$elsewhere->id}")->assertOk();
        $this->actingAs($everySite)->get("/staff/{$noSite->id}")->assertOk();
    }

    public function test_everyone_can_open_their_own_record_whatever_their_sites(): void
    {
        $noSiteManager = $this->person(
            $this->roleWith('zz_no_site_staff_viewer', ['staff.viewAny']),
            null,
        );
        $noSiteWorker = $this->staffAt(null);

        $this->actingAs($noSiteManager)->get("/staff/{$noSiteManager->id}")->assertOk();
        $this->actingAs($noSiteWorker)->get("/staff/{$noSiteWorker->id}")->assertOk();
        $this->actingAs($this->manager)->get("/staff/{$this->manager->id}")->assertOk();
    }

    public function test_a_family_portal_user_is_still_not_found(): void
    {
        $portalUser = User::factory()->create(['role' => 'client', 'approved_at' => now()]);
        $portalUser->roles()->attach(Role::where('name', 'client')->firstOrFail());

        $this->actingAs($this->manager)
            ->get("/staff/{$portalUser->id}")
            ->assertNotFound();
    }

    public function test_without_the_staff_permission_another_record_is_still_forbidden(): void
    {
        $worker = $this->staffAt($this->ownSite);
        $colleague = $this->staffAt($this->ownSite);

        $this->actingAs($worker)
            ->get("/staff/{$colleague->id}")
            ->assertForbidden();
    }

    // ── Editing a record ──────────────────────────────────────────────

    public function test_the_edit_form_follows_the_same_site_rule(): void
    {
        $colleague = $this->staffAt($this->ownSite);
        $elsewhere = $this->staffAt($this->otherSite);

        $this->actingAs($this->manager)->get("/staff/{$colleague->id}/edit")->assertOk();
        $this->actingAs($this->manager)->get("/staff/{$elsewhere->id}/edit")->assertNotFound();
    }

    public function test_saving_a_staff_record_in_another_site_is_not_found_and_changes_nothing(): void
    {
        $elsewhere = $this->staffAt($this->otherSite);
        $coordinator = Role::where('name', 'coordinator')->firstOrFail();

        $this->actingAs($this->manager)
            ->put("/staff/{$elsewhere->id}", [
                'name' => 'Changed elsewhere',
                'email' => 'changed.elsewhere@example.test',
                'role_ids' => [$coordinator->id],
            ])
            ->assertNotFound();

        $elsewhere->refresh();
        $this->assertNotSame('Changed elsewhere', $elsewhere->name);
        $this->assertNotSame('changed.elsewhere@example.test', $elsewhere->email);
        $this->assertFalse($elsewhere->roles()->whereKey($coordinator->id)->exists());
    }

    public function test_saving_a_staff_record_in_the_managers_own_site_works(): void
    {
        $colleague = $this->staffAt($this->ownSite);

        $this->actingAs($this->manager)
            ->put("/staff/{$colleague->id}", [
                'name' => 'Aroha Updated',
                'email' => 'aroha.updated@example.test',
            ])
            ->assertRedirect("/staff/{$colleague->id}");

        $this->assertSame('Aroha Updated', $colleague->refresh()->name);
    }

    public function test_sites_view_all_can_edit_and_save_staff_at_every_site(): void
    {
        $everySite = $this->person(
            $this->roleWith('zz_all_sites_staff_editor', ['staff.viewAny', 'staff.update', 'sites.viewAll']),
            $this->ownSite,
        );
        $elsewhere = $this->staffAt($this->otherSite);

        $this->actingAs($everySite)->get("/staff/{$elsewhere->id}/edit")->assertOk();
        $this->actingAs($everySite)
            ->put("/staff/{$elsewhere->id}", [
                'name' => 'Changed by head office',
                'email' => $elsewhere->email,
            ])
            ->assertRedirect("/staff/{$elsewhere->id}");

        $this->assertSame('Changed by head office', $elsewhere->refresh()->name);
    }
}
