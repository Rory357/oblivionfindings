<?php

namespace Tests\Feature\FleetAssets;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\Client;
use App\Models\FleetFuelLog;
use App\Models\FleetTrip;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FleetFuelIndexSitePrivacyTest extends TestCase
{
    use RefreshDatabase;

    private Site $visibleSite;

    private Site $secondarySite;

    private Site $hiddenSite;

    private User $viewer;

    private User $visibleLogger;

    private User $hiddenLogger;

    private Asset $visibleVehicle;

    private Asset $secondaryVehicle;

    private Asset $hiddenVehicle;

    private FleetFuelLog $visibleRecentLog;

    private FleetFuelLog $secondaryLog;

    private FleetFuelLog $visibleHistoricalLog;

    private FleetFuelLog $hiddenLog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->travelTo(Carbon::parse('2026-09-15 12:00:00', config('app.timezone')));

        $this->visibleSite = $this->operationalSite('RUN193 Harbour Site');
        $this->secondarySite = $this->operationalSite('RUN193 Valley Site');
        $this->hiddenSite = $this->operationalSite('RUN193 Forest Site');

        $this->viewer = $this->siteUser(
            $this->visibleSite,
            'RUN193 Site Viewer',
            [$this->secondarySite->id],
        );
        $this->grantPermission($this->viewer, 'fleet.viewAny');

        $this->visibleLogger = $this->siteUser($this->visibleSite, 'RUN193 Local Logger');
        $this->hiddenLogger = $this->siteUser($this->hiddenSite, 'RUN193 HIDDEN LOGGER');

        $this->visibleVehicle = $this->vehicle(
            $this->visibleSite,
            'RUN193 Harbour Van',
            'RUN193-VISIBLE-VAN',
        );
        $this->secondaryVehicle = $this->vehicle(
            $this->secondarySite,
            'RUN193 Valley Wagon',
            'RUN193-SECONDARY-WAGON',
        );
        $this->hiddenVehicle = $this->vehicle(
            $this->hiddenSite,
            'RUN193 HIDDEN FOREST VAN',
            'RUN193-HIDDEN-VAN',
        );

        $this->visibleRecentLog = $this->fuelLog($this->visibleVehicle, $this->visibleLogger, [
            'logged_at' => now()->subDay(),
            'quantity_litres' => 10,
            'cost_per_litre' => 2,
            'total_cost' => 20,
            'odometer_km' => 1000,
            'station_name' => 'RUN193 Harbour Fuel',
            'notes' => 'RUN193 Harbour receipt',
        ]);
        $this->secondaryLog = $this->fuelLog($this->secondaryVehicle, $this->visibleLogger, [
            'logged_at' => now()->subDays(2),
            'quantity_litres' => 5,
            'cost_per_litre' => 3,
            'total_cost' => 15,
            'odometer_km' => 500,
            'station_name' => 'RUN193 Valley Fuel',
            'notes' => 'RUN193 Valley receipt',
        ]);
        $this->hiddenLog = $this->fuelLog($this->hiddenVehicle, $this->hiddenLogger, [
            'logged_at' => now()->subDays(3),
            'quantity_litres' => 100,
            'cost_per_litre' => 9,
            'total_cost' => 900,
            'odometer_km' => 9000,
            'station_name' => 'RUN193 HIDDEN FOREST FUEL',
            'notes' => 'RUN193 HIDDEN FOREST RECEIPT',
        ]);
        $this->visibleHistoricalLog = $this->fuelLog($this->visibleVehicle, $this->visibleLogger, [
            'logged_at' => now()->subDays(20),
            'quantity_litres' => 2,
            'cost_per_litre' => 2,
            'total_cost' => 4,
            'odometer_km' => 900,
            'station_name' => 'RUN193 Historic Harbour Fuel',
            'notes' => 'RUN193 Historic Harbour receipt',
        ]);

        $this->completedTrip($this->visibleVehicle, 120);
        $this->completedTrip($this->secondaryVehicle, 40);
        $this->completedTrip($this->hiddenVehicle, 5000);
    }

    public function test_fuel_index_scopes_rows_nested_identity_dropdown_and_every_aggregate_to_approved_sites(): void
    {
        $expectedLogIds = [
            $this->visibleRecentLog->id,
            $this->secondaryLog->id,
            $this->visibleHistoricalLog->id,
        ];

        $this->actingAs($this->viewer)
            ->get('/fleet-assets/fuel')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('fleet-assets/fuel/index')
                ->where('fuel_logs.data', function ($rows) use ($expectedLogIds): bool {
                    $logs = collect($rows);

                    $this->assertSame($expectedLogIds, $logs->pluck('id')->all());
                    $this->assertSame(
                        $this->visibleLogger->id,
                        data_get($logs->firstWhere('id', $this->visibleRecentLog->id), 'user.id'),
                    );
                    $this->assertStringNotContainsString('RUN193 HIDDEN', (string) json_encode($rows));

                    return true;
                })
                ->where('fuel_logs.meta.total', 3)
                ->where('vehicles', fn ($vehicles): bool => collect($vehicles)->pluck('id')->sort()->values()->all() === collect([
                    $this->visibleVehicle->id,
                    $this->secondaryVehicle->id,
                ])->sort()->values()->all())
                ->where('hero.spend_month', 35)
                ->where('hero.litres_month', 15)
                ->where('hero.entries_30d', 3)
                ->where('hero.avg_cost_per_litre', 2.333)
                ->where('summary.total_fill_ups', 2)
                ->where('summary.total_litres', 15)
                ->where('summary.total_cost', 35)
                ->where('summary.best_efficiency.asset_id', $this->visibleVehicle->id)
                ->where('summary.best_efficiency.km_per_litre', 10)
                ->where('summary.worst_efficiency.asset_id', $this->secondaryVehicle->id)
                ->where('summary.worst_efficiency.km_per_litre', 8)
                ->where('efficiency', function ($rows): bool {
                    $efficiency = collect($rows)->keyBy('asset_id');

                    return $efficiency->keys()->map(fn ($id): int => (int) $id)->sort()->values()->all() === collect([
                        $this->visibleVehicle->id,
                        $this->secondaryVehicle->id,
                    ])->sort()->values()->all()
                        && (float) data_get($efficiency->get($this->visibleVehicle->id), 'total_litres') === 12.0
                        && (float) data_get($efficiency->get($this->visibleVehicle->id), 'total_distance_km') === 120.0
                        && (float) data_get($efficiency->get($this->secondaryVehicle->id), 'total_litres') === 5.0
                        && (float) data_get($efficiency->get($this->secondaryVehicle->id), 'total_distance_km') === 40.0;
                }));
    }

    public function test_fuel_csv_excludes_foreign_rows_vehicle_identity_and_logger_context(): void
    {
        $response = $this->actingAs($this->viewer)
            ->get('/fleet-assets/fuel?export=csv')
            ->assertOk()
            ->assertDownload('fuel-logs-export.csv');

        $csv = $response->streamedContent();

        $this->assertStringContainsString('RUN193 Harbour Van', $csv);
        $this->assertStringContainsString('RUN193 Valley Wagon', $csv);
        $this->assertStringContainsString('RUN193 Local Logger', $csv);
        $this->assertStringNotContainsString('RUN193 HIDDEN FOREST VAN', $csv);
        $this->assertStringNotContainsString('RUN193 HIDDEN LOGGER', $csv);
        $this->assertStringNotContainsString('RUN193 HIDDEN FOREST FUEL', $csv);
        $this->assertStringNotContainsString('RUN193 HIDDEN FOREST RECEIPT', $csv);

        $rows = preg_split('/\r\n|\r|\n/', trim($csv));
        $this->assertCount(4, $rows ?: []);
    }

    public function test_asset_filter_conceals_foreign_and_missing_vehicles(): void
    {
        $this->actingAs($this->viewer)
            ->get('/fleet-assets/fuel?'.http_build_query(['asset_id' => $this->visibleVehicle->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('fuel_logs.meta.total', 2)
                ->where('fuel_logs.data', fn ($rows): bool => collect($rows)->pluck('id')->all() === [
                    $this->visibleRecentLog->id,
                    $this->visibleHistoricalLog->id,
                ]));

        foreach ([$this->hiddenVehicle->id, 987654321] as $assetId) {
            $this->actingAs($this->viewer)
                ->get('/fleet-assets/fuel?'.http_build_query(['asset_id' => $assetId]))
                ->assertNotFound();
        }
    }

    public function test_fuel_universe_uses_direct_home_and_client_provenance_and_fails_closed_on_conflicts(): void
    {
        $visibleClient = Client::factory()->create(['site_id' => $this->visibleSite->id]);
        $hiddenClient = Client::factory()->create(['site_id' => $this->hiddenSite->id]);

        $homeFallback = Asset::factory()->vehicle()->create([
            'site_id' => null,
            'home_site_id' => $this->visibleSite->id,
            'client_id' => null,
            'name' => 'RUN193 PROVENANCE HOME VISIBLE',
        ]);
        $clientFallback = Asset::factory()->vehicle()->create([
            'site_id' => null,
            'home_site_id' => null,
            'client_id' => $visibleClient->id,
            'name' => 'RUN193 PROVENANCE CLIENT VISIBLE',
        ]);
        $directConflict = Asset::factory()->vehicle()->create([
            'site_id' => $this->visibleSite->id,
            'home_site_id' => $this->hiddenSite->id,
            'client_id' => $hiddenClient->id,
            'name' => 'RUN193 PROVENANCE DIRECT CONFLICT',
        ]);
        $homeConflict = Asset::factory()->vehicle()->create([
            'site_id' => null,
            'home_site_id' => $this->visibleSite->id,
            'client_id' => $hiddenClient->id,
            'name' => 'RUN193 PROVENANCE HOME CONFLICT',
        ]);
        $hiddenHome = Asset::factory()->vehicle()->create([
            'site_id' => null,
            'home_site_id' => $this->hiddenSite->id,
            'client_id' => null,
            'name' => 'RUN193 PROVENANCE HIDDEN HOME',
        ]);
        $unattributed = Asset::factory()->vehicle()->create([
            'site_id' => null,
            'home_site_id' => null,
            'client_id' => null,
            'name' => 'RUN193 PROVENANCE UNATTRIBUTED',
        ]);

        $logs = collect([
            $homeFallback,
            $clientFallback,
            $directConflict,
            $homeConflict,
            $hiddenHome,
            $unattributed,
        ])->mapWithKeys(fn (Asset $asset, int $offset): array => [
            $asset->id => $this->fuelLog($asset, $this->visibleLogger, [
                'logged_at' => now()->subHours($offset + 1),
                'quantity_litres' => 1,
                'cost_per_litre' => 10,
                'total_cost' => 10,
                'station_name' => $asset->name,
            ]),
        ]);

        $expectedIds = collect([
            $this->visibleRecentLog->id,
            $this->secondaryLog->id,
            $this->visibleHistoricalLog->id,
            $logs[$homeFallback->id]->id,
            $logs[$clientFallback->id]->id,
        ])->sort()->values()->all();

        $this->actingAs($this->viewer)
            ->get('/fleet-assets/fuel')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('fuel_logs.data', function ($rows) use ($expectedIds): bool {
                    $encoded = (string) json_encode($rows);

                    return collect($rows)->pluck('id')->sort()->values()->all() === $expectedIds
                        && ! str_contains($encoded, 'RUN193 PROVENANCE DIRECT CONFLICT')
                        && ! str_contains($encoded, 'RUN193 PROVENANCE HOME CONFLICT')
                        && ! str_contains($encoded, 'RUN193 PROVENANCE HIDDEN HOME')
                        && ! str_contains($encoded, 'RUN193 PROVENANCE UNATTRIBUTED');
                })
                ->where('fuel_logs.meta.total', 5)
                ->where('vehicles', fn ($vehicles): bool => collect($vehicles)->pluck('id')->sort()->values()->all() === collect([
                    $this->visibleVehicle->id,
                    $this->secondaryVehicle->id,
                    $homeFallback->id,
                    $clientFallback->id,
                ])->sort()->values()->all())
                ->where('summary.total_fill_ups', 4)
                ->where('summary.total_litres', 17)
                ->where('summary.total_cost', 55)
                ->where('hero.entries_30d', 5));
    }

    public function test_noncurrent_or_missing_profile_receives_empty_universe_and_permission_revocation_denies(): void
    {
        $ended = $this->siteUser($this->visibleSite, 'RUN193 Ended Viewer', [], [
            'end_date' => now()->subDay()->toDateString(),
        ]);
        $inactive = $this->siteUser($this->visibleSite, 'RUN193 Inactive Viewer', [], [
            'is_active' => false,
        ]);
        $missingProfile = User::factory()->create([
            'name' => 'RUN193 Missing Profile Viewer',
            'approved_at' => now(),
            'role' => 'support_worker',
        ]);

        foreach ([$ended, $inactive, $missingProfile] as $restrictedViewer) {
            $this->grantPermission($restrictedViewer, 'fleet.viewAny');
            $this->actingAs($restrictedViewer)
                ->get('/fleet-assets/fuel')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('fuel_logs.meta.total', 0)
                    ->where('fuel_logs.data', [])
                    ->where('vehicles', [])
                    ->where('hero.spend_month', 0)
                    ->where('hero.litres_month', 0)
                    ->where('hero.entries_30d', 0)
                    ->where('summary.total_fill_ups', 0)
                    ->where('efficiency', []));
        }

        $permission = Permission::query()->where('key', 'fleet.viewAny')->firstOrFail();
        $this->viewer->permissionOverrides()->syncWithoutDetaching([
            $permission->id => ['allowed' => false],
        ]);

        $this->actingAs($this->viewer)
            ->get('/fleet-assets/fuel')
            ->assertForbidden();
    }

    public function test_fleet_manager_sees_all_operational_sites_but_not_archived_sites(): void
    {
        $this->grantPermission($this->viewer, 'fleet.manage');

        $archivedSite = Site::factory()->create([
            'name' => 'RUN193 Archived Site',
            'is_active' => false,
            'archived' => true,
            'archived_at' => now(),
        ]);
        $archivedLogger = $this->siteUser($archivedSite, 'RUN193 ARCHIVED LOGGER');
        $archivedVehicle = $this->vehicle(
            $archivedSite,
            'RUN193 ARCHIVED VEHICLE',
            'RUN193-ARCHIVED-VEHICLE',
        );
        $archivedLog = $this->fuelLog($archivedVehicle, $archivedLogger, [
            'logged_at' => now()->subHours(6),
            'quantity_litres' => 70,
            'cost_per_litre' => 10,
            'total_cost' => 700,
            'station_name' => 'RUN193 ARCHIVED FUEL',
            'notes' => 'RUN193 ARCHIVED RECEIPT',
        ]);
        $this->completedTrip($archivedVehicle, 7000);

        $this->actingAs($this->viewer)
            ->get('/fleet-assets/fuel')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('fuel_logs.data', function ($rows) use ($archivedLog): bool {
                    $logs = collect($rows);

                    return $logs->pluck('id')->sort()->values()->all() === collect([
                        $this->visibleRecentLog->id,
                        $this->secondaryLog->id,
                        $this->hiddenLog->id,
                        $this->visibleHistoricalLog->id,
                    ])->sort()->values()->all()
                        && ! $logs->contains('id', $archivedLog->id)
                        && ! str_contains((string) json_encode($rows), 'RUN193 ARCHIVED');
                })
                ->where('fuel_logs.meta.total', 4)
                ->where('vehicles', fn ($vehicles): bool => collect($vehicles)->pluck('id')->sort()->values()->all() === collect([
                    $this->visibleVehicle->id,
                    $this->secondaryVehicle->id,
                    $this->hiddenVehicle->id,
                ])->sort()->values()->all())
                ->where('hero.spend_month', 935)
                ->where('hero.litres_month', 115)
                ->where('hero.entries_30d', 4)
                ->where('summary.total_fill_ups', 3)
                ->where('summary.total_litres', 115)
                ->where('summary.total_cost', 935)
                ->where('summary.best_efficiency.asset_id', $this->hiddenVehicle->id)
                ->where('summary.best_efficiency.km_per_litre', 50)
                ->where('summary.worst_efficiency.asset_id', $this->secondaryVehicle->id));

        $csv = $this->actingAs($this->viewer)
            ->get('/fleet-assets/fuel?export=csv')
            ->assertOk()
            ->assertDownload('fuel-logs-export.csv')
            ->streamedContent();

        $this->assertStringContainsString('RUN193 HIDDEN FOREST VAN', $csv);
        $this->assertStringContainsString('RUN193 HIDDEN LOGGER', $csv);
        $this->assertStringNotContainsString('RUN193 ARCHIVED VEHICLE', $csv);
        $this->assertStringNotContainsString('RUN193 ARCHIVED LOGGER', $csv);
    }

    private function operationalSite(string $name): Site
    {
        return Site::factory()->create([
            'name' => $name,
            'is_active' => true,
            'archived' => false,
            'archived_at' => null,
        ]);
    }

    /** @param list<int> $secondarySiteIds */
    private function siteUser(
        Site $site,
        string $name,
        array $secondarySiteIds = [],
        array $profileOverrides = [],
    ): User {
        $user = User::factory()->create([
            'name' => $name,
            'approved_at' => now(),
            'role' => 'support_worker',
        ]);

        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'position_title' => 'Support Worker',
            'position_role' => 'support_worker',
            'employment_type' => 'full_time',
            'start_date' => now()->subYear()->toDateString(),
            'end_date' => null,
            'is_active' => true,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => $secondarySiteIds,
            ...$profileOverrides,
        ]);

        return $user;
    }

    private function grantPermission(User $user, string $key): void
    {
        $permission = Permission::query()->firstOrCreate(
            ['key' => $key],
            [
                'description' => $key,
                'group' => 'fleet',
                'module' => 'fleet',
            ],
        );
        $user->permissionOverrides()->syncWithoutDetaching([
            $permission->id => ['allowed' => true],
        ]);
    }

    private function vehicle(Site $site, string $name, string $assetTag): Asset
    {
        return Asset::factory()->vehicle()->create([
            'site_id' => $site->id,
            'home_site_id' => $site->id,
            'client_id' => null,
            'name' => $name,
            'asset_tag' => $assetTag,
            'status' => 'active',
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function fuelLog(Asset $vehicle, User $user, array $attributes): FleetFuelLog
    {
        return FleetFuelLog::query()->create(array_merge([
            'asset_id' => $vehicle->id,
            'user_id' => $user->id,
            'fuel_type' => 'diesel',
            'full_tank' => true,
        ], $attributes));
    }

    private function completedTrip(Asset $vehicle, float $distanceKm): FleetTrip
    {
        return FleetTrip::query()->create([
            'asset_id' => $vehicle->id,
            'started_at' => now()->subDays(10),
            'ended_at' => now()->subDays(10)->addHour(),
            'distance_km' => $distanceKm,
            'duration_s' => 3600,
            'status' => 'completed',
            'consent_blocked' => false,
            'is_personal' => false,
        ]);
    }
}
