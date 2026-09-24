<?php

namespace Tests\Feature\FleetAssets;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\Client;
use App\Models\FleetDriverSession;
use App\Models\FleetFuelLog;
use App\Models\FleetIncident;
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
 * FA-T01: Fleet reports belong to the viewer's approved Sites. Every report
 * total, list, CSV export, house summary and reimbursement row covers only
 * vehicles at those Sites, drivers are named only when the viewer can see
 * them as staff, and `fleet.manage` is the explicit all-Sites bypass.
 */
class FleetReportSiteAccessTest extends TestCase
{
    use RefreshDatabase;

    private Site $localSite;

    private Site $otherSite;

    private Asset $localVehicle;

    private Asset $otherVehicle;

    private Client $localResident;

    private User $localDriver;

    private User $otherDriver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->travelTo(Carbon::parse('2026-09-15 12:00:00', config('app.timezone')));

        $this->localSite = $this->house('Harbour House');
        $this->otherSite = $this->house('Forest House');
        $this->localVehicle = $this->vehicleAt($this->localSite, 'Harbour Van');
        $this->otherVehicle = $this->vehicleAt($this->otherSite, 'Forest Van');
        $this->localDriver = $this->staffAt($this->localSite, [], 'Harbour Driver');
        $this->otherDriver = $this->staffAt($this->otherSite, [], 'Forest Driver');

        // The Forest driver also borrowed the Harbour van and had its incident.
        $this->trip($this->localVehicle, $this->localDriver, 12.5);
        $this->trip($this->localVehicle, $this->otherDriver, 7.5);
        $this->trip($this->otherVehicle, $this->otherDriver, 40.0);

        $this->fuelLog($this->localVehicle, $this->localDriver);
        $this->fuelLog($this->otherVehicle, $this->otherDriver);
        $this->workOrder($this->localVehicle, 'Harbour Van brake check');
        $this->workOrder($this->otherVehicle, 'Forest Van tyre swap');
        $this->incident($this->localVehicle, $this->otherDriver);
        $this->incident($this->otherVehicle, $this->otherDriver);

