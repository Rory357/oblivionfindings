<?php

namespace Tests\Feature\FleetAssets;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Contract for the source-owned, single-organisation Fleet Overview. */
class DashboardHeroContractTest extends TestCase
{
    use RefreshDatabase;

    private function viewer(array $permissions, ?Site $site = null): User
    {
        $this->seed(RbacSeeder::class);
        $user = User::factory()->create(['approved_at' => now()]);
        if ($site) {
            HrEmployeeProfile::factory()->create([
                'user_id' => $user->id, 'primary_site_id' => $site->id,
                'secondary_site_ids' => [], 'is_active' => true,
                'start_date' => now()->subMonth(), 'end_date' => null,
            ]);
        }
        foreach ($permissions as $key) {
            $permission = Permission::query()->firstOrCreate(['key' => $key],
                ['description' => $key, 'group' => explode('.', $key)[0]]);
            $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
        }

        return $user;
    }

    public function test_overview_has_honest_empty_and_unavailable_source_states(): void
    {
        $viewer = $this->viewer(['fleet.viewAny']);
        $this->actingAs($viewer)->get('/fleet-assets')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('fleet-assets/dashboard')
                ->has('overview.vehicles', 0)
                ->has('overview.assets', 0)
                ->has('overview.attention', 0)
                ->has('overview.agenda', 0)
                ->where('overview.sources.3.name', 'Assignments and receipt')
                ->where('overview.sources.3.state', 'no_access')
                ->missing('stats')
            );
    }

    public function test_fleet_management_alone_does_not_bypass_approved_site_scope(): void
    {
        $site = Site::factory()->create();
        $foreign = Site::factory()->create();
        $viewer = $this->viewer(['fleet.viewAny', 'fleet.manage'], $site);
        $local = Asset::factory()->vehicle()->forSite($site)->create();
        Asset::factory()->vehicle()->forSite($foreign)->create();

        $this->actingAs($viewer)->get('/fleet-assets')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('overview.vehicles', 1)
                ->where('overview.vehicles.0.id', $local->id)
            );
    }
}
