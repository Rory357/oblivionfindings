<?php

namespace Tests\Feature\FleetAssets;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\FleetVehicleBooking;
use App\Models\FleetVehicleReminder;
use App\Models\FleetVehicleStateSnapshot;
use App\Models\FleetWorkOrder;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Fleet\VehicleBookingAccessService;
use App\Services\Fleet\VehicleDocumentService;
use App\Services\Fleet\VehicleFinancePresenter;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Central fleet oversight (Stephan, 24 September 2026): fleet.vehicles.viewAllSites
 * opens every vehicle and its profile across Sites, without other Site access.
 * Bookings, trips, locations, drivers and Finance keep their own Site rules.
 */
class Pkg02bFleetVehiclesAllSitesTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_09_24_000200_grant_fleet_vehicles_view_all_sites.php';

    private Site $site;

    private Site $otherSite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->travelTo(Carbon::parse('2026-09-24 09:30:00', 'Pacific/Auckland')->utc());
        $this->site = Site::factory()->create(['name' => 'Kōwhai House']);
        $this->otherSite = Site::factory()->create(['name' => 'Rimu House']);
    }

    public function test_the_seeder_and_the_grant_migration_give_admin_and_the_fleet_manager_role_central_oversight(): void
    {
        $this->assertSame(RbacSeeder::FLEET_MANAGER_PERMISSIONS, array_values(array_intersect(
            RbacSeeder::FLEET_MANAGER_PERMISSIONS, $this->roleKeys('fleet_manager'),
        )));
        $this->assertContains('fleet.vehicles.viewAllSites', $this->roleKeys('admin'));

        // A deployed database never ran the seeder for this role: the migration
        // creates it with the seeder's grants and gives admin the permission.
        $this->forgetPermissionAndRole();
        $this->migrate();
        $this->assertSame('Fleet Manager', Role::query()->where('name', 'fleet_manager')->value('label'));
        $this->assertEqualsCanonicalizing(RbacSeeder::FLEET_MANAGER_PERMISSIONS, $this->roleKeys('fleet_manager'));
        $this->assertContains('fleet.vehicles.viewAllSites', $this->roleKeys('admin'));

        // Running it again changes nothing.
        $grants = DB::table('role_permission')->count();
        $this->migrate();
        $this->assertSame($grants, DB::table('role_permission')->count());
        $this->assertSame(1, Permission::query()->where('key', 'fleet.vehicles.viewAllSites')->count());

        // A Fleet Manager role someone already set up only gains the new permission.
        $this->forgetPermissionAndRole();
        $custom = Role::query()->create(['name' => 'fleet_manager', 'label' => 'Fleet lead']);
        $custom->permissions()->sync(Permission::query()->where('key', 'fleet.viewAny')->pluck('id'));
        $this->migrate();
        $this->assertEqualsCanonicalizing(['fleet.viewAny', 'fleet.vehicles.viewAllSites'], $this->roleKeys('fleet_manager'));
        $this->assertSame('Fleet lead', $custom->fresh()->label);
    }

    public function test_the_register_daily_check_and_profile_show_every_vehicle_to_central_oversight_only(): void
    {
        $central = $this->siteUser($this->site, ['fleet.viewAny', 'fleet.vehicles.viewAllSites']);
        $local = $this->siteUser($this->site, ['fleet.viewAny']);
        $own = $this->vehicle($this->site, 'Kōwhai van');
        $other = $this->vehicle($this->otherSite, 'Rimu van');
        foreach ([$own, $other] as $vehicle) {
            FleetVehicleStateSnapshot::query()->create([
                'asset_id' => $vehicle->id, 'status' => 'online', 'latitude' => -36.85, 'longitude' => 174.76,
                'speed_kph' => 42, 'last_seen_at' => now(),
            ]);
        }

        $this->actingAs($central)->get('/fleet-assets/vehicles')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('hero.total', 2)
                ->where('vehicles.data', function ($rows) use ($own, $other): bool {
                    $rows = collect($rows)->keyBy('id');

                    // The other Site's vehicle is listed, without its position
                    // (the trip Site rule) and outside bulk Site actions.
                    return $rows->count() === 2
                        && $rows[$own->id]['at_your_sites'] === true && $rows[$own->id]['state']['lat'] !== null
                        && $rows[$other->id]['at_your_sites'] === false
                        && $rows[$other->id]['state']['lat'] === null && $rows[$other->id]['state']['speed_kph'] === null
                        && $rows[$other->id]['state']['status'] === 'online';
                })->etc());
        $this->actingAs($local)->get('/fleet-assets/vehicles')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('hero.total', 1)->etc());

        $this->actingAs($central)->get('/fleet-assets/daily-check')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('vehicles', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all()
                === collect([$own->id, $other->id])->sort()->values()->all())->etc());

        $this->actingAs($central)->get("/fleet-assets/vehicles/{$other->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('workspace.vehicle.id', $other->id)
                ->where('workspace.can.view_site_records', false)
                ->where('workspace.can.book', false)
                ->where('workspace.can.view_finance', false)
                ->where('workspace.can.view_vehicle_technology', false)
                // Maintenance keeps PKG-01's Site rule.
                ->where('workspace.work.can_view', false)
                ->where('workspace.work.site_restricted', true)
                ->etc());
        $this->actingAs($central)->get("/fleet-assets/vehicles/{$own->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('workspace.can.view_site_records', true)->etc());
        $this->actingAs($local)->get("/fleet-assets/vehicles/{$other->id}")->assertNotFound();

        // The permission widens Sites, never the action: without a Fleet read it opens nothing.
        $scopeOnly = $this->siteUser($this->site, ['fleet.vehicles.viewAllSites']);
        $this->actingAs($scopeOnly)->get("/fleet-assets/vehicles/{$other->id}")->assertForbidden();
    }

    public function test_the_vehicles_own_records_can_be_kept_across_sites(): void
    {
        $central = $this->siteUser($this->site, ['fleet.viewAny', 'fleet.manage', 'fleet.vehicles.viewAllSites']);
        $local = $this->siteUser($this->site, ['fleet.viewAny', 'fleet.manage']);
        $other = $this->vehicle($this->otherSite, 'Rimu van');
        $tick = ['applicability' => 'not_applicable', 'applicability_basis' => 'Petrol car.', 'outcome' => 'recorded'];

        $this->actingAs($local)->postJson("/fleet-assets/vehicles/{$other->id}/compliance/ruc", $tick,
            ['Idempotency-Key' => 'central-ruc-local-01'])->assertNotFound();
        // Checks are recorded through Maintenance at the person's own Sites.
        $this->actingAs($central)->get("/fleet-assets/vehicles/{$other->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('workspace.can.manage', true)
                ->where('workspace.can.inspect', false)->etc());
        $this->actingAs($central)->postJson("/fleet-assets/vehicles/{$other->id}/compliance/ruc", $tick,
            ['Idempotency-Key' => 'central-ruc-other-01'])->assertOk();
        $this->actingAs($central)->postJson("/fleet-assets/vehicles/{$other->id}/odometer-observations", [
            'value_km' => 61234, 'observed_at' => now()->subMinutes(5)->toIso8601String(),
        ], ['Idempotency-Key' => 'central-odometer-01'])->assertOk();

        // Placement and driver stay with the vehicle's Site.
        $driver = $this->siteUser($this->site, []);
        $this->actingAs($central)->putJson("/fleet-assets/vehicles/{$other->id}", [
            'profile_version' => 1, 'reason' => 'Move to head office', 'home_site_id' => $this->site->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('home_site_id');
        $this->actingAs($central)->putJson("/fleet-assets/vehicles/{$other->id}", [
            'profile_version' => 1, 'reason' => 'New driver', 'primary_driver_user_id' => $driver->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('primary_driver_user_id');
        $this->actingAs($central)->putJson("/fleet-assets/vehicles/{$other->id}", [
            'profile_version' => 1, 'reason' => 'Seats counted', 'seating_capacity' => 7,
        ])->assertOk();
        $this->assertSame(7, (int) $other->fresh()->seating_capacity);
        $this->assertSame($this->otherSite->id, (int) $other->fresh()->site_id);
    }

    public function test_bookings_trips_locations_drivers_and_finance_keep_their_own_site_rules(): void
    {
        $central = $this->siteUser($this->site, ['fleet.viewAny', 'fleet.manage', 'fleet.bookings.approve', 'finance.assets.view', 'fleet.vehicles.viewAllSites']);
        $otherDriver = $this->siteUser($this->otherSite, ['fleet.viewAny']);
        $other = $this->vehicle($this->otherSite, 'Rimu van', ['primary_driver_user_id' => $otherDriver->id]);
        $booking = FleetVehicleBooking::query()->create([
            'asset_id' => $other->id, 'user_id' => $otherDriver->id, 'purpose' => 'Hospital visit',
            'starts_at' => now()->addHours(2), 'ends_at' => now()->addHours(4),
            'pickup_site_id' => $this->otherSite->id, 'return_site_id' => $this->otherSite->id, 'status' => 'approved',
        ]);

        // Driver details are withheld on the profile.
        $this->actingAs($central)->get("/fleet-assets/vehicles/{$other->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('workspace.vehicle.primary_driver', null)
                ->where('workspace.vehicle.primary_driver_withheld', true)
                ->where('eligible_drivers', [])
                ->etc());

        // Bookings: not listed, not requestable, shown on the calendar only as busy time.
        $bookings = app(VehicleBookingAccessService::class);
        $this->assertNull($bookings->vehicle($central, $other->id));
        $this->assertFalse($bookings->accessibleBookings($central)->whereKey($booking->id)->exists());
        $this->actingAs($central)->getJson("/fleet-assets/vehicles/{$other->id}/calendar/summary")->assertOk()
            ->assertJsonPath('bookings', [])->assertJsonPath('drivers', [])
            ->assertJsonPath('can.view_bookings', false)->assertJsonPath('can.request', false)
            ->assertJsonPath('can.approve', false)->assertJsonPath('can.mark_unavailable', false);
        $events = $this->actingAs($central)->getJson("/fleet-assets/vehicles/{$other->id}/calendar/events?".http_build_query([
            'start' => now()->startOfDay()->toIso8601String(), 'end' => now()->addDays(2)->toIso8601String(),
        ]))->assertOk()->json('events');
        $this->assertSame(['busy:'.$booking->id], collect($events)->where('kind', 'busy')->pluck('id')->values()->all());
        $this->assertStringNotContainsString('Hospital visit', json_encode($events));

        // Trips, positions, driving insights and alerts: not found.
        foreach (['trip-history', 'location', 'driving', 'alerts'] as $section) {
            $this->actingAs($central)->getJson("/fleet-assets/vehicles/{$other->id}/{$section}")->assertNotFound();
        }

        // Finance reads as not available, and its files, booking files and
        // driving evidence stay closed, while the vehicle's own files open.
        $finance = app(VehicleFinancePresenter::class)->present($central, $other);
        $this->assertTrue($finance['site_restricted']);
        $this->assertFalse($finance['can']['view']);
        $this->assertSame([], $finance['records']);
        $files = app(VehicleDocumentService::class);
        $this->assertTrue($files->canView($central, $other));
        $this->assertTrue($files->sourceVisible($central, $other, null, null));
        $this->assertTrue($files->sourceVisible($central, $other, 'compliance_version', 1));
        $this->assertFalse($files->sourceVisible($central, $other, 'booking', $booking->id));
        $this->assertFalse($files->sourceVisible($central, $other, 'finance_review_request', 1));
        $this->assertFalse($files->sourceVisible($central, $other, 'speed_limit', 1));
    }

    public function test_maintenance_work_stays_with_the_vehicles_site(): void
    {
        $central = $this->siteUser($this->site, ['fleet.viewAny', 'fleet.maintenance.manage', 'fleet.vehicles.viewAllSites']);
        $otherManager = $this->siteUser($this->otherSite, ['fleet.viewAny', 'fleet.maintenance.manage']);
        $other = $this->vehicle($this->otherSite, 'Rimu van');
        $work = FleetWorkOrder::factory()->create([
            'asset_id' => $other->id, 'title' => 'Replace brake pads', 'status' => 'completed', 'priority' => 'medium',
            'completion_notes' => 'Pads replaced at Rimu Motors.',
        ]);
        FleetVehicleReminder::query()->create([
            'asset_id' => $other->id, 'title' => 'Check the brakes again', 'action_text' => 'Look at the pads.',
            'source_type' => 'work_order', 'source_id' => $work->id, 'due_at' => now()->addWeek(),
            'owner_user_id' => $otherManager->id, 'state' => 'scheduled', 'lock_version' => 1,
        ]);

        // Central oversight sees the vehicle, not the other Site's Maintenance work.
        $this->actingAs($central)->get("/fleet-assets/vehicles/{$other->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('workspace.can.view_maintenance', false)
                ->where('workspace.service_history', [])
                ->where('workspace.reminders.0.source.label', 'Maintenance work')
                ->where('workspace.work.can_view', false)
                ->etc());
        $this->assertStringNotContainsString('Pads replaced', (string) $this->actingAs($central)
            ->get("/fleet-assets/vehicles/{$other->id}")->getContent());
        $this->actingAs($central)->getJson("/fleet-assets/vehicles/{$other->id}/checks")->assertOk()
            ->assertJsonPath('can.view_maintenance', false);

        // At the vehicle's Site the same records are shown.
        $this->actingAs($otherManager)->get("/fleet-assets/vehicles/{$other->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('workspace.can.view_maintenance', true)
                ->where('workspace.service_history.0.work_order_id', $work->id)
                ->where('workspace.service_history.0.notes', 'Pads replaced at Rimu Motors.')
                ->where('workspace.reminders.0.source.label', fn (string $label): bool => str_contains($label, 'Replace brake pads'))
                ->where('workspace.work.can_view', true)
                ->etc());
    }

    public function test_the_compliance_page_follows_the_register_scope(): void
    {
        $central = $this->siteUser($this->site, ['fleet.viewAny', 'fleet.vehicles.viewAllSites']);
        $local = $this->siteUser($this->site, ['fleet.viewAny']);
        $own = $this->vehicle($this->site, 'Kōwhai van');
        $other = $this->vehicle($this->otherSite, 'Rimu van');
        $ids = fn (User $user): array => collect($this->actingAs($user)->get('/fleet-assets/compliance')->assertOk()
            ->viewData('page')['props']['vehicles'])->pluck('id')->sort()->values()->all();

        $this->assertSame(collect([$own->id, $other->id])->sort()->values()->all(), $ids($central));
        $this->assertSame([$own->id], $ids($local));

        // With no evidence recorded, readiness blocks both: "Not ready", not "Expiring soon".
        $page = $this->actingAs($central)->get('/fleet-assets/compliance')->viewData('page')['props'];
        $this->assertSame(['not_ready'], collect($page['vehicles'])->pluck('status')->unique()->values()->all());
        $this->assertSame(2, $page['summary']['not_ready']);
    }

    public function test_the_fleet_settings_grant_migration_gives_admin_and_the_fleet_manager_role_the_permission(): void
    {
        $this->assertContains('fleet.settings.manage', $this->roleKeys('fleet_manager'));
        $this->assertContains('fleet.settings.manage', $this->roleKeys('admin'));

        // A deployed database never ran the seeder: the migration adds and grants it, once.
        $permissionId = Permission::query()->where('key', 'fleet.settings.manage')->value('id');
        DB::table('role_permission')->where('permission_id', $permissionId)->delete();
        Permission::query()->whereKey($permissionId)->delete();
        $settings = require base_path('database/migrations/2026_09_24_000400_grant_fleet_settings_manage.php');
        $settings->up();
        $settings->up();
        $this->assertSame(1, Permission::query()->where('key', 'fleet.settings.manage')->count());
        $this->assertContains('fleet.settings.manage', $this->roleKeys('fleet_manager'));
        $this->assertContains('fleet.settings.manage', $this->roleKeys('admin'));
        $this->assertNotContains('fleet.settings.manage', $this->roleKeys('coordinator'));
    }

    /** @return list<string> */
    private function roleKeys(string $role): array
    {
        return Role::query()->where('name', $role)->firstOrFail()->permissions()->pluck('key')->all();
    }

    private function forgetPermissionAndRole(): void
    {
        $permissionId = Permission::query()->where('key', 'fleet.vehicles.viewAllSites')->value('id');
        DB::table('role_permission')->where('permission_id', $permissionId)->delete();
        Permission::query()->whereKey($permissionId)->delete();
        $roleId = Role::query()->where('name', 'fleet_manager')->value('id');
        DB::table('role_permission')->where('role_id', $roleId)->delete();
        Role::query()->whereKey($roleId)->delete();
    }

    private function migrate(): void
    {
        (require base_path(self::MIGRATION))->up();
    }

    /** @param list<string> $permissions */
    private function siteUser(Site $site, array $permissions): User
    {
        $user = User::factory()->create(['approved_at' => now(), 'role' => 'manager']);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
            'start_date' => today()->subYear(), 'end_date' => null, 'is_active' => true,
            'created_by' => $user->id, 'updated_by' => $user->id,
        ]);
        $user->permissionOverrides()->syncWithoutDetaching(collect($permissions)->mapWithKeys(fn (string $key): array => [
            Permission::query()->firstOrCreate(['key' => $key], [
                'description' => $key, 'group' => str($key)->before('.')->value(), 'module' => str($key)->before('.')->value(),
            ])->id => ['allowed' => true],
        ])->all());

        return $user;
    }

    /** @param array<string,mixed> $attributes */
    private function vehicle(Site $site, string $name, array $attributes = []): Asset
    {
        return Asset::factory()->vehicle()->create([
            'site_id' => $site->id, 'home_site_id' => $site->id, 'name' => $name, 'status' => 'active',
            ...$attributes,
        ]);
    }
}
