<?php

namespace Tests\Feature\FleetAssets;

use App\Models\Asset;
use App\Models\ControlRoomAlert;
use App\Models\FleetDriverSession;
use App\Models\FleetFuelLog;
use App\Models\FleetTrip;
use App\Models\FleetWorkOrder;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FleetHeroRolloutContractTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<int, string> $extraPermissionKeys */
    private function makeFleetUser(array $extraPermissionKeys = []): User
    {
        $this->seed(\Database\Seeders\RbacSeeder::class);

        $user = User::factory()->create(['approved_at' => now()]);

        foreach (array_merge(['fleet.viewAny', 'assets.viewAny'], $extraPermissionKeys) as $permissionKey) {
            $permission = Permission::query()->firstOrCreate(
                ['key' => $permissionKey],
                [
                    'description' => str_replace('.', ' ', $permissionKey),
                    'group' => explode('.', $permissionKey)[0],
                ],
            );

            $user->permissionOverrides()->syncWithoutDetaching([
                $permission->id => ['allowed' => true],
            ]);
        }

        return $user;
    }

    /** @return array<int, array<int, string|null>> */
    private function csvRows(string $csv): array
    {
        return collect(preg_split('/\r\n|\r|\n/', trim($csv)) ?: [])
            ->filter(fn (string $line) => $line !== '')
            ->map(fn (string $line) => str_getcsv($line, ',', '"', ''))
            ->values()
            ->all();
    }

    public function test_daily_check_and_vehicle_index_expose_live_compliance_badge_counts(): void
    {
        // This contract verifies organisation-wide badge arithmetic. Cross-site
        // totals are intentionally reserved for the explicit fleet manager bypass.
        $user = $this->makeFleetUser(['fleet.manage']);
        $site = Site::factory()->create();

        Asset::factory()->vehicle()->create([
            'wof_expires_at' => now()->addDays(10),
            'registration_expires_at' => now()->addDays(12),
            'cof_expires_at' => now()->addDays(14),
        ]);
        Asset::factory()->vehicle()->create([
            'wof_expires_at' => now()->subDay(),
            'registration_expires_at' => now()->subDay(),
            'cof_expires_at' => now()->subDay(),
        ]);

        ControlRoomAlert::factory()->fromFleet()->open()->critical()->create(['site_id' => $site->id]);
        ControlRoomAlert::factory()->fromFleet()->open()->high()->create(['site_id' => $site->id]);
        ControlRoomAlert::factory()->fromCompliance()->open()->low()->create(['site_id' => $site->id]);
        ControlRoomAlert::factory()->fromFleet()->resolved()->critical()->create(['site_id' => $site->id]);

        $this->actingAs($user)
            ->get('/fleet-assets/daily-check')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('fleet-assets/daily-check')
                ->where('compliance.wof_due', 1)
                ->where('compliance.wof_expired', 1)
                ->where('compliance.rego_due', 1)
                ->where('compliance.rego_expired', 1)
                ->where('compliance.cof_due', 1)
                ->where('compliance.cof_expired', 1)
                ->where('compliance.insurance_expiring', null)
                ->where('compliance.insurance_expired', null)
                ->where('compliance.open_alerts', 3)
                ->where('compliance.critical_alerts', 1)
            );

        $this->actingAs($user)
            ->get('/fleet-assets/vehicles')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('fleet-assets/vehicles/index')
                ->where('compliance.wof_due', 1)
                ->where('compliance.wof_expired', 1)
                ->where('compliance.rego_due', 1)
                ->where('compliance.rego_expired', 1)
                ->where('compliance.cof_due', 1)
                ->where('compliance.cof_expired', 1)
                ->where('compliance.insurance_expiring', null)
                ->where('compliance.insurance_expired', null)
                ->where('compliance.open_alerts', 3)
                ->where('compliance.critical_alerts', 1)
            );
    }

    public function test_overdue_work_order_filter_returns_only_active_past_due_work(): void
    {
        $user = $this->makeFleetUser();

        $overdueOpen = FleetWorkOrder::factory()->create([
            'title' => 'Overdue open work',
            'status' => 'open',
            'due_at' => now()->subDay(),
        ]);
        $overdueInProgress = FleetWorkOrder::factory()->create([
            'title' => 'Overdue in-progress work',
            'status' => 'in_progress',
            'due_at' => now()->subHours(2),
        ]);
        FleetWorkOrder::factory()->create([
            'status' => 'cancelled',
            'due_at' => now()->subDay(),
        ]);
        FleetWorkOrder::factory()->create(['status' => 'open', 'due_at' => now()->addDay()]);
        FleetWorkOrder::factory()->create(['status' => 'open', 'due_at' => null]);
        FleetWorkOrder::factory()->create([
            'status' => 'completed',
            'completed_at' => now()->subDays(5),
        ]);
        FleetWorkOrder::factory()->count(2)->create([
            'status' => 'completed',
            'completed_at' => now()->subDays(60),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->get('/fleet-assets/maintenance/work-orders?overdue=1')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('fleet-assets/maintenance/work-orders/index')
                ->has('work_orders.data', 2)
                ->where('work_orders.meta.total', 2)
                ->where('filters.overdue', '1')
                ->where('stats.completed_30d', 1)
            );

        $this->assertEqualsCanonicalizing(
            [$overdueOpen->id, $overdueInProgress->id],
            collect($response->inertiaProps('work_orders.data'))->pluck('id')->all(),
        );

        $this->actingAs($user)
            ->get('/fleet-assets/maintenance/work-orders?overdue=true')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('work_orders.meta.total', 8)
                ->where('filters.overdue', 'true')
            );
    }

    public function test_work_order_status_transitions_manage_the_completion_timestamp_and_hero_metric(): void
    {
        $user = $this->makeFleetUser(['fleet.maintenance.manage']);
        $workOrder = FleetWorkOrder::factory()->create([
            'status' => 'open',
            'completed_at' => null,
        ]);

        $this->actingAs($user)
            ->put("/fleet-assets/maintenance/work-orders/{$workOrder->id}", ['status' => 'completed'])
            ->assertStatus(302);

        $firstCompletion = $workOrder->fresh()->completed_at;
        $this->assertNotNull($firstCompletion);

        $this->actingAs($user)
            ->get('/fleet-assets/maintenance/work-orders')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('stats.completed_30d', 1));

        $this->actingAs($user)
            ->put("/fleet-assets/maintenance/work-orders/{$workOrder->id}", ['status' => 'in_progress'])
            ->assertStatus(302);

        $this->assertNull($workOrder->fresh()->completed_at);

        $this->actingAs($user)
            ->get('/fleet-assets/maintenance/work-orders')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('stats.completed_30d', 0));

        $this->actingAs($user)
            ->put("/fleet-assets/maintenance/work-orders/{$workOrder->id}", ['status' => 'completed'])
            ->assertStatus(302);

        $recompletedAt = $workOrder->fresh()->completed_at;
        $this->assertNotNull($recompletedAt);
        $this->assertTrue($recompletedAt->greaterThanOrEqualTo($firstCompletion));
    }

    public function test_one_year_trip_export_includes_only_trips_within_the_selected_period(): void
    {
        $user = $this->makeFleetUser();
        $withinYearVehicleName = 'Fleet Report Within Year Vehicle';
        $olderVehicleName = 'Fleet Report Older Than Year Vehicle';
        $recentVehicleName = 'Fleet Report Invalid Period Fallback Vehicle';

        $withinYearVehicle = Asset::factory()->vehicle()->create([
            'name' => $withinYearVehicleName,
        ]);
        $olderVehicle = Asset::factory()->vehicle()->create([
            'name' => $olderVehicleName,
        ]);
        $recentVehicle = Asset::factory()->vehicle()->create([
            'name' => $recentVehicleName,
        ]);

        FleetTrip::factory()->closed()->create([
            'asset_id' => $withinYearVehicle->id,
            'started_at' => now()->subMonths(6),
            'ended_at' => now()->subMonths(6)->addHour(),
            'is_personal' => false,
        ]);
        FleetTrip::factory()->closed()->create([
            'asset_id' => $olderVehicle->id,
            'started_at' => now()->subYears(2),
            'ended_at' => now()->subYears(2)->addHour(),
            'is_personal' => false,
        ]);
        FleetTrip::factory()->closed()->create([
            'asset_id' => $recentVehicle->id,
            'started_at' => now()->subDays(5),
            'ended_at' => now()->subDays(5)->addHour(),
            'is_personal' => false,
        ]);

        $response = $this->actingAs($user)
            ->get('/fleet-assets/reports/export?period=1y&type=trips');

        $response->assertOk();

        $csv = $response->streamedContent();

        $this->assertStringContainsString($withinYearVehicleName, $csv);
        $this->assertStringContainsString($recentVehicleName, $csv);
        $this->assertStringNotContainsString($olderVehicleName, $csv);

        $fallbackResponse = $this->actingAs($user)
            ->get('/fleet-assets/reports/export?period=-1&type=trips');

        $fallbackResponse->assertOk();
        $fallbackCsv = $fallbackResponse->streamedContent();

        $this->assertStringContainsString($recentVehicleName, $fallbackCsv);
        $this->assertStringNotContainsString($withinYearVehicleName, $fallbackCsv);
    }

    public function test_trip_export_queries_rows_only_when_the_download_stream_runs(): void
    {
        $user = $this->makeFleetUser();
        $vehicleName = 'Fleet Report Lazy Stream Vehicle';
        $vehicle = Asset::factory()->vehicle()->create(['name' => $vehicleName]);
        $driver = User::factory()->create(['name' => 'Fleet Report Lazy Stream Driver']);
        $startedAt = now()->subDay()->startOfHour();
        $endedAt = $startedAt->copy()->addMinutes(90);
        $driverSession = FleetDriverSession::query()->create([
            'asset_id' => $vehicle->id,
            'user_id' => $driver->id,
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'source' => 'manual',
            'status' => 'closed',
        ]);

        FleetTrip::factory()->closed()->create([
            'asset_id' => $vehicle->id,
            'driver_session_id' => $driverSession->id,
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'distance_km' => 42.125,
            'duration_s' => 5400,
            'status' => 'closed',
            'is_personal' => false,
        ]);

        $tripSelects = 0;
        DB::listen(function ($query) use (&$tripSelects): void {
            if (str_contains(strtolower($query->sql), 'fleet_trips')) {
                $tripSelects++;
            }
        });

        $response = $this->actingAs($user)
            ->get('/fleet-assets/reports/export?period=30d&type=trips');

        $response->assertOk();
        $this->assertSame(0, $tripSelects, 'Trip rows were queried before the stream callback ran.');

        $csv = $response->streamedContent();

        $this->assertGreaterThan(0, $tripSelects);
        $rows = $this->csvRows($csv);
        $this->assertCount(2, $rows);
        $this->assertSame(
            [
                $vehicleName,
                'Fleet Report Lazy Stream Driver',
                $startedAt->toDateTimeString(),
                $endedAt->toDateTimeString(),
                '42.125',
                '90',
                'closed',
            ],
            array_slice($rows[1], 1),
        );
    }

    public function test_fuel_export_queries_rows_only_when_the_download_stream_runs(): void
    {
        $user = $this->makeFleetUser();
        $vehicleName = 'Fleet Fuel Lazy Stream Vehicle';
        $vehicle = Asset::factory()->vehicle()->create(['name' => $vehicleName]);
        $fuelUser = User::factory()->create(['name' => 'Fleet Fuel Lazy Stream User']);
        $loggedAt = now()->subDay()->startOfHour();

        FleetFuelLog::query()->create([
            'asset_id' => $vehicle->id,
            'user_id' => $fuelUser->id,
            'logged_at' => $loggedAt,
            'fuel_type' => 'petrol',
            'quantity_litres' => 20,
            'cost_per_litre' => 2.5,
            'total_cost' => 50,
            'odometer_km' => 12345.6,
        ]);

        $fuelSelects = 0;
        DB::listen(function ($query) use (&$fuelSelects): void {
            if (str_contains(strtolower($query->sql), 'fleet_fuel_logs')) {
                $fuelSelects++;
            }
        });

        $response = $this->actingAs($user)
            ->get('/fleet-assets/reports/export?period=30d&type=fuel');

        $response->assertOk();
        $this->assertSame(0, $fuelSelects, 'Fuel rows were queried before the stream callback ran.');

        $csv = $response->streamedContent();

        $this->assertGreaterThan(0, $fuelSelects);
        $rows = $this->csvRows($csv);
        $this->assertCount(2, $rows);
        $this->assertSame(
            [
                $vehicleName,
                'Fleet Fuel Lazy Stream User',
                $loggedAt->toDateTimeString(),
                'petrol',
                '20.00',
                '2.500',
                '50.00',
                '12345.6',
            ],
            array_slice($rows[1], 1),
        );
    }

    public function test_driver_detail_and_mobile_dashboard_use_the_fleet_hero_family(): void
    {
        $driverSource = file_get_contents(resource_path('js/pages/fleet-assets/drivers/show.tsx'));
        $mobileSource = file_get_contents(resource_path('js/pages/fleet-assets/mobile/dashboard.tsx'));

        $this->assertIsString($driverSource);
        $this->assertStringContainsString('<FleetCompactHero', $driverSource);
        $this->assertStringContainsString('data-fleet-narrow-strategy="horizontal-scroll"', $driverSource);

        $this->assertIsString($mobileSource);
        $this->assertStringContainsString('data-fleet-mobile-hero', $mobileSource);
        $this->assertStringContainsString('text-primary-foreground', $mobileSource);
    }
}
