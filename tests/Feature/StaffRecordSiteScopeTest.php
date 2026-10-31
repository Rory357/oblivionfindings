<?php

namespace Tests\Feature;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\StaffCredential;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Staff records (/staff/{user}) are limited to staff in the viewer's own
 * Sites (Stephan, 24 Oct 2026). A record in another Site reads as not found;
 * sites.viewAll sees every Site; everyone can open their own record. The
 * edit form and the save follow the same rule, and so do the staff list
 * (/staff), credentials (/staff/{user}/credentials) and client assignments
 * (/staff/{user}/assignments).
 */
class StaffRecordSiteScopeTest extends TestCase
{
    use RefreshDatabase;

    /** Everything the staff module offers, short of sites.viewAll. */
    private const MANAGER_PERMISSIONS = [
        'staff.viewAny',
        'staff.update',
        'staff.credentials.viewAny',
        'staff.credentials.updateAny',
        'staff.assignments.update',
    ];

    private Site $ownSite;

    private Site $otherSite;

    /** MANAGER_PERMISSIONS, no sites.viewAll, works at ownSite. */
    private User $manager;

    private Role $staffRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->ownSite = Site::factory()->create();
        $this->otherSite = Site::factory()->create();
        $this->staffRole = Role::where('name', 'support_worker')->firstOrFail();

        $manager = $this->roleWith('zz_site_staff_manager', self::MANAGER_PERMISSIONS);
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

    private function allSitesManager(): User
    {
        return $this->person(
            $this->roleWith('zz_all_sites_staff_manager', [...self::MANAGER_PERMISSIONS, 'sites.viewAll']),
            $this->ownSite,
        );
    }

