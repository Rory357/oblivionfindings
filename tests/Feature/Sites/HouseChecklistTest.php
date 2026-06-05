<?php

namespace Tests\Feature\Sites;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The standalone house-checklists screen was retired and folded into the
 * unified Checklists workspace. Its URL now permanently redirects so old
 * bookmarks keep working.
 */
class HouseChecklistTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Site $houseSite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RbacSeeder::class);

        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->houseSite = Site::factory()->create(['type' => 'house']);
    }

    public function test_house_checklists_route_requires_authentication(): void
    {
        $this->get("/sites/{$this->houseSite->id}/house-checklists")->assertRedirect('/login');
    }

    public function test_legacy_house_checklists_redirects_to_unified_page(): void
    {
        $this->actingAs($this->admin)
            ->get("/sites/{$this->houseSite->id}/house-checklists")
            ->assertRedirect("/sites/{$this->houseSite->id}/checklists");
    }

    public function test_retired_house_checklist_write_routes_are_gone(): void
    {
        // The old template/start/complete POST endpoints were removed entirely,
        // so their URIs no longer resolve to any route.
        $this->actingAs($this->admin)
            ->post("/sites/{$this->houseSite->id}/house-checklists/templates", [])
            ->assertNotFound();
    }
}
