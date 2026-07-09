<?php

namespace Tests\Feature\Sites;

use App\Models\Role;
use App\Models\Site;
use App\Models\SiteDamage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteDamageTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $supportWorker;
    protected Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RbacSeeder::class);

        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->supportWorker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $this->supportWorker->roles()->attach(Role::where('name', 'support_worker')->first());

        $this->site = Site::factory()->create(['type' => 'house']);
    }

    public function test_damages_index_requires_authentication(): void
    {
        $this->get("/sites/{$this->site->id}/damages")->assertRedirect('/login');
    }

    public function test_admin_can_view_damages(): void
    {
        $this->actingAs($this->admin)
            ->get("/sites/{$this->site->id}/damages")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('sites/damages/index')
                ->has('site')
                ->has('damages')
            );
    }

    public function test_admin_can_create_damage_report(): void
    {
        $this->actingAs($this->admin)
            ->post("/sites/{$this->site->id}/damages", [
                'title' => 'Broken window',
                'description' => 'Window in bedroom 2 was broken during storm.',
                'severity' => 'moderate',
                'damage_date' => '2026-02-18',
                'discovered_date' => '2026-02-18',
                'estimated_cost' => 500.00,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('site_damages', [
            'site_id' => $this->site->id,
            'title' => 'Broken window',
            'severity' => 'moderate',
            'status' => 'reported',
            'reported_by' => $this->admin->id,
        ]);
    }

    public function test_creating_damage_report_inherits_tenant_id_from_site_when_omitted(): void
    {
        $site = Site::factory()->create(['tenant_id' => 99]);

        $damage = SiteDamage::create([
            'site_id' => $site->id,
            'reported_by' => $this->admin->id,
            'title' => 'Cracked window',
            'description' => 'Window in bedroom 2 was cracked during storm.',
            'severity' => 'moderate',
            'status' => 'reported',
            'damage_date' => '2026-02-18',
            'discovered_date' => '2026-02-18',
        ]);

        $this->assertSame(99, $damage->tenant_id);
    }

    public function test_admin_can_update_damage_report(): void
    {
        $damage = SiteDamage::create([
            'site_id' => $this->site->id,
            'reported_by' => $this->admin->id,
            'title' => 'Cracked wall',
            'description' => 'Crack in lounge wall.',
            'severity' => 'minor',
            'status' => 'reported',
            'damage_date' => '2026-02-18',
            'discovered_date' => '2026-02-18',
        ]);

        $this->actingAs($this->admin)
            ->put("/sites/{$this->site->id}/damages/{$damage->id}", [
                'status' => 'assessed',
                'estimated_cost' => 200.00,
            ])
            ->assertRedirect();

        $damage->refresh();
        $this->assertEquals('assessed', $damage->status);
        $this->assertEquals(200.00, $damage->estimated_cost);
    }

    public function test_marking_repaired_sets_repaired_at(): void
    {
        $damage = SiteDamage::create([
            'site_id' => $this->site->id,
            'reported_by' => $this->admin->id,
            'title' => 'Leaking tap',
            'description' => 'Kitchen tap leaking.',
            'severity' => 'minor',
            'status' => 'repair_in_progress',
            'damage_date' => '2026-02-18',
            'discovered_date' => '2026-02-18',
        ]);

        $this->actingAs($this->admin)
            ->put("/sites/{$this->site->id}/damages/{$damage->id}", [
                'status' => 'repaired',
            ])
            ->assertRedirect();

        $damage->refresh();
        $this->assertEquals('repaired', $damage->status);
        $this->assertNotNull($damage->repaired_at);
        $this->assertEquals($this->admin->id, $damage->repaired_by);
    }

    public function test_admin_can_soft_delete_damage(): void
    {
        $damage = SiteDamage::create([
            'site_id' => $this->site->id,
            'reported_by' => $this->admin->id,
            'title' => 'Test damage',
            'description' => 'To be deleted.',
            'severity' => 'minor',
            'status' => 'reported',
            'damage_date' => '2026-02-18',
            'discovered_date' => '2026-02-18',
        ]);

        $this->actingAs($this->admin)
            ->delete("/sites/{$this->site->id}/damages/{$damage->id}")
            ->assertRedirect();

        $this->assertSoftDeleted('site_damages', ['id' => $damage->id]);
    }

    public function test_support_worker_can_report_site_damage(): void
    {
        // Frontline support workers are on-site and are expected to report damage
        // they discover. The role holds sites.viewAny (the site route-group gate)
        // and sites.damages.create (SiteDamagePolicy::create), so the report is
        // accepted. This previously asserted a 403; support_worker gained
        // sites.viewAny with the My Day feature (2026-05-23), which — combined with
        // the sites.damages.create it has always held — makes the old block obsolete.
        $this->assertTrue($this->supportWorker->canDo('sites.damages.create'));

        $this->actingAs($this->supportWorker)
            ->post("/sites/{$this->site->id}/damages", [
                'title' => 'Dented door',
                'description' => 'Front door has a dent.',
                'severity' => 'minor',
                'damage_date' => '2026-02-19',
                'discovered_date' => '2026-02-19',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('site_damages', [
            'site_id' => $this->site->id,
            'title' => 'Dented door',
            'severity' => 'minor',
            'status' => 'reported',
            'reported_by' => $this->supportWorker->id,
        ]);
    }

    public function test_unauthorized_user_blocked_by_site_middleware(): void
    {
        // The sites/{site} route group is gated by permission:sites.viewAny. A
        // portal-only user (next_of_kin) lacks that permission, so the group
        // middleware abort(403)s before the request reaches the controller — and
        // no damage row is written.
        $portalUser = User::factory()->create(['role' => 'next_of_kin', 'approved_at' => now()]);
        $portalUser->roles()->attach(Role::where('name', 'next_of_kin')->first());

        $this->assertFalse($portalUser->canDo('sites.viewAny'));

        $this->actingAs($portalUser)
            ->post("/sites/{$this->site->id}/damages", [
                'title' => 'Dented door',
                'description' => 'Front door has a dent.',
                'severity' => 'minor',
                'damage_date' => '2026-02-19',
                'discovered_date' => '2026-02-19',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('site_damages', 0);
    }
}