    /** @return list<int> */
    private function listedIds(User $viewer, string $query = ''): array
    {
        return collect($this->actingAs($viewer)->get('/staff'.$query)->assertOk()->inertiaProps('users.data'))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
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

    // ── The staff list ────────────────────────────────────────────────

    public function test_the_staff_list_shows_staff_in_the_managers_sites_and_their_own_row(): void
    {
        $colleague = $this->staffAt($this->ownSite);
        $shared = $this->staffAt($this->otherSite, ['secondary_site_ids' => [$this->ownSite->id]]);
        $former = $this->staffAt($this->ownSite, [
            'is_active' => false,
            'end_date' => now()->subMonth()->toDateString(),
        ]);
        $elsewhere = $this->staffAt($this->otherSite);
        $noSite = $this->staffAt(null);

        $listed = $this->listedIds($this->manager);

        foreach ([$this->manager, $colleague, $shared, $former] as $visible) {
            $this->assertContains($visible->id, $listed);
        }
        $this->assertNotContains($elsewhere->id, $listed, 'Another Site’s staff are not listed.');
        $this->assertNotContains($noSite->id, $listed, 'Staff with no Site are not listed.');
    }

    public function test_a_manager_with_no_site_of_their_own_lists_only_themselves(): void
    {
        $noSiteManager = $this->person($this->roleWith('zz_no_site_staff_lister', ['staff.viewAny']), null);
        $this->staffAt($this->ownSite);
        $this->staffAt($this->otherSite);

        $this->assertSame([$noSiteManager->id], $this->listedIds($noSiteManager));
    }

    public function test_search_and_pagination_stay_inside_the_managers_sites(): void
    {
        foreach (range(1, 21) as $n) {
            $this->staffAt($this->ownSite)->forceFill(['name' => sprintf('Kahu Colleague %02d', $n)])->save();
        }
        $elsewhere = $this->staffAt($this->otherSite);
        $elsewhere->forceFill(['name' => 'Kahu Elsewhere'])->save();

        $first = $this->actingAs($this->manager)->get('/staff?q=Kahu')->assertOk();
        $this->assertSame(21, $first->inertiaProps('users.total'));
        $this->assertCount(20, $first->inertiaProps('users.data'));
        $this->assertSame('Kahu', $first->inertiaProps('filters.q'));

        $second = $this->actingAs($this->manager)->get('/staff?q=Kahu&page=2')->assertOk();
        $this->assertSame(
            ['Kahu Colleague 21'],
            collect($second->inertiaProps('users.data'))->pluck('name')->all(),
        );
        $this->assertNotContains($elsewhere->id, $this->listedIds($this->manager, '?q=Elsewhere'));
    }

    public function test_sites_view_all_lists_staff_at_every_site(): void
    {
        $everySite = $this->allSitesManager();
        $elsewhere = $this->staffAt($this->otherSite);
        $noSite = $this->staffAt(null);

        $listed = $this->listedIds($everySite);

        $this->assertContains($elsewhere->id, $listed);
        $this->assertContains($noSite->id, $listed);
    }

    // ── Credentials ───────────────────────────────────────────────────

    public function test_credentials_follow_the_same_site_rule(): void
    {
        $colleague = $this->staffAt($this->ownSite);
        $elsewhere = $this->staffAt($this->otherSite);
        $theirs = StaffCredential::create(['user_id' => $elsewhere->id, 'type' => 'First aid']);

        $this->actingAs($this->manager)->get("/staff/{$colleague->id}/credentials")->assertOk();
        $this->actingAs($this->manager)->get("/staff/{$elsewhere->id}/credentials")->assertNotFound();

        $this->actingAs($this->manager)
            ->post("/staff/{$elsewhere->id}/credentials", ['type' => 'Police vet'])
            ->assertNotFound();
        $this->actingAs($this->manager)
            ->put("/staff/{$elsewhere->id}/credentials/{$theirs->id}", ['type' => 'Changed elsewhere'])
            ->assertNotFound();
        $this->actingAs($this->manager)
            ->delete("/staff/{$elsewhere->id}/credentials/{$theirs->id}")
            ->assertNotFound();

        $this->assertSame(['First aid'], StaffCredential::where('user_id', $elsewhere->id)->pluck('type')->all());

        $this->actingAs($this->manager)
            ->post("/staff/{$colleague->id}/credentials", ['type' => 'Police vet'])
            ->assertRedirect();
        $this->assertTrue(StaffCredential::where('user_id', $colleague->id)->where('type', 'Police vet')->exists());
    }

    public function test_everyone_can_manage_their_own_credentials_whatever_their_sites(): void
    {
        $noSiteWorker = $this->staffAt(null);

        $this->actingAs($noSiteWorker)->get("/staff/{$noSiteWorker->id}/credentials")->assertOk();
        $this->actingAs($noSiteWorker)
            ->post("/staff/{$noSiteWorker->id}/credentials", ['type' => 'Driver licence'])
            ->assertRedirect();

        $this->assertTrue(StaffCredential::where('user_id', $noSiteWorker->id)->where('type', 'Driver licence')->exists());
    }

    public function test_sites_view_all_opens_credentials_at_every_site(): void
    {
        $elsewhere = $this->staffAt($this->otherSite);

        $this->actingAs($this->allSitesManager())
            ->get("/staff/{$elsewhere->id}/credentials")
            ->assertOk();
    }

    // ── Client assignments ────────────────────────────────────────────

    public function test_client_assignments_follow_the_same_site_rule(): void
    {
        $colleague = $this->staffAt($this->ownSite);
        $elsewhere = $this->staffAt($this->otherSite);
        $client = Client::factory()->create(['site_id' => $this->ownSite->id]);
        $elsewhere->assignedClients()->attach($client->id);

        $this->actingAs($this->manager)->get("/staff/{$colleague->id}/assignments")->assertOk();
        $this->actingAs($this->manager)->get("/staff/{$elsewhere->id}/assignments")->assertNotFound();

        $this->actingAs($this->manager)
            ->put("/staff/{$elsewhere->id}/assignments", ['client_ids' => []])
            ->assertNotFound();
        $this->assertTrue($elsewhere->assignedClients()->whereKey($client->id)->exists());

        $this->actingAs($this->manager)
            ->put("/staff/{$colleague->id}/assignments", ['client_ids' => [$client->id]])
            ->assertRedirect();
        $this->assertTrue($colleague->assignedClients()->whereKey($client->id)->exists());
    }

    public function test_sites_view_all_opens_client_assignments_at_every_site(): void
    {
        $elsewhere = $this->staffAt($this->otherSite);

        $this->actingAs($this->allSitesManager())
            ->get("/staff/{$elsewhere->id}/assignments")
            ->assertOk();
    }

    // ── Clients offered on the assignments page (Stephan, 25 Oct 2026) ──

    public function test_assignments_page_lists_only_clients_at_the_viewers_sites(): void
    {
        $colleague = $this->staffAt($this->ownSite);
        $own = Client::factory()->create(['site_id' => $this->ownSite->id]);
        $other = Client::factory()->create(['site_id' => $this->otherSite->id]);
        $siteless = Client::factory()->create(['site_id' => null]);
        $colleague->assignedClients()->attach([$own->id, $other->id]);

        $response = $this->actingAs($this->manager)->get("/staff/{$colleague->id}/assignments")->assertOk();
        $listed = collect($response->inertiaProps('clients'))->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertContains($own->id, $listed);
        $this->assertNotContains($other->id, $listed);
        $this->assertNotContains($siteless->id, $listed);
        $this->assertSame([$own->id], collect($response->inertiaProps('assignedIds'))->map(fn ($id) => (int) $id)->all());
    }

    public function test_assignments_refuse_a_client_at_another_site(): void
    {
        $colleague = $this->staffAt($this->ownSite);
        $other = Client::factory()->create(['site_id' => $this->otherSite->id]);

        $this->actingAs($this->manager)
            ->put("/staff/{$colleague->id}/assignments", ['client_ids' => [$other->id]])
            ->assertSessionHasErrors('client_ids');
        $this->assertFalse($colleague->assignedClients()->whereKey($other->id)->exists());
    }

    public function test_saving_keeps_assignments_at_sites_the_viewer_cannot_see(): void
    {
        $colleague = $this->staffAt($this->ownSite);
        $own = Client::factory()->create(['site_id' => $this->ownSite->id]);
        $other = Client::factory()->create(['site_id' => $this->otherSite->id]);
        $colleague->assignedClients()->attach([$own->id, $other->id]);

        $this->actingAs($this->manager)
            ->put("/staff/{$colleague->id}/assignments", ['client_ids' => []])
            ->assertRedirect();

        $this->assertFalse($colleague->assignedClients()->whereKey($own->id)->exists());
        $this->assertTrue($colleague->assignedClients()->whereKey($other->id)->exists());
    }

    public function test_sites_view_all_lists_and_assigns_clients_at_every_site(): void
    {
        $colleague = $this->staffAt($this->otherSite);
        $other = Client::factory()->create(['site_id' => $this->otherSite->id]);
        $siteless = Client::factory()->create(['site_id' => null]);
        $viewer = $this->allSitesManager();

        $listed = collect($this->actingAs($viewer)->get("/staff/{$colleague->id}/assignments")->assertOk()->inertiaProps('clients'))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains($other->id, $listed);
        $this->assertContains($siteless->id, $listed);

        $this->actingAs($viewer)
            ->put("/staff/{$colleague->id}/assignments", ['client_ids' => [$other->id, $siteless->id]])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing([$other->id, $siteless->id], $colleague->assignedClients()->pluck('clients.id')->map(fn ($id) => (int) $id)->all());
    }
}
