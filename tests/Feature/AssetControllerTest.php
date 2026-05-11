<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Client;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $coordinator;
    protected User $supportWorker;
    protected Site $site;

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

        $this->site = Site::factory()->create();
    }

    // ──────────────────────────────────────
    // Index - Authentication & Authorization
    // ──────────────────────────────────────

    public function test_asset_index_requires_authentication(): void
    {
        $this->get('/assets')->assertRedirect('/login');
    }

    public function test_asset_index_accessible_by_admin(): void
    {
        $this->actingAs($this->admin)
            ->get('/assets')
            ->assertOk();
    }

    public function test_asset_index_accessible_by_coordinator(): void
    {
        $this->actingAs($this->coordinator)
            ->get('/assets')
            ->assertOk();
    }

    public function test_asset_index_blocked_for_user_without_permission(): void
    {
        $noPermUser = User::factory()->create(['approved_at' => now()]);

        $this->actingAs($noPermUser)
            ->get('/assets')
            ->assertForbidden();
    }

    // ──────────────────────────────────────
    // Index - Data & Filtering
    // ──────────────────────────────────────

    public function test_asset_index_returns_inertia_page(): void
    {
        $this->actingAs($this->admin)
            ->get('/assets')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('assets/index')
                ->has('assets')
                ->has('sites')
                ->has('clients')
                ->has('filters')
                ->has('can')
            );
    }

    public function test_asset_index_paginates_results(): void
    {
        Asset::factory()->count(30)->forSite($this->site)->create();

        $this->actingAs($this->admin)
            ->get('/assets')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('assets.data', 25)
            );
    }

    public function test_asset_index_filter_by_site(): void
    {
        $otherSite = Site::factory()->create();
        Asset::factory()->count(3)->forSite($this->site)->create();
        Asset::factory()->count(2)->forSite($otherSite)->create();

        $this->actingAs($this->admin)
            ->get("/assets?site_id={$this->site->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('assets.data', 3)
            );
    }

    public function test_asset_index_filter_by_status(): void
    {
        Asset::factory()->active()->count(3)->forSite($this->site)->create();
        Asset::factory()->retired()->count(2)->forSite($this->site)->create();

        $this->actingAs($this->admin)
            ->get('/assets?status=active')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('assets.data', 3)
            );
    }

    public function test_asset_index_filter_by_risk(): void
    {
        Asset::factory()->highRisk()->count(2)->forSite($this->site)->create();
        Asset::factory()->count(3)->forSite($this->site)->create();

        $this->actingAs($this->admin)
            ->get('/assets?risk=high')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('assets.data', 2)
            );
    }

    public function test_asset_index_search_by_name(): void
    {
        Asset::factory()->forSite($this->site)->create(['name' => 'Unique Wheelchair']);
        Asset::factory()->forSite($this->site)->create(['name' => 'Laptop']);

        $this->actingAs($this->admin)
            ->get('/assets?search=Wheelchair')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('assets.data', 1)
            );
    }

    public function test_asset_index_search_by_asset_tag(): void
    {
        Asset::factory()->forSite($this->site)->create(['asset_tag' => 'TAG-UNIQUE-123']);
        Asset::factory()->forSite($this->site)->create(['asset_tag' => 'TAG-OTHER-456']);

        $this->actingAs($this->admin)
            ->get('/assets?search=UNIQUE')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('assets.data', 1)
            );
    }

    public function test_asset_index_admin_can_create(): void
    {
        $this->actingAs($this->admin)
            ->get('/assets')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can.create', true)
            );
    }

    // ──────────────────────────────────────
    // Create
    // ──────────────────────────────────────

    public function test_asset_create_requires_authentication(): void
    {
        $this->get('/fleet-assets/assets/create')->assertRedirect('/login');
    }

    public function test_asset_create_accessible_by_admin(): void
    {
        $this->actingAs($this->admin)
            ->get('/fleet-assets/assets/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('fleet-assets/assets/create')
                ->has('sites')
                ->has('clients')
                ->has('categories')
            );
    }

    public function test_asset_create_blocked_for_user_without_permission(): void
    {
        $noPermUser = User::factory()->create(['approved_at' => now()]);

        $this->actingAs($noPermUser)
            ->get('/fleet-assets/assets/create')
            ->assertForbidden();
    }

    // ──────────────────────────────────────
    // Store
    // ──────────────────────────────────────

    public function test_asset_store_creates_asset(): void
    {
        $this->actingAs($this->admin)
            ->post('/fleet-assets/assets', [
                'name' => 'Test Wheelchair',
                'site_id' => $this->site->id,
                'status' => 'active',
                'risk_level' => 'low',
                'requires_inspection' => false,
                'requires_maintenance' => false,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('assets', [
            'name' => 'Test Wheelchair',
            'site_id' => $this->site->id,
        ]);
    }

    public function test_asset_store_validates_required_name(): void
    {
        $this->actingAs($this->admin)
            ->post('/fleet-assets/assets', [
                'site_id' => $this->site->id,
                'status' => 'active',
                'risk_level' => 'low',
                'requires_inspection' => false,
                'requires_maintenance' => false,
            ])
            ->assertSessionHasErrors(['name']);
    }

    public function test_asset_store_validates_status(): void
    {
        $this->actingAs($this->admin)
            ->post('/fleet-assets/assets', [
                'name' => 'Test',
                'site_id' => $this->site->id,
                'status' => 'invalid_status',
                'risk_level' => 'low',
                'requires_inspection' => false,
                'requires_maintenance' => false,
            ])
            ->assertSessionHasErrors(['status']);
    }

    public function test_asset_store_validates_risk_level(): void
    {
        $this->actingAs($this->admin)
            ->post('/fleet-assets/assets', [
                'name' => 'Test',
                'site_id' => $this->site->id,
                'status' => 'active',
                'risk_level' => 'extreme',
                'requires_inspection' => false,
                'requires_maintenance' => false,
            ])
            ->assertSessionHasErrors(['risk_level']);
    }

    public function test_asset_store_requires_site_or_client(): void
    {
        $this->actingAs($this->admin)
            ->post('/fleet-assets/assets', [
                'name' => 'Test',
                'status' => 'active',
                'risk_level' => 'low',
                'requires_inspection' => false,
                'requires_maintenance' => false,
            ])
            ->assertSessionHasErrors(['site_id']);
    }

    public function test_asset_store_infers_site_from_client(): void
    {
        $client = Client::factory()->create(['site_id' => $this->site->id]);

        $this->actingAs($this->admin)
            ->post('/fleet-assets/assets', [
                'name' => 'Client Asset',
                'client_id' => $client->id,
                'status' => 'active',
                'risk_level' => 'low',
                'requires_inspection' => false,
                'requires_maintenance' => false,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('assets', [
            'name' => 'Client Asset',
            'site_id' => $this->site->id,
            'client_id' => $client->id,
        ]);
    }

    public function test_asset_store_blocked_for_user_without_permission(): void
    {
        $noPermUser = User::factory()->create(['approved_at' => now()]);

        $this->actingAs($noPermUser)
            ->post('/fleet-assets/assets', [
                'name' => 'Test',
                'site_id' => $this->site->id,
                'status' => 'active',
                'risk_level' => 'low',
                'requires_inspection' => false,
                'requires_maintenance' => false,
            ])
            ->assertForbidden();
    }

    // ──────────────────────────────────────
    // Show
    // ──────────────────────────────────────

    public function test_asset_show_requires_authentication(): void
    {
        $asset = Asset::factory()->forSite($this->site)->create();
        $this->get("/assets/{$asset->id}")->assertRedirect('/login');
    }

    public function test_asset_show_accessible_by_admin(): void
    {
        $asset = Asset::factory()->forSite($this->site)->create();

        $this->actingAs($this->admin)
            ->get("/assets/{$asset->id}")
            ->assertOk();
    }

    public function test_asset_show_returns_inertia_page(): void
    {
        $asset = Asset::factory()->forSite($this->site)->create();

        $this->actingAs($this->admin)
            ->get("/assets/{$asset->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('assets/show')
                ->has('asset')
                ->has('inspections')
                ->has('maintenance')
                ->has('documents')
                // The legacy `alerts` prop was renamed to `archived_alerts`
                // when ControlRoomAlert became the canonical operational alert
                // surface — see AssetAlertArchiveTest for the contract.
                ->has('archived_alerts')
                ->has('scan_events')
                ->has('geofences')
                ->has('can')
            );
    }

    public function test_asset_show_returns_404_for_nonexistent(): void
    {
        $this->actingAs($this->admin)
            ->get('/assets/99999')
            ->assertNotFound();
    }

    // ──────────────────────────────────────
    // Edit & Update
    // ──────────────────────────────────────

    public function test_asset_edit_accessible_by_admin(): void
    {
        $asset = Asset::factory()->forSite($this->site)->create();

        $this->actingAs($this->admin)
            ->get("/fleet-assets/assets/{$asset->id}/edit")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('fleet-assets/assets/edit')
                ->has('asset')
                ->has('sites')
                ->has('categories')
            );
    }

    public function test_asset_update_successful(): void
    {
        $asset = Asset::factory()->forSite($this->site)->create(['name' => 'Old Name']);

        $this->actingAs($this->admin)
            ->put("/fleet-assets/assets/{$asset->id}", [
                'name' => 'Updated Name',
                'site_id' => $this->site->id,
                'category' => 'equipment',
                'status' => 'active',
                'risk_level' => 'medium',
                'requires_inspection' => false,
                'requires_maintenance' => false,
            ])
            ->assertRedirect("/fleet-assets/assets/{$asset->id}");

        $asset->refresh();
        $this->assertEquals('Updated Name', $asset->name);
        $this->assertEquals('medium', $asset->risk_level);
    }

    public function test_asset_update_validates_required_fields(): void
    {
        $asset = Asset::factory()->forSite($this->site)->create();

        $this->actingAs($this->admin)
            ->put("/fleet-assets/assets/{$asset->id}", [])
            ->assertSessionHasErrors(['name', 'status', 'risk_level']);
    }

    // ──────────────────────────────────────
    // Delete
    // ──────────────────────────────────────

    public function test_asset_delete_successful(): void
    {
        $asset = Asset::factory()->forSite($this->site)->create();

        $this->actingAs($this->admin)
            ->delete("/assets/{$asset->id}")
            ->assertRedirect(route('assets.index'));

        $this->assertDatabaseMissing('assets', ['id' => $asset->id]);
    }

    public function test_asset_delete_blocked_for_coordinator(): void
    {
        $asset = Asset::factory()->forSite($this->site)->create();

        $this->actingAs($this->coordinator)
            ->delete("/assets/{$asset->id}")
            ->assertForbidden();
    }
}
