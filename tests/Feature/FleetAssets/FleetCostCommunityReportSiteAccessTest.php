<?php

namespace Tests\Feature\FleetAssets;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\Client;
use App\Models\FleetDriverSession;
use App\Models\FleetFuelLog;
use App\Models\FleetOuting;
use App\Models\FleetOutingResident;
use App\Models\FleetResidentTransport;
use App\Models\FleetTrip;
use App\Models\FleetWorkOrder;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * FA-T01: the Cost Allocation and Community Access reports belong to the
 * viewer's approved Sites. Every cost, house row, resident row, outing count
 * and CSV covers only vehicles, journeys and residents at those Sites, and
 * `fleet.manage` is the explicit all-Sites bypass.
 */
class FleetCostCommunityReportSiteAccessTest extends TestCase
{
    use RefreshDatabase;

    private Site $localSite;

    private Site $otherSite;

    private Asset $localVehicle;

    private Asset $otherVehicle;

    private Client $localResident;

    private Client $otherResident;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->travelTo(Carbon::parse('2026-09-15 12:00:00', config('app.timezone')));

        $this->localSite = $this->house('Harbour House');
        $this->otherSite = $this->house('Forest House');
        $this->localVehicle = $this->vehicle('Harbour Van', $this->localSite, $this->localSite);
        $this->otherVehicle = $this->vehicle('Forest Van', $this->otherSite, $this->otherSite);
        // Allocated to Harbour but homed at Forest: a Harbour viewer sees it,
        // and must never get a cost row keyed by the Forest house.
        $this->vehicle('Harbour Loan Van', $this->localSite, $this->otherSite);

        $localDriver = $this->staffAt($this->localSite, [], 'Harbour Driver');
        $otherDriver = $this->staffAt($this->otherSite, [], 'Forest Driver');

        $this->fuelLog($this->localVehicle, $localDriver, 100);
        $this->fuelLog($this->otherVehicle, $otherDriver, 250);
        $this->workOrder($this->localVehicle, 80);
        $this->workOrder($this->otherVehicle, 300);
        $this->trip($this->localVehicle, $localDriver, 20.0);
        $this->trip($this->otherVehicle, $otherDriver, 40.0);

        $this->localResident = $this->residentAt($this->localSite, 'Harbour');
        $this->otherResident = $this->residentAt($this->otherSite, 'Forest');
        $this->transport($this->localVehicle, $localDriver, $this->localResident);
        $this->transport($this->otherVehicle, $otherDriver, $this->otherResident);

