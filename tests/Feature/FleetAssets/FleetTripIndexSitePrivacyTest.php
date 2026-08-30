<?php

namespace Tests\Feature\FleetAssets;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\Client;
use App\Models\FleetDriverSession;
use App\Models\FleetTrip;
use App\Models\FleetTripSegment;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FleetTripIndexSitePrivacyTest extends TestCase
{
    use RefreshDatabase;

    private Site $visibleSite;

    private Site $hiddenSite;

    private Asset $visibleVehicle;

    private Asset $hiddenVehicle;

    private User $viewer;

    private User $visibleDriver;

    private User $hiddenDriver;

    private FleetTrip $visibleTrip;

    private FleetTrip $visibleTripWithHiddenDriver;

    private FleetTrip $hiddenTrip;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->travelTo(Carbon::parse('2026-08-30 12:00:00', config('app.timezone')));

        $this->visibleSite = Site::factory()->create([
            'name' => 'RUN176 Harbour Site',
            'is_active' => true,
            'archived' => false,
            'archived_at' => null,
        ]);
        $this->hiddenSite = Site::factory()->create([
            'name' => 'RUN176 Forest Site',
            'is_active' => true,
            'archived' => false,
            'archived_at' => null,
        ]);

        $this->viewer = $this->siteUser($this->visibleSite, 'RUN176 Site Viewer');
        $this->grantPermission($this->viewer, 'fleet.viewAny');

        $this->visibleDriver = $this->siteUser($this->visibleSite, 'RUN176 Visible Driver');
        $this->hiddenDriver = $this->siteUser($this->hiddenSite, 'RUN176 HIDDEN DRIVER');

        $this->visibleVehicle = Asset::factory()->vehicle()->create([
            'site_id' => $this->visibleSite->id,
            'home_site_id' => $this->hiddenSite->id,
            'name' => 'RUN176 Harbour Van',
            'asset_tag' => 'RUN176-VISIBLE-VAN',
            'status' => 'active',
        ]);
        $this->hiddenVehicle = Asset::factory()->vehicle()->create([
            'site_id' => $this->hiddenSite->id,
            'home_site_id' => $this->visibleSite->id,
            'name' => 'RUN176 HIDDEN FOREST VAN',
            'asset_tag' => 'RUN176-HIDDEN-VAN',
            'status' => 'active',
        ]);

        $visibleTripStartedAt = now()->startOfDay()->addHours(7);
        $hiddenTripStartedAt = now()->startOfDay()->addHours(6);
        $visibleSession = $this->driverSession($this->visibleVehicle, $this->visibleDriver, $visibleTripStartedAt);
        $hiddenDriverSession = $this->driverSession($this->visibleVehicle, $this->hiddenDriver, now()->subHour());
        $hiddenTripSession = $this->driverSession($this->hiddenVehicle, $this->hiddenDriver, $hiddenTripStartedAt);

        $this->visibleTrip = $this->trip($this->visibleVehicle, $visibleSession, [
            'started_at' => $visibleTripStartedAt,
            'ended_at' => $visibleTripStartedAt->copy()->addMinutes(10),
            'distance_km' => 11.25,
            'duration_s' => 600,
            'start_latitude' => -36.8100000,
            'start_longitude' => 174.7100000,
            'end_latitude' => -36.8200000,
            'end_longitude' => 174.7200000,
            'start_address' => 'RUN176 Harbour Start',
            'end_address' => 'RUN176 Harbour End',
            'status' => 'closed',
        ]);
        FleetTripSegment::query()->create([
            'fleet_trip_id' => $this->visibleTrip->id,
            'seq' => 1,
            'started_at' => $visibleTripStartedAt,
            'ended_at' => $visibleTripStartedAt->copy()->addMinutes(10),
            'distance_km' => 11.25,
            'duration_s' => 600,
            'polyline' => json_encode([[-36.81, 174.71], [-36.82, 174.72]], JSON_THROW_ON_ERROR),
        ]);

        $this->visibleTripWithHiddenDriver = $this->trip($this->visibleVehicle, $hiddenDriverSession, [
            'started_at' => now()->subHour(),
            'ended_at' => now()->subHour()->addMinutes(10),
            'distance_km' => 5.75,
            'duration_s' => 600,
            'start_address' => 'RUN176 Harbour Second Start',
            'end_address' => 'RUN176 Harbour Second End',
            'status' => 'closed',
        ]);

        $this->hiddenTrip = $this->trip($this->hiddenVehicle, $hiddenTripSession, [
            'started_at' => $hiddenTripStartedAt,
            'ended_at' => null,
            'distance_km' => 99.75,
            'duration_s' => 900,
            'start_latitude' => -36.9900000,
            'start_longitude' => 174.9900000,
            'end_latitude' => -36.9800000,
            'end_longitude' => 174.9800000,
            'start_address' => 'RUN176 HIDDEN FOREST START',
            'end_address' => 'RUN176 HIDDEN FOREST END',
            'status' => 'open',
        ]);
        FleetTripSegment::query()->create([
            'fleet_trip_id' => $this->hiddenTrip->id,
            'seq' => 1,
            'started_at' => $hiddenTripStartedAt,
            'ended_at' => null,
            'distance_km' => 99.75,
            'duration_s' => 900,
            'polyline' => json_encode([[-36.99, 174.99], [-36.98, 174.98]], JSON_THROW_ON_ERROR),
        ]);
    }

    public function test_trip_index_scopes_rows_nested_identity_filters_and_every_aggregate_to_approved_sites(): void
    {
        HrEmployeeProfile::query()
            ->where('user_id', $this->visibleDriver->id)
            ->update([
                'is_active' => false,
                'end_date' => now()->subDay()->toDateString(),
            ]);
        $this->visibleDriver->forceFill(['approved_at' => null])->save();

        $expectedTripIds = [
            $this->visibleTripWithHiddenDriver->id,
            $this->visibleTrip->id,
        ];

        $this->actingAs($this->viewer)
            ->get('/fleet-assets/trips')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('fleet-assets/trips/index')
                ->where('trips.data', function ($rows) use ($expectedTripIds): bool {
                    $trips = collect($rows);
                    $visible = $trips->firstWhere('id', $this->visibleTrip->id);
                    $hiddenDriverTrip = $trips->firstWhere('id', $this->visibleTripWithHiddenDriver->id);

                    $this->assertSame($expectedTripIds, $trips->pluck('id')->all());
                    $this->assertSame($this->visibleVehicle->id, data_get($visible, 'asset.id'));
                    $this->assertSame('RUN176 Harbour Van', data_get($visible, 'asset.name'));
                    $this->assertSame($this->visibleDriver->id, data_get($visible, 'driver.id'));
                    $this->assertSame('RUN176 Visible Driver', data_get($visible, 'driver.name'));
                    $this->assertSame('RUN176 Harbour Start', data_get($visible, 'start_address'));
                    $this->assertSame(-36.81, data_get($visible, 'segments.0.polyline.0.0'));
                    $this->assertNull(data_get($hiddenDriverTrip, 'driver'));
                    $this->assertStringNotContainsString('RUN176 HIDDEN', (string) json_encode($rows));

                    return true;
                })
                ->where('trips.meta.total', 2)
                ->where('vehicles', fn ($vehicles): bool => collect($vehicles)->all() === [[
                    'id' => $this->visibleVehicle->id,
                    'name' => 'RUN176 Harbour Van',
                ]])
                ->where('summary.total_trips', 2)
                ->where('summary.total_distance_km', 17)
                ->where('summary.total_duration_s', 1200)
                ->where('summary.avg_speed_kph', 0)
                ->where('summary.avg_distance_km', 8.5)
                ->where('summary.active_trips', 0)
                ->where('trips_by_day', fn ($days): bool => collect($days)->sum('value') === 2)
                ->where('top_vehicles', fn ($vehicles): bool => collect($vehicles)->all() === [[
                    'label' => 'RUN176 Harbour Van',
                    'value' => 17,
                ]])
                ->where('distance_trend', [11.3, 5.8])
                ->where('hero.trips_today', 2)
                ->where('hero.distance_today_km', 17)
                ->where('hero.active_now', 0)
                ->where('hero.after_hours_7d', 2)
            );
    }

    public function test_trip_csv_export_excludes_hidden_site_rows_and_hidden_nested_driver_identity(): void
    {
        $response = $this->actingAs($this->viewer)
            ->get('/fleet-assets/trips?export=csv')
            ->assertOk()
            ->assertDownload('trips-export.csv');

        $csv = $response->streamedContent();

        $this->assertStringContainsString('RUN176 Harbour Van', $csv);
        $this->assertStringContainsString('RUN176 Visible Driver', $csv);
        $this->assertStringContainsString('RUN176 Harbour Start', $csv);
        $this->assertStringContainsString('RUN176 Harbour Second Start', $csv);
        $this->assertStringNotContainsString('RUN176 HIDDEN FOREST VAN', $csv);
        $this->assertStringNotContainsString('RUN176 HIDDEN DRIVER', $csv);
        $this->assertStringNotContainsString('RUN176 HIDDEN FOREST START', $csv);

        $rows = preg_split('/\r\n|\r|\n/', trim($csv));
        $parsed = array_map(fn (string $row): array => str_getcsv($row), $rows ?: []);
        $header = array_shift($parsed);
        $driverColumn = array_search('Driver', $header, true);
        $startColumn = array_search('Start Address', $header, true);
        $redactedRow = collect($parsed)->first(
            fn (array $row): bool => ($row[$startColumn] ?? null) === 'RUN176 Harbour Second Start',
        );

        $this->assertCount(2, $parsed);
        $this->assertIsArray($redactedRow);
        $this->assertSame('', $redactedRow[$driverColumn] ?? null);
    }

    public function test_vehicle_and_legacy_asset_filters_conceal_foreign_and_missing_vehicles(): void
    {
        foreach (['vehicle_id', 'asset_id'] as $parameter) {
            $this->actingAs($this->viewer)
                ->get('/fleet-assets/trips?'.http_build_query([$parameter => $this->visibleVehicle->id]))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('trips.meta.total', 2)
                    ->where('trips.data', fn ($rows): bool => collect($rows)->pluck('id')->sort()->values()->all() === collect([
                        $this->visibleTrip->id,
                        $this->visibleTripWithHiddenDriver->id,
                    ])->sort()->values()->all()));

            foreach ([$this->hiddenVehicle->id, 987654321] as $vehicleId) {
                $this->actingAs($this->viewer)
                    ->get('/fleet-assets/trips?'.http_build_query([$parameter => $vehicleId]))
                    ->assertNotFound();
            }
        }

        $this->actingAs($this->viewer)
            ->get('/fleet-assets/trips?'.http_build_query([
                'vehicle_id' => $this->visibleVehicle->id,
                'asset_id' => $this->hiddenVehicle->id,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('trips.meta.total', 2));

        $this->actingAs($this->viewer)
            ->get('/fleet-assets/trips?'.http_build_query([
                'vehicle_id' => $this->hiddenVehicle->id,
                'asset_id' => $this->visibleVehicle->id,
            ]))
            ->assertNotFound();
    }

    public function test_fleet_manager_sees_all_operational_sites_but_not_archived_site_or_driver_identity(): void
    {
        $this->grantPermission($this->viewer, 'fleet.manage');

        $archivedSite = Site::factory()->create([
            'name' => 'RUN176 Archived Site',
            'is_active' => false,
            'archived' => true,
            'archived_at' => now(),
        ]);
        $archivedDriver = $this->siteUser($archivedSite, 'RUN176 ARCHIVED DRIVER');
        $archivedVehicle = Asset::factory()->vehicle()->create([
            'site_id' => $archivedSite->id,
            'home_site_id' => $archivedSite->id,
            'name' => 'RUN176 ARCHIVED VEHICLE',
            'asset_tag' => 'RUN176-ARCHIVED-VAN',
            'status' => 'active',
        ]);

        $archivedDriverSession = $this->driverSession(
            $this->visibleVehicle,
            $archivedDriver,
            now()->subMinutes(15),
        );
        $visibleTripWithArchivedDriver = $this->trip($this->visibleVehicle, $archivedDriverSession, [
            'started_at' => now()->subMinutes(15),
            'ended_at' => now()->subMinutes(10),
            'distance_km' => 3.25,
            'duration_s' => 300,
            'start_address' => 'RUN176 Manager Local Start',
            'end_address' => 'RUN176 Manager Local End',
            'status' => 'closed',
        ]);

        $archivedTripSession = $this->driverSession($archivedVehicle, $archivedDriver, now()->subMinutes(10));
        $archivedTrip = $this->trip($archivedVehicle, $archivedTripSession, [
            'started_at' => now()->subMinutes(10),
            'ended_at' => null,
            'distance_km' => 55.5,
            'duration_s' => 600,
            'start_address' => 'RUN176 ARCHIVED PRIVATE START',
            'end_address' => 'RUN176 ARCHIVED PRIVATE END',
            'status' => 'open',
        ]);

        $expectedTripIds = [
            $visibleTripWithArchivedDriver->id,
            $this->visibleTripWithHiddenDriver->id,
            $this->visibleTrip->id,
            $this->hiddenTrip->id,
        ];

        $this->actingAs($this->viewer)
            ->get('/fleet-assets/trips')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('trips.data', function ($rows) use ($expectedTripIds, $visibleTripWithArchivedDriver, $archivedTrip): bool {
                    $trips = collect($rows);
                    $operationalForeignTrip = $trips->firstWhere('id', $this->visibleTripWithHiddenDriver->id);
                    $archivedDriverTrip = $trips->firstWhere('id', $visibleTripWithArchivedDriver->id);

                    $this->assertSame($expectedTripIds, $trips->pluck('id')->all());
                    $this->assertSame($this->hiddenDriver->id, data_get($operationalForeignTrip, 'driver.id'));
                    $this->assertNull(data_get($archivedDriverTrip, 'driver'));
                    $this->assertFalse($trips->contains('id', $archivedTrip->id));
                    $this->assertStringNotContainsString('RUN176 ARCHIVED', (string) json_encode($rows));

                    return true;
                })
                ->where('trips.meta.total', 4)
                ->where('vehicles', fn ($vehicles): bool => collect($vehicles)->pluck('id')->sort()->values()->all() === collect([
                    $this->visibleVehicle->id,
                    $this->hiddenVehicle->id,
                ])->sort()->values()->all())
                ->where('summary.total_trips', 4)
                ->where('summary.total_distance_km', 120)
                ->where('summary.total_duration_s', 2400)
                ->where('summary.avg_speed_kph', 0)
                ->where('summary.avg_distance_km', 30)
                ->where('summary.active_trips', 1)
                ->where('trips_by_day', fn ($days): bool => collect($days)->sum('value') === 4)
                ->where('top_vehicles', fn ($vehicles): bool => collect($vehicles)->all() === [
                    ['label' => 'RUN176 HIDDEN FOREST VAN', 'value' => 99.8],
                    ['label' => 'RUN176 Harbour Van', 'value' => 20.3],
                ])
                ->where('distance_trend', [99.8, 11.3, 5.8, 3.3])
                ->where('hero.trips_today', 4)
                ->where('hero.distance_today_km', 120)
                ->where('hero.active_now', 1)
                ->where('hero.after_hours_7d', 4));

        $csv = $this->actingAs($this->viewer)
            ->get('/fleet-assets/trips?export=csv')
            ->assertOk()
            ->assertDownload('trips-export.csv')
            ->streamedContent();

        $this->assertStringContainsString('RUN176 HIDDEN FOREST VAN', $csv);
        $this->assertStringContainsString('RUN176 HIDDEN DRIVER', $csv);
        $this->assertStringNotContainsString('RUN176 ARCHIVED VEHICLE', $csv);
        $this->assertStringNotContainsString('RUN176 ARCHIVED DRIVER', $csv);
        $this->assertStringNotContainsString('RUN176 ARCHIVED PRIVATE START', $csv);
    }

    public function test_trip_universe_uses_canonical_direct_home_and_client_site_provenance(): void
    {
        $visibleClient = Client::factory()->create(['site_id' => $this->visibleSite->id]);
        $hiddenClient = Client::factory()->create(['site_id' => $this->hiddenSite->id]);

        $homeFallback = Asset::factory()->vehicle()->create([
            'site_id' => null,
            'home_site_id' => $this->visibleSite->id,
            'client_id' => null,
            'name' => 'RUN176 PROVENANCE HOME VISIBLE',
        ]);
        $clientFallback = Asset::factory()->vehicle()->create([
            'site_id' => null,
            'home_site_id' => null,
            'client_id' => $visibleClient->id,
            'name' => 'RUN176 PROVENANCE CLIENT VISIBLE',
        ]);
        $directConflict = Asset::factory()->vehicle()->create([
            'site_id' => $this->visibleSite->id,
            'home_site_id' => $this->hiddenSite->id,
            'client_id' => $hiddenClient->id,
            'name' => 'RUN176 PROVENANCE DIRECT CONFLICT',
        ]);
        $homeConflict = Asset::factory()->vehicle()->create([
            'site_id' => null,
            'home_site_id' => $this->visibleSite->id,
            'client_id' => $hiddenClient->id,
            'name' => 'RUN176 PROVENANCE HOME CONFLICT',
        ]);
        $hiddenHome = Asset::factory()->vehicle()->create([
            'site_id' => null,
            'home_site_id' => $this->hiddenSite->id,
            'client_id' => null,
            'name' => 'RUN176 PROVENANCE HIDDEN HOME',
        ]);
        $unattributed = Asset::factory()->vehicle()->create([
            'site_id' => null,
            'home_site_id' => null,
            'client_id' => null,
            'name' => 'RUN176 PROVENANCE UNATTRIBUTED',
        ]);

        $assets = collect([
            $homeFallback,
            $clientFallback,
            $directConflict,
            $homeConflict,
            $hiddenHome,
            $unattributed,
        ]);
        $tripIds = [];
        foreach ($assets as $offset => $asset) {
            $startedAt = now()->subDay()->addMinutes($offset);
            $session = $this->driverSession($asset, $this->visibleDriver, $startedAt);
            $trip = $this->trip($asset, $session, [
                'started_at' => $startedAt,
                'ended_at' => $startedAt->copy()->addMinutes(5),
                'distance_km' => 1.0,
                'duration_s' => 300,
                'start_address' => "RUN176 PROVENANCE START {$offset}",
                'end_address' => "RUN176 PROVENANCE END {$offset}",
                'status' => 'closed',
            ]);
            $tripIds[$asset->id] = $trip->id;
        }

        $expectedIds = collect([
            $tripIds[$homeFallback->id],
            $tripIds[$clientFallback->id],
        ])->sort()->values()->all();

        $this->actingAs($this->viewer)
            ->get('/fleet-assets/trips?'.http_build_query(['search' => 'RUN176 PROVENANCE']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('trips.data', fn ($rows): bool => collect($rows)->pluck('id')->sort()->values()->all() === $expectedIds)
                ->where('trips.meta.total', 2)
                ->where('summary.total_trips', 2)
                ->where('summary.total_distance_km', 2));
    }

    private function siteUser(Site $site, string $name): User
    {
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
            'secondary_site_ids' => [],
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

    private function driverSession(Asset $vehicle, User $driver, Carbon $startedAt): FleetDriverSession
    {
        return FleetDriverSession::query()->create([
            'asset_id' => $vehicle->id,
            'user_id' => $driver->id,
            'started_at' => $startedAt,
            'ended_at' => $startedAt->copy()->addMinutes(10),
            'source' => 'manual',
            'status' => 'closed',
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function trip(Asset $vehicle, FleetDriverSession $session, array $attributes): FleetTrip
    {
        return FleetTrip::query()->create(array_merge([
            'asset_id' => $vehicle->id,
            'driver_session_id' => $session->id,
            'consent_blocked' => false,
            'is_personal' => false,
        ], $attributes));
    }
}
