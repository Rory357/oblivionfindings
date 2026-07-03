<?php

namespace Tests\Feature\Fleet;

use App\Models\Asset;
use App\Models\FleetTrip;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FleetManagementTest extends TestCase
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
    // Legacy routes redirect to /fleet-assets
    // ──────────────────────────────────────

    public function test_legacy_fleet_dashboard_redirects_to_fleet_assets(): void
    {
        $this->get('/fleet-management')->assertRedirect('/fleet-assets');
    }

    public function test_legacy_vehicle_show_redirects_to_fleet_assets(): void
    {
        $site = Site::factory()->create();
        $vehicle = Asset::factory()->vehicle()->forSite($site)->create();

        $this->get("/fleet/vehicles/{$vehicle->id}")
            ->assertRedirect("/fleet-assets/vehicles/{$vehicle->id}");
    }

    public function test_legacy_fuel_index_redirects_to_fleet_assets(): void
    {
        $this->get('/fleet/fuel')->assertRedirect('/fleet-assets/fuel');
    }

    public function test_legacy_reports_index_redirects_to_fleet_assets(): void
    {
        $this->get('/fleet/reports')->assertRedirect('/fleet-assets/reports');
    }

    public function test_legacy_trip_show_redirects_to_playback(): void
    {
        $site = Site::factory()->create();
        $vehicle = Asset::factory()->vehicle()->forSite($site)->create();
        $trip = FleetTrip::factory()->create(['asset_id' => $vehicle->id]);

        $this->get("/fleet/trips/{$trip->id}")
            ->assertRedirect("/fleet-assets/trips/{$trip->id}/playback");
    }

    public function test_legacy_playback_endpoint_redirects_to_playback_data(): void
    {
        $site = Site::factory()->create();
        $vehicle = Asset::factory()->vehicle()->forSite($site)->create();
        $trip = FleetTrip::factory()->create(['asset_id' => $vehicle->id]);

        $this->get("/fleet/trips/{$trip->id}/playback")
            ->assertRedirect("/fleet-assets/trips/{$trip->id}/playback/data");
    }

    // ──────────────────────────────────────
    // Trip Playback (canonical shell)
    // ──────────────────────────────────────

    public function test_trip_playback_page_requires_authentication(): void
    {
        $site = Site::factory()->create();
        $vehicle = Asset::factory()->vehicle()->forSite($site)->create();
        $trip = FleetTrip::factory()->create(['asset_id' => $vehicle->id]);

        $this->get("/fleet-assets/trips/{$trip->id}/playback")->assertRedirect('/login');
    }

    public function test_trip_playback_page_accessible_by_admin(): void
    {
        $site = Site::factory()->create();
        $vehicle = Asset::factory()->vehicle()->forSite($site)->create();
        $trip = FleetTrip::factory()->create(['asset_id' => $vehicle->id]);

        $this->actingAs($this->admin)
            ->get("/fleet-assets/trips/{$trip->id}/playback")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('fleet-assets/trips/playback')
                ->has('trip')
                ->has('driver_sessions')
                ->has('can')
            );
    }

    public function test_trip_playback_page_blocked_for_user_without_permission(): void
    {
        $site = Site::factory()->create();
        $vehicle = Asset::factory()->vehicle()->forSite($site)->create();
        $trip = FleetTrip::factory()->create(['asset_id' => $vehicle->id]);

        $noPermUser = User::factory()->create(['approved_at' => now()]);

        $this->actingAs($noPermUser)
            ->get("/fleet-assets/trips/{$trip->id}/playback")
            ->assertForbidden();
    }

    public function test_trip_playback_data_returns_json(): void
    {
        $site = Site::factory()->create();
        $vehicle = Asset::factory()->vehicle()->forSite($site)->create();
        $trip = FleetTrip::factory()->create(['asset_id' => $vehicle->id]);

        $this->actingAs($this->admin)
            ->getJson("/fleet-assets/trips/{$trip->id}/playback/data")
            ->assertOk()
            ->assertJsonStructure(['points']);
    }

    // ──────────────────────────────────────
    // Trip Management (write endpoints kept)
    // ──────────────────────────────────────

    public function test_trip_close_by_admin(): void
    {
        $site = Site::factory()->create();
        $vehicle = Asset::factory()->vehicle()->forSite($site)->create();
        $trip = FleetTrip::factory()->create([
            'asset_id' => $vehicle->id,
            'status' => 'open',
        ]);

        $this->actingAs($this->admin)
            ->post("/fleet/trips/{$trip->id}/close")
            ->assertRedirect();

        $trip->refresh();
        $this->assertEquals('closed', $trip->status);
    }

    public function test_trip_close_already_closed_returns_error(): void
    {
        $site = Site::factory()->create();
        $vehicle = Asset::factory()->vehicle()->forSite($site)->create();
        $trip = FleetTrip::factory()->closed()->create(['asset_id' => $vehicle->id]);

        $this->actingAs($this->admin)
            ->post("/fleet/trips/{$trip->id}/close")
            ->assertSessionHasErrors(['trip']);
    }

    public function test_trip_delete_by_admin(): void
    {
        $site = Site::factory()->create();
        $vehicle = Asset::factory()->vehicle()->forSite($site)->create();
        $trip = FleetTrip::factory()->create(['asset_id' => $vehicle->id]);

        $this->actingAs($this->admin)
            ->delete("/fleet/trips/{$trip->id}")
            ->assertRedirect(route('fleet-assets.trips.index'));

        $this->assertDatabaseMissing('fleet_trips', ['id' => $trip->id]);
    }

    public function test_trip_update_status_by_admin(): void
    {
        $site = Site::factory()->create();
        $vehicle = Asset::factory()->vehicle()->forSite($site)->create();
        $trip = FleetTrip::factory()->create([
            'asset_id' => $vehicle->id,
            'status' => 'open',
        ]);

        $this->actingAs($this->admin)
            ->put("/fleet/trips/{$trip->id}", [
                'status' => 'closed',
            ])
            ->assertRedirect();

        $trip->refresh();
        $this->assertEquals('closed', $trip->status);
    }

    // ──────────────────────────────────────
    // Permission Checks
    // ──────────────────────────────────────

    public function test_trip_delete_blocked_for_support_worker(): void
    {
        $site = Site::factory()->create();
        $vehicle = Asset::factory()->vehicle()->forSite($site)->create();
        $trip = FleetTrip::factory()->create(['asset_id' => $vehicle->id]);

        $this->actingAs($this->supportWorker)
            ->delete("/fleet/trips/{$trip->id}")
            ->assertForbidden();
    }
}
