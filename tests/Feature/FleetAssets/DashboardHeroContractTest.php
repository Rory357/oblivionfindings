<?php

namespace Tests\Feature\FleetAssets;

use App\Models\Asset;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Contract for the /fleet-assets hero redesign: the new compliance / resident-
 * movement stats, and the ?scope=mine cluster lens (scoped cluster counts,
 * org-wide attention strip + badges).
 */
class DashboardHeroContractTest extends TestCase
{
    use RefreshDatabase;

    private function makeFleetUser(array $permissionKeys = ['fleet.viewAny']): User
    {
        $this->seed(\Database\Seeders\RbacSeeder::class);

        $user = User::factory()->create([
            'approved_at' => now(),
        ]);

        foreach ($permissionKeys as $permissionKey) {
            $permission = Permission::query()->firstOrCreate(
                ['key' => $permissionKey],
                [
                    'description' => str_replace('.', ' ', $permissionKey),
                    'group' => explode('.', $permissionKey)[0],
                ]
            );

            $user->permissionOverrides()->syncWithoutDetaching([
                $permission->id => ['allowed' => true],
            ]);
        }

        return $user;
    }

    public function test_dashboard_exposes_hero_stats_contract_and_hides_lens_without_site(): void
    {
        $user = $this->makeFleetUser();
        $site = Site::factory()->create();
        Asset::factory()->vehicle()->forSite($site)->create([
            'registration_expires_at' => now()->addDays(10),
            'cof_expires_at' => now()->addDays(10),
        ]);
        Asset::factory()->vehicle()->forSite($site)->create([
            'registration_expires_at' => now()->subDay(),
            'cof_expires_at' => now()->subDay(),
        ]);

        $this->actingAs($user)
            ->get('/fleet-assets')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('fleet-assets/dashboard')
                ->has('stats.wof_expired')
                ->where('stats.rego_due_30', 1)
                ->where('stats.rego_expired', 1)
                ->where('stats.cof_due', 1)
                ->where('stats.cof_expired', 1)
                ->where('stats.insurance_expiring', null)
                ->where('stats.insurance_expired', null)
                ->has('stats.transports_today')
                ->has('stats.open_wandering_alerts')
                ->has('stats.overdue_count_scoped')
                ->has('stats.outings_past_return_scoped')
                ->where('scope', 'all')
                ->where('has_site', false)
            );
    }

    public function test_scope_mine_filters_cluster_counts_but_not_org_wide_totals(): void
    {
        $user = $this->makeFleetUser();

        $mySite = Site::factory()->create();
        $otherSite = Site::factory()->create();

        // The user's site resolves through their first assigned client.
        $client = Client::factory()->create(['site_id' => $mySite->id]);
        $user->assignedClients()->attach($client->id);

        Asset::factory()->vehicle()->forSite($mySite)->count(2)->create();
        Asset::factory()->vehicle()->forSite($otherSite)->count(3)->create();

        // Factory vehicles have no fleet state → every in-scope vehicle counts as offline.
        $this->actingAs($user)
            ->get('/fleet-assets?scope=mine')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('scope', 'mine')
                ->where('has_site', true)
                ->where('stats.offline_count', 2)
                ->where('stats.total_vehicles', 5)
            );

        $this->actingAs($user)
            ->get('/fleet-assets')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('scope', 'all')
                ->where('stats.offline_count', 5)
            );
    }

    public function test_scope_mine_falls_back_to_all_when_user_has_no_site(): void
    {
        $user = $this->makeFleetUser();
        $site = Site::factory()->create();
        Asset::factory()->vehicle()->forSite($site)->count(3)->create();

        $this->actingAs($user)
            ->get('/fleet-assets?scope=mine')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('scope', 'all')
                ->where('has_site', false)
                ->where('stats.offline_count', 3)
            );
    }
}
