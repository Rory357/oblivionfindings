<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Client;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteControllerTest extends TestCase
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

    public function test_site_index_requires_authentication(): void
    {
        $this->get('/sites')->assertRedirect('/login');
    }

    public function test_site_index_accessible_by_admin(): void
    {
        $this->actingAs($this->admin)
            ->get('/sites')
            ->assertOk();
    }

    public function test_site_index_accessible_by_coordinator(): void
    {
        $this->actingAs($this->coordinator)
            ->get('/sites')
            ->assertOk();
    }

    public function test_site_index_blocked_for_support_worker(): void
    {
        $this->actingAs($this->supportWorker)
            ->get('/sites')
            ->assertForbidden();
    }

    // ──────────────────────────────────────
    // Index - Data
    // ──────────────────────────────────────

    public function test_site_index_returns_inertia_page(): void
    {
        $this->actingAs($this->admin)
            ->get('/sites')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('sites/index')
                ->has('sites')
                ->has('filters')
            );
    }

    public function test_site_index_lists_all_sites(): void
    {
        Site::factory()->count(5)->create();

        $this->actingAs($this->admin)
            ->get('/sites')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('sites', 5)
            );
    }

    public function test_site_index_filter_by_active_status(): void
    {
        Site::factory()->count(3)->create(['is_active' => true]);
        Site::factory()->count(2)->create(['is_active' => false]);

        $this->actingAs($this->admin)
            ->get('/sites?status=active')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('sites', 3)
            );
    }

    public function test_site_index_filter_by_inactive_status(): void
    {
        Site::factory()->count(3)->create(['is_active' => true]);
        Site::factory()->count(2)->create(['is_active' => false]);

        $this->actingAs($this->admin)
            ->get('/sites?status=inactive')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('sites', 2)
            );
    }

    public function test_site_index_all_status_shows_everything(): void
    {
        Site::factory()->count(3)->create(['is_active' => true]);
        Site::factory()->count(2)->create(['is_active' => false]);

        $this->actingAs($this->admin)
            ->get('/sites?status=all')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('sites', 5)
            );
    }

    // ──────────────────────────────────────
    // Show
    // ──────────────────────────────────────

    public function test_site_show_requires_authentication(): void
    {
        $site = Site::factory()->create();
        $this->get("/sites/{$site->id}")->assertRedirect('/login');
    }

    public function test_site_show_accessible_by_admin(): void
    {
        $site = Site::factory()->create();

        $this->actingAs($this->admin)
            ->get("/sites/{$site->id}")
            ->assertOk();
    }

    public function test_site_show_returns_inertia_page(): void
    {
        $site = Site::factory()->create();

        $this->actingAs($this->admin)
            ->get("/sites/{$site->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('sites/show')
                ->has('site')
                ->has('clients')
                ->has('contacts')
                ->has('documents')
                ->has('assets')
                ->has('checklist')
                ->has('can_edit')
                ->has('can')
            );
    }

    public function test_site_show_includes_checklist(): void
    {
        $site = Site::factory()->create();

        $this->actingAs($this->admin)
            ->get("/sites/{$site->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('checklist', 6)
            );
    }

    public function test_site_show_includes_linked_assets(): void
    {
        $site = Site::factory()->create();
        Asset::factory()->count(3)->forSite($site)->create();

        $this->actingAs($this->admin)
            ->get("/sites/{$site->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('assets', 3)
            );
    }

    public function test_site_show_returns_404_for_nonexistent_site(): void
    {
        $this->actingAs($this->admin)
            ->get('/sites/99999')
            ->assertNotFound();
    }

    // ──────────────────────────────────────
    // Create
    // ──────────────────────────────────────

    public function test_site_create_requires_authentication(): void
    {
        $this->get('/sites/create')->assertRedirect('/login');
    }

    public function test_site_create_accessible_by_admin(): void
    {
        $this->actingAs($this->admin)
            ->get('/sites/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('sites/create')
            );
    }

    public function test_site_create_blocked_for_support_worker(): void
    {
        $this->actingAs($this->supportWorker)
            ->get('/sites/create')
            ->assertForbidden();
    }

    // ──────────────────────────────────────
    // Store
    // ──────────────────────────────────────

    public function test_site_store_creates_site(): void
    {
        $this->actingAs($this->admin)
            ->post('/sites', [
                'name' => 'New Care Home',
                'is_active' => true,
            ])
            ->assertRedirect(route('sites.index'));

        $this->assertDatabaseHas('sites', ['name' => 'New Care Home']);
    }

    public function test_site_store_accepts_residential_type(): void
    {
        $this->actingAs($this->admin)
            ->post('/sites', [
                'name' => 'Residential Home',
                'type' => 'residential',
                'is_active' => true,
            ])
            ->assertRedirect(route('sites.index'));

        $this->assertDatabaseHas('sites', [
            'name' => 'Residential Home',
            'type' => 'residential',
        ]);
    }

    public function test_site_store_validates_required_name(): void
    {
        $this->actingAs($this->admin)
            ->post('/sites', [
                'is_active' => true,
            ])
            ->assertSessionHasErrors(['name']);
    }

    public function test_site_store_validates_required_is_active(): void
    {
        $this->actingAs($this->admin)
            ->post('/sites', [
                'name' => 'Test Site',
            ])
            ->assertSessionHasErrors(['is_active']);
    }

    public function test_site_store_with_full_details(): void
    {
        $this->actingAs($this->admin)
            ->post('/sites', [
                'name' => 'Full Details Home',
                'phone' => '09 123 4567',
                'email' => 'home@example.com',
                'manager_name' => 'Jane Manager',
                'manager_phone' => '021 987 6543',
                'after_hours_phone' => '0800 111 222',
                'emergency_plan_location' => 'Reception desk',
                'medication_storage_location' => 'Locked cabinet, office',
                'notes' => 'Test notes for the site',
                'address_line_1' => '123 Test Street',
                'suburb' => 'Testville',
                'city' => 'Auckland',
                'postcode' => '1010',
                'country' => 'New Zealand',
                'is_active' => true,
            ])
            ->assertRedirect(route('sites.index'));

        $this->assertDatabaseHas('sites', [
            'name' => 'Full Details Home',
            'phone' => '09 123 4567',
            'email' => 'home@example.com',
            'city' => 'Auckland',
        ]);
    }

    public function test_site_store_blocked_for_support_worker(): void
    {
        $this->actingAs($this->supportWorker)
            ->post('/sites', [
                'name' => 'New Site',
                'is_active' => true,
            ])
            ->assertForbidden();
    }

    // ──────────────────────────────────────
    // Edit
    // ──────────────────────────────────────

    public function test_site_edit_accessible_by_admin(): void
    {
        $site = Site::factory()->create();

        $this->actingAs($this->admin)
            ->get("/sites/{$site->id}/edit")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('sites/edit')
                ->has('site')
            );
    }

    public function test_site_edit_blocked_for_support_worker(): void
    {
        $site = Site::factory()->create();

        $this->actingAs($this->supportWorker)
            ->get("/sites/{$site->id}/edit")
            ->assertForbidden();
    }

    // ──────────────────────────────────────
    // Update
    // ──────────────────────────────────────

    public function test_site_update_successful(): void
    {
        $site = Site::factory()->create(['name' => 'Old Name']);

        $this->actingAs($this->admin)
            ->put("/sites/{$site->id}", [
                'name' => 'Updated Name',
                'is_active' => true,
            ])
            ->assertRedirect(route('sites.index'));

        $site->refresh();
        $this->assertEquals('Updated Name', $site->name);
    }

    public function test_site_update_validates_required_fields(): void
    {
        $site = Site::factory()->create();

        $this->actingAs($this->admin)
            ->put("/sites/{$site->id}", [])
            ->assertSessionHasErrors(['name', 'is_active']);
    }

    public function test_site_update_blocked_for_support_worker(): void
    {
        $site = Site::factory()->create();

        $this->actingAs($this->supportWorker)
            ->put("/sites/{$site->id}", [
                'name' => 'Hacked',
                'is_active' => true,
            ])
            ->assertForbidden();
    }

    public function test_site_update_can_deactivate_site(): void
    {
        $site = Site::factory()->create(['is_active' => true]);

        $this->actingAs($this->admin)
            ->put("/sites/{$site->id}", [
                'name' => $site->name,
                'is_active' => false,
            ])
            ->assertRedirect(route('sites.index'));

        $site->refresh();
        $this->assertFalse((bool) $site->is_active);
    }
}
