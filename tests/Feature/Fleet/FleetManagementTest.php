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
    // Fleet Dashboard - Auth & Access
    // ──────────────────────────────────────

    public function test_fleet_dashboard_requires_authentication(): void
    {
        $this->get('/fleet-management')->assertRedirect('/login');
    }

    public function test_fleet_dashboard_accessible_by_admin(): void
    {
        $this->actingAs($this->admin)
            ->get('/fleet-management')
            ->assertOk();
    }

    public function test_fleet_dashboard_accessible_by_coordinator(): void
    {
        $this->actingAs($this->coordinator)
            ->get('/fleet-management')
            ->assertOk();
    }

    public function test_fleet_dashboard_accessible_by_support_worker(): void
    {
        $this->actingAs($this->supportWorker)
            ->get('/fleet-management')
            ->assertOk();
    }

    public function test_fleet_dashboard_blocked_for_user_without_permission(): void
    {
        $noPermUser = User::factory()->create(['approved_at' => now()]);

        $this->actingAs($noPermUser)
            ->get('/fleet-management')
            ->assertForbidden();
    }

    // ──────────────────────────────────────
    // Fleet Dashboard - Data
    // ──────────────────────────────────────

    public function test_fleet_dashboard_returns_inertia_page(): void
    {
        $this->actingAs($this->admin)
            ->get('/fleet-management')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('fleet-management/index')
            );
    }

    // ──────────────────────────────────────
    // Vehicle Show
    // ──────────────────────────────────────

    public function test_vehicle_show_requires_authentication(): void
    {
        $site = Site::factory()->create();
        $vehicle = Asset::factory()->vehicle()->forSite($site)->create();
        $this->get("/fleet/vehicles/{$vehicle->id}")->assertRedirect('/login');
    }

    public function test_vehicle_show_accessible_by_admin(): void
    {
        $site = Site::factory()->create();
        $vehicle = Asset::factory()->vehicle()->forSite($site)->create();

        $this->actingAs($this->admin)
            ->get("/fleet/vehicles/{$vehicle->id}")
            ->assertOk();
    }

    public function test_vehicle_show_returns_inertia_page(): void
    {
        $site = Site::factory()->create();
        $vehicle = Asset::factory()->vehicle()->forSite($site)->create();

        $this->actingAs($this->admin)
            ->get("/fleet/vehicles/{$vehicle->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('fleet-management/vehicle')
            );
    }

    // ──────────────────────────────────────
    // Trip Management
    // ──────────────────────────────────────

    public function test_trip_show_requires_authentication(): void
    {
        $site = Site::factory()->create();
        $vehicle = Asset::factory()->vehicle()->forSite($site)->create();
        $trip = FleetTrip::factory()->create(['asset_id' => $vehicle->id]);
        $this->get("/fleet/trips/{$trip->id}")->assertRedirect('/login');
    }

    public function test_trip_show_accessible_by_admin(): void
    {
        $site = Site::factory()->create();
        $vehicle = Asset::factory()->vehicle()->forSite($site)->create();
        $trip = FleetTrip::factory()->create(['asset_id' => $vehicle->id]);

        $this->actingAs($this->admin)
            ->get("/fleet/trips/{$trip->id}")
            ->assertOk();
    }

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
            ->assertRedirect();

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

    public function test_trip_playback_returns_json(): void
    {
        $site = Site::factory()->create();
        $vehicle = Asset::factory()->vehicle()->forSite($site)->create();
        $trip = FleetTrip::factory()->create(['asset_id' => $vehicle->id]);

        $this->actingAs($this->admin)
            ->getJson("/fleet/trips/{$trip->id}/playback")
            ->assertOk()
            ->assertJsonStructure(['points']);
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
