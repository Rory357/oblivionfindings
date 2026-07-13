<?php

namespace Tests\Feature\FleetAssets;

use App\Models\FleetIncident;
use App\Models\AssetGeofence;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FleetModalRouteShimTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RbacSeeder::class);

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'approved_at' => now(),
        ]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->firstOrFail());
    }

    public function test_full_page_create_routes_redirect_to_modal_query_shims(): void
    {
        $this->actingAs($this->admin)
            ->get('/fleet-assets/geofences/create')
            ->assertRedirect('/fleet-assets/geofences?new=1');

        $this->actingAs($this->admin)
            ->get('/fleet-assets/outings/create')
            ->assertRedirect('/fleet-assets/outings?new=1');

        $this->actingAs($this->admin)
            ->get('/fleet-assets/transports/create')
            ->assertRedirect('/fleet-assets/transports?new=1');
    }

    public function test_geofence_edit_route_redirects_to_the_index_edit_modal(): void
    {
        $geofence = AssetGeofence::query()->create([
            'name' => 'Test geofence',
            'type' => 'custom',
            'scope' => 'site',
            'shape' => [
                'type' => 'circle',
                'center' => ['lat' => -41.2865, 'lng' => 174.7762],
                'radius' => 250,
            ],
            'breach_type' => 'both',
            'alert_config' => [],
            'time_rules' => [],
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->get("/fleet-assets/geofences/{$geofence->id}/edit")
            ->assertRedirect("/fleet-assets/geofences?edit={$geofence->id}");
    }

    public function test_incident_detail_route_redirects_to_the_index_detail_modal(): void
    {
        $incident = FleetIncident::factory()->create();

        $this->actingAs($this->admin)
            ->get("/fleet-assets/incidents/{$incident->id}")
            ->assertRedirect("/fleet-assets/incidents?incident={$incident->id}");
    }
}