        // The Harbour van also took the Forest resident along on its outing.
        $this->outing($this->localVehicle, $localDriver, [$this->localResident, $this->otherResident]);
        $this->outing($this->otherVehicle, $otherDriver, [$this->otherResident]);
    }

    public function test_site_viewer_cost_allocation_covers_only_their_sites(): void
    {
        $viewer = $this->staffAt($this->localSite, ['fleet.viewAny']);

        $this->actingAs($viewer)
            ->get('/fleet-assets/reports/cost-allocation')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('fleet-assets/reports/cost-allocation')
                ->has('by_site', 1)
                ->where('by_site.0.id', $this->localSite->id)
                ->where('by_site.0.name', 'Harbour House')
                ->where('by_site.0.vehicles', 2)
                ->where('by_site.0.fuel_cost', 100)
                ->where('by_site.0.maintenance_cost', 80)
                ->where('by_site.0.total', 180)
                ->where('stats.total_fleet_cost', 180)
                ->where('stats.total_fuel', 100)
                ->where('stats.total_maintenance', 80)
                ->has('by_resident', 1)
                ->where('by_resident.0.id', $this->localResident->id)
                ->where('by_resident.0.name', 'Harbour Resident')
                ->where('by_resident.0.house', 'Harbour House'));

        $houses = $this->costCsv($viewer, 'house');
        $this->assertStringContainsString('Harbour House', $houses);
        $this->assertStringNotContainsString('Forest', $houses);

        $residents = $this->costCsv($viewer, 'resident');
        $this->assertStringContainsString('Harbour Resident', $residents);
        $this->assertStringNotContainsString('Forest', $residents);
    }

    public function test_site_viewer_community_access_covers_only_their_sites(): void
    {
        $viewer = $this->staffAt($this->localSite, ['fleet.viewAny']);

        $this->actingAs($viewer)
            ->get('/fleet-assets/reports/community-access')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('fleet-assets/reports/community-access')
                // The Forest resident rode on the Harbour van's outing but is
                // not a Client this viewer may see, so gets no row.
                ->has('by_resident', 1)
                ->where('by_resident.0.id', $this->localResident->id)
                ->where('by_resident.0.name', 'Harbour Resident')
                ->where('by_resident.0.house', 'Harbour House')
                ->where('by_resident.0.outings', 1)
                ->where('by_resident.0.transport_trips', 1)
                ->where('stats.total_outings', 1)
                ->where('stats.residents_participating', 1)
                ->where('weekly_trend', fn ($weeks): bool => collect($weeks)->sum('value') === 1));

        $csv = $this->communityCsv($viewer);
        $this->assertStringContainsString('Harbour Resident', $csv);
        $this->assertStringNotContainsString('Forest', $csv);
    }

    public function test_viewer_without_a_current_site_sees_nothing(): void
    {
        // Without a current HR Site there is nothing to report: the boundary fails closed.
        $unplaced = User::factory()->create(['approved_at' => now()]);
        $this->grant($unplaced, ['fleet.viewAny']);

        $this->actingAs($unplaced)
            ->get('/fleet-assets/reports/cost-allocation')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('by_site', 0)
                ->has('by_resident', 0)
                ->where('stats.total_fleet_cost', 0)
                ->where('stats.total_fuel', 0)
                ->where('stats.total_maintenance', 0));

        $this->actingAs($unplaced)
            ->get('/fleet-assets/reports/community-access')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('by_resident', 0)
                ->where('stats.total_outings', 0)
                ->where('weekly_trend', fn ($weeks): bool => collect($weeks)->sum('value') === 0));

        foreach ([$this->costCsv($unplaced, 'house'), $this->costCsv($unplaced, 'resident'), $this->communityCsv($unplaced)] as $csv) {
            $this->assertStringNotContainsString('Harbour', $csv);
            $this->assertStringNotContainsString('Forest', $csv);
        }
    }

    public function test_fleet_manage_is_the_explicit_all_sites_bypass(): void
    {
        $fleetManager = $this->staffAt($this->localSite, ['fleet.viewAny', 'fleet.manage']);

        $this->actingAs($fleetManager)
            ->get('/fleet-assets/reports/cost-allocation')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('by_site', fn ($rows): bool => collect($rows)->pluck('id')->sort()->values()->all()
                    === collect([$this->localSite->id, $this->otherSite->id])->sort()->values()->all())
                ->where('stats.total_fleet_cost', 730)
                ->where('by_resident', fn ($rows): bool => collect($rows)->pluck('id')->sort()->values()->all()
                    === collect([$this->localResident->id, $this->otherResident->id])->sort()->values()->all()));

        $this->actingAs($fleetManager)
            ->get('/fleet-assets/reports/community-access')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('by_resident', 2)
                ->where('stats.total_outings', 3)
                ->where('weekly_trend', fn ($weeks): bool => collect($weeks)->sum('value') === 2));

        $this->assertStringContainsString('Forest House', $this->costCsv($fleetManager, 'house'));
        $this->assertStringContainsString('Forest Resident', $this->communityCsv($fleetManager));
    }

    private function costCsv(User $viewer, string $tab): string
    {
        return $this->actingAs($viewer)
            ->get("/fleet-assets/reports/cost-allocation?export=csv&tab={$tab}")
            ->assertOk()
            ->streamedContent();
    }

    private function communityCsv(User $viewer): string
    {
        return $this->actingAs($viewer)
            ->get('/fleet-assets/reports/community-access?export=csv')
            ->assertOk()
            ->streamedContent();
    }

    /** @param list<string> $permissionKeys */
    private function staffAt(Site $site, array $permissionKeys = [], ?string $name = null): User
    {
        $user = User::factory()->create([
            ...($name ? ['name' => $name] : []),
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subMonth(),
            'end_date' => null,
            'is_active' => true,
        ]);
        $this->grant($user, $permissionKeys);

        return $user;
    }

    /** @param list<string> $permissionKeys */
    private function grant(User $user, array $permissionKeys): void
    {
        foreach ($permissionKeys as $permissionKey) {
            $permission = Permission::query()->firstOrCreate(
                ['key' => $permissionKey],
                ['description' => $permissionKey, 'group' => 'fleet', 'module' => 'Fleet'],
            );

            $user->permissionOverrides()->syncWithoutDetaching([
                $permission->id => ['allowed' => true],
            ]);
        }
    }

    private function house(string $name): Site
    {
        return Site::factory()->create([
            'name' => $name,
            'type' => 'house',
            'is_active' => true,
            'archived' => false,
            'archived_at' => null,
        ]);
    }

    private function vehicle(string $name, Site $site, Site $homeSite): Asset
    {
        return Asset::factory()->vehicle()->create([
            'site_id' => $site->id,
            'home_site_id' => $homeSite->id,
            'client_id' => null,
            'category' => 'vehicle',
            'status' => 'active',
            'name' => $name,
        ]);
    }

    private function residentAt(Site $site, string $firstName): Client
    {
        return Client::factory()->create([
            'site_id' => $site->id,
            'status' => 'active',
            'first_name' => $firstName,
            'last_name' => 'Resident',
        ]);
    }

    private function trip(Asset $vehicle, User $driver, float $distanceKm): FleetTrip
    {
        $session = FleetDriverSession::query()->create([
            'asset_id' => $vehicle->id,
            'user_id' => $driver->id,
            'started_at' => now()->subDays(2),
            'ended_at' => now()->subDays(2)->addHour(),
            'source' => 'manual',
            'status' => 'closed',
        ]);

        return FleetTrip::query()->create([
            'asset_id' => $vehicle->id,
            'driver_session_id' => $session->id,
            'started_at' => now()->subDays(2),
            'ended_at' => now()->subDays(2)->addHour(),
            'distance_km' => $distanceKm,
            'duration_s' => 3600,
            'status' => 'closed',
            'consent_blocked' => false,
            'is_personal' => false,
        ]);
    }

    private function fuelLog(Asset $vehicle, User $user, float $totalCost): FleetFuelLog
    {
        return FleetFuelLog::query()->create([
            'asset_id' => $vehicle->id,
            'user_id' => $user->id,
            'logged_at' => now()->subDays(2),
            'fuel_type' => 'diesel',
            'quantity_litres' => 40,
            'cost_per_litre' => $totalCost / 40,
            'total_cost' => $totalCost,
            'full_tank' => true,
        ]);
    }

    private function workOrder(Asset $vehicle, float $actualCost): FleetWorkOrder
    {
        return FleetWorkOrder::factory()->create([
            'asset_id' => $vehicle->id,
            'title' => "{$vehicle->name} service",
            'status' => 'open',
            'actual_cost' => $actualCost,
        ]);
    }

    private function transport(Asset $vehicle, User $driver, Client $resident): FleetResidentTransport
    {
        return FleetResidentTransport::query()->create([
            'asset_id' => $vehicle->id,
            'site_id' => $resident->site_id,
            'driver_user_id' => $driver->id,
            'resident_id' => $resident->id,
            'resident_name' => $resident->first_name.' Resident',
            'transport_type' => 'community',
            'departed_at' => now()->subDays(2),
            'status' => 'completed',
        ]);
    }

    /** @param list<Client> $residents */
    private function outing(Asset $vehicle, User $driver, array $residents): FleetOuting
    {
        $outing = FleetOuting::query()->create([
            'title' => "{$vehicle->name} outing",
            'destination' => 'Botanic Gardens',
            'planned_departure' => now()->subDays(2),
            'planned_return' => now()->subDays(2)->addHours(3),
            'actual_departure' => now()->subDays(2),
            'actual_return' => now()->subDays(2)->addHours(2),
            'asset_id' => $vehicle->id,
            'driver_user_id' => $driver->id,
            'status' => 'completed',
            'created_by_user_id' => $driver->id,
        ]);

        foreach ($residents as $resident) {
            FleetOutingResident::query()->create([
                'outing_id' => $outing->id,
                'client_id' => $resident->id,
                'returned_at' => now()->subDays(2)->addHours(2),
            ]);
        }

        return $outing;
    }
}
