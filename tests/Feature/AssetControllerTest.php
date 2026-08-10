<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Client;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
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

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);

        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->coordinator = User::factory()->create(['role' => 'coordinator', 'approved_at' => now()]);
        $this->coordinator->roles()->attach(Role::where('name', 'coordinator')->first());

        $this->supportWorker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $this->supportWorker->roles()->attach(Role::where('name', 'support_worker')->first());

        $this->site = Site::factory()->create();
    }

    // ──────────────────────────────────────
    // Legacy index/show/alerts redirect to /fleet-assets
    // ──────────────────────────────────────

    public function test_asset_index_requires_authentication(): void
    {
        $this->get('/assets')->assertRedirect('/login');
    }

    public function test_asset_index_redirects_to_fleet_assets(): void
    {
        $this->actingAs($this->admin)
            ->get('/assets')
            ->assertRedirect('/fleet-assets/assets');
    }

    public function test_asset_alerts_redirects_to_fleet_assets_alerts(): void
    {
        $this->actingAs($this->admin)
            ->get('/assets/alerts')
            ->assertRedirect('/fleet-assets/alerts');
    }

    // ──────────────────────────────────────
    // Create
    // ──────────────────────────────────────

    public function test_asset_create_requires_authentication(): void
    {
        $this->get('/fleet-assets/assets/create')->assertRedirect('/login');
    }

    public function test_asset_create_redirects_to_index_wizard_shim(): void
    {
        // The full-page create form was replaced by the AssetWizardDialog on
        // the index (?new=1 opens it on mount).
        $this->actingAs($this->admin)
            ->get('/fleet-assets/assets/create')
            ->assertRedirect('/fleet-assets/assets?new=1');
    }

    public function test_asset_index_ships_wizard_option_lists(): void
    {
        $this->actingAs($this->admin)
            ->get('/fleet-assets/assets')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('fleet-assets/assets/index')
                ->has('sites')
                ->has('clients')
                ->has('prefill')
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
    // Show (legacy URL redirects)
    // ──────────────────────────────────────

    public function test_asset_show_requires_authentication(): void
    {
        $asset = Asset::factory()->forSite($this->site)->create();
        $this->get("/assets/{$asset->id}")->assertRedirect('/login');
    }

    public function test_asset_show_redirects_to_fleet_assets(): void
    {
        $asset = Asset::factory()->forSite($this->site)->create();

        $this->actingAs($this->admin)
            ->get("/assets/{$asset->id}")
            ->assertRedirect("/fleet-assets/assets/{$asset->id}");
    }

    // ──────────────────────────────────────
    // Edit & Update
    // ──────────────────────────────────────

    public function test_asset_edit_redirects_to_show_wizard_shim(): void
    {
        // The full-page edit form was replaced by the AssetWizardDialog on the
        // show page (?edit=1 opens it on mount).
        $asset = Asset::factory()->forSite($this->site)->create();

        $this->actingAs($this->admin)
            ->get("/fleet-assets/assets/{$asset->id}/edit")
            ->assertRedirect("/fleet-assets/assets/{$asset->id}?edit=1");
    }

    public function test_asset_show_ships_edit_wizard_option_lists(): void
    {
        $asset = Asset::factory()->forSite($this->site)->create();

        $this->actingAs($this->admin)
            ->get("/fleet-assets/assets/{$asset->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('fleet-assets/assets/show')
                ->has('asset')
                ->has('sites')
                ->has('clients')
            );
    }

    public function test_asset_store_from_modal_redirects_back_to_index_with_created_id(): void
    {
        $this->actingAs($this->admin)
            ->post('/fleet-assets/assets', [
                'name' => 'Modal Asset',
                'site_id' => $this->site->id,
                'status' => 'active',
                'risk_level' => 'low',
                'requires_inspection' => false,
                'requires_maintenance' => false,
                '_modal' => true,
            ])
            ->assertRedirect();

        $asset = Asset::query()->where('name', 'Modal Asset')->firstOrFail();

        $this->actingAs($this->admin)
            ->get("/fleet-assets/assets?created={$asset->id}")
            ->assertInertia(fn ($page) => $page
                ->component('fleet-assets/assets/index')
                ->where('created_asset_id', $asset->id)
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

    public function test_asset_delete_retires_and_retains_the_record(): void
    {
        $asset = Asset::factory()->forSite($this->site)->create();

        $this->actingAs($this->admin)
            ->delete("/assets/{$asset->id}")
            ->assertRedirect(route('fleet-assets.assets.index'));

        $this->assertDatabaseHas('assets', [
            'id' => $asset->id,
            'status' => 'retired',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'assets.retired',
            'auditable_id' => $asset->id,
        ]);
    }

    public function test_asset_delete_blocked_for_coordinator(): void
    {
        $asset = Asset::factory()->forSite($this->site)->create();

        $this->actingAs($this->coordinator)
            ->delete("/assets/{$asset->id}")
            ->assertForbidden();
    }
}