        $this->localResident = $this->residentAt($this->localSite, 'Harbour');
        $this->transport($this->localVehicle, $this->localDriver, $this->localResident);
        $this->transport($this->otherVehicle, $this->otherDriver, $this->residentAt($this->otherSite, 'Forest'));
    }

    public function test_site_viewer_report_totals_and_lists_cover_only_their_sites(): void
    {
        $viewer = $this->staffAt($this->localSite, ['fleet.viewAny']);

        $this->actingAs($viewer)
            ->get('/fleet-assets/reports')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('fleet-assets/reports/index')
                ->where('trip_stats.total_trips', 2)
                // Whole-number floats come back as ints from the Inertia JSON round-trip.
                ->where('trip_stats.total_distance_km', 20)
                ->where('utilization', fn ($rows): bool => collect($rows)->pluck('vehicle')->all() === ['Harbour Van'])
                ->where('fuel_stats.total_fill_ups', 1)
                ->where('fuel_by_vehicle', fn ($rows): bool => collect($rows)->pluck('vehicle')->all() === ['Harbour Van'])
                ->where('maintenance_stats.total_work_orders', 1)
                ->where('compliance.expiring_items', fn ($items): bool => collect($items)->pluck('vehicle_id')->unique()->values()->all()
                    === [$this->localVehicle->id])
                ->where('incident_stats.total', 1)
                ->where('incident_stats.open', 1)
                ->where('vehicle_utilisation', fn ($rows): bool => collect($rows)->pluck('id')->all() === [$this->localVehicle->id])
                ->where('trends.5.trips', 2)
                ->where('trends.5.incidents', 1)
                // The Forest driver is not staff this viewer can see, so gets no row
                // even though the incident on the Harbour van is theirs.
                ->where('staff_risk', fn ($rows): bool => collect($rows)->pluck('name')->all() === ['Harbour Driver'])
                ->where('resident_demand.residents', fn ($rows): bool => collect($rows)->pluck('id')->all() === [$this->localResident->id])
                ->where('resident_demand.purpose_breakdown', fn ($breakdown): bool => collect($breakdown)->keys()->all() === ['community']
                    && (int) collect($breakdown)->get('community') === 1));
    }

    public function test_site_viewer_csv_exports_cover_only_their_sites_and_visible_drivers(): void
    {
        $viewer = $this->staffAt($this->localSite, ['fleet.viewAny']);

        $trips = $this->export($viewer, 'trips');
        $this->assertStringContainsString('Harbour Van', $trips);
        $this->assertStringContainsString('Harbour Driver', $trips);
        $this->assertStringNotContainsString('Forest Van', $trips);
        $this->assertStringNotContainsString('Forest Driver', $trips);
        $this->assertSame(3, substr_count(trim($trips), "\n") + 1, 'header plus both Harbour van trips');

        $fuel = $this->export($viewer, 'fuel');
        $this->assertStringContainsString('Harbour Van', $fuel);
        $this->assertStringNotContainsString('Forest Van', $fuel);

        $maintenance = $this->export($viewer, 'maintenance');
        $this->assertStringContainsString('Harbour Van brake check', $maintenance);
        $this->assertStringNotContainsString('Forest Van', $maintenance);

        $compliance = $this->export($viewer, 'compliance');
        $this->assertStringContainsString('Harbour Van', $compliance);
        $this->assertStringNotContainsString('Forest Van', $compliance);
    }

    public function test_site_viewer_house_usage_and_reimbursement_cover_only_their_sites(): void
    {
        $viewer = $this->staffAt($this->localSite, ['fleet.viewAny']);

        $this->actingAs($viewer)
            ->get('/fleet-assets/reports/by-house')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('fleet-assets/reports/by-house')
                ->where('houses', fn ($houses): bool => collect($houses)->pluck('id')->all() === [$this->localSite->id])
                ->where('house_summaries.0.id', $this->localSite->id)
                ->where('house_summaries.0.vehicles_count', 1)
                ->where('house_summaries.0.trips_this_month', 2)
                ->where('house_summaries.0.transport_logs', 1)
                ->has('house_summaries', 1));

        // A foreign house answers exactly like a missing one.
        $missingSiteId = (int) Site::query()->max('id') + 1000;
        foreach ([$this->otherSite->id, $missingSiteId] as $hiddenHouseId) {
            $this->actingAs($viewer)
                ->get("/fleet-assets/reports/by-house?house_id={$hiddenHouseId}")
                ->assertNotFound();
        }

        $this->actingAs($viewer)
            ->get("/fleet-assets/reports/by-house?house_id={$this->localSite->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('vehicle_details', fn ($rows): bool => collect($rows)->pluck('id')->all() === [$this->localVehicle->id]));

        // The Forest driver's Harbour van trip is counted but not attributed to them.
        $staff = collect($this->actingAs($viewer)
            ->getJson('/fleet-assets/reports/reimbursement/data?period=this_month&rate=1')
            ->assertOk()
            ->json('staff'));

        $this->assertEqualsCanonicalizing(['Harbour Driver', 'Unknown'], $staff->pluck('name')->all());
        $this->assertEqualsWithDelta(20.0, $staff->sum('distance_km'), 0.001);
    }

    public function test_viewer_without_a_current_site_sees_nothing(): void
    {
        // Without a current HR Site there is nothing to report: the boundary fails closed.
        $unplaced = User::factory()->create(['approved_at' => now()]);
        $this->grant($unplaced, ['fleet.viewAny']);

        $this->actingAs($unplaced)
            ->get('/fleet-assets/reports')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('trip_stats.total_trips', 0)
                ->where('fuel_stats.total_fill_ups', 0)
                ->where('maintenance_stats.total_work_orders', 0)
                ->where('incident_stats.total', 0)
                ->where('incident_stats.open', 0)
                ->has('utilization', 0)
                ->has('compliance.expiring_items', 0)
                ->has('vehicle_utilisation', 0)
                ->has('staff_risk', 0)
                ->has('resident_demand.residents', 0));

        foreach (['trips', 'fuel', 'maintenance', 'compliance'] as $type) {
            $csv = $this->export($unplaced, $type);
            $this->assertStringNotContainsString('Harbour', $csv, "{$type} export");
            $this->assertStringNotContainsString('Forest', $csv, "{$type} export");
        }

        $this->actingAs($unplaced)
            ->get('/fleet-assets/reports/by-house')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('houses', 0)
                ->has('house_summaries', 0));

        $this->actingAs($unplaced)
            ->getJson('/fleet-assets/reports/reimbursement/data?period=this_month')
            ->assertOk()
            ->assertJsonCount(0, 'staff');
    }

    public function test_fleet_manage_is_the_explicit_all_sites_bypass(): void
    {
        $fleetManager = $this->staffAt($this->localSite, ['fleet.viewAny', 'fleet.manage']);

        $this->actingAs($fleetManager)
            ->get('/fleet-assets/reports')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('trip_stats.total_trips', 3)
                ->where('fuel_stats.total_fill_ups', 2)
                ->where('maintenance_stats.total_work_orders', 2)
                ->where('incident_stats.total', 2)
                ->where('vehicle_utilisation', fn ($rows): bool => collect($rows)->pluck('id')->sort()->values()->all()
                    === collect([$this->localVehicle->id, $this->otherVehicle->id])->sort()->values()->all())
                ->where('staff_risk', fn ($rows): bool => collect($rows)->pluck('name')->sort()->values()->all()
                    === ['Forest Driver', 'Harbour Driver'])
                ->has('resident_demand.residents', 2));

        $trips = $this->export($fleetManager, 'trips');
        $this->assertStringContainsString('Forest Van', $trips);
        $this->assertStringContainsString('Forest Driver', $trips);
        $this->assertStringContainsString('Forest Van tyre swap', $this->export($fleetManager, 'maintenance'));

        $this->actingAs($fleetManager)
            ->get("/fleet-assets/reports/by-house?house_id={$this->otherSite->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('houses', fn ($houses): bool => collect($houses)->pluck('id')->contains($this->localSite->id)
                    && collect($houses)->pluck('id')->contains($this->otherSite->id))
                ->where('vehicle_details', fn ($rows): bool => collect($rows)->pluck('id')->all() === [$this->otherVehicle->id]));

        $staff = collect($this->actingAs($fleetManager)
            ->getJson('/fleet-assets/reports/reimbursement/data?period=this_month&rate=1')
            ->assertOk()
            ->json('staff'));
        $this->assertEqualsCanonicalizing(['Forest Driver', 'Harbour Driver'], $staff->pluck('name')->all());
    }

    private function export(User $viewer, string $type): string
    {
        return $this->actingAs($viewer)
            ->get("/fleet-assets/reports/export?type={$type}")
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

    private function vehicleAt(Site $site, string $name): Asset
    {
        return Asset::factory()->vehicle()->create([
            'site_id' => $site->id,
            'home_site_id' => $site->id,
            'client_id' => null,
            'category' => 'vehicle',
            'status' => 'active',
            'name' => $name,
            'wof_expires_at' => now()->addDays(20),
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

    private function fuelLog(Asset $vehicle, User $user): FleetFuelLog
    {
        return FleetFuelLog::query()->create([
            'asset_id' => $vehicle->id,
            'user_id' => $user->id,
            'logged_at' => now()->subDays(2),
            'fuel_type' => 'diesel',
            'quantity_litres' => 40,
            'cost_per_litre' => 2.5,
            'total_cost' => 100,
            'full_tank' => true,
        ]);
    }

    private function workOrder(Asset $vehicle, string $title): FleetWorkOrder
    {
        return FleetWorkOrder::factory()->create([
            'asset_id' => $vehicle->id,
            'title' => $title,
            'status' => 'open',
        ]);
    }

    private function incident(Asset $vehicle, User $driver): FleetIncident
    {
        return FleetIncident::factory()->create([
            'asset_id' => $vehicle->id,
            'driver_user_id' => $driver->id,
            'incident_type' => 'damage',
            'severity' => 'minor',
            'status' => 'reported',
            'occurred_at' => now()->subDay(),
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
}
