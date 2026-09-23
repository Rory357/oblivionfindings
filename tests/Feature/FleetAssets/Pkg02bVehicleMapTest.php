<?php

namespace Tests\Feature\FleetAssets;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Enums\LinkType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Models\Asset;
use App\Models\AssetGeofence;
use App\Models\FleetGeofenceState;
use App\Models\FleetTelemetryEvent;
use App\Models\FleetTrip;
use App\Models\FleetVehicleGeofenceAssignment;
use App\Models\FleetVehicleStateSnapshot;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Fleet\FleetGeofenceService;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PKG-02B vehicle map: Location & geofences (reported state, recorded
 * positions and their privacy, shared-boundary assignments that never start
 * monitoring) and Vehicle telemetry (technology-gated recorded samples).
 */
class Pkg02bVehicleMapTest extends TestCase
{
    use RefreshDatabase;

    private const ZONE = 'Pacific/Auckland';

    private Site $site;

    private Site $foreignSite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);
        $this->travelTo(Carbon::parse('2026-09-22 09:30:00', self::ZONE)->utc());
        $this->site = Site::factory()->create([
            'name' => 'Kōwhai House', 'is_active' => true, 'latitude' => -41.2838, 'longitude' => 174.7743,
        ]);
        $this->foreignSite = Site::factory()->create(['name' => 'Rimu House', 'is_active' => true]);
    }

    public function test_location_shows_the_reported_state_and_recent_trip_ends_within_the_viewers_sites(): void
    {
        $reader = $this->siteUser([$this->site], ['fleet.viewAny']);
        $outsider = $this->siteUser([$this->foreignSite], ['fleet.viewAny']);
        $vehicle = $this->vehicle($this->site);
        $trip = $this->trip($vehicle, '2026-09-22 08:00', 12, ['end_address' => 'Community pickup']);
        $this->trip($vehicle, '2026-09-22 08:20', 15, ['is_personal' => true, 'end_address' => 'PERSONAL HIDDEN']);
        $this->trip($vehicle, '2026-09-21 16:00', 20, ['consent_blocked' => true, 'end_address' => 'CONSENT HIDDEN']);
        $event = $this->sample($vehicle, $this->local('2026-09-22 09:25'), ['ignition' => false, 'speed_kph' => 0]);
        $this->state($vehicle, $event, ['last_trip_id' => $trip->id]);
        $url = "/fleet-assets/vehicles/{$vehicle->id}/location";

        $response = $this->actingAs($reader)->getJson($url)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('tracker.linked', true)
            ->assertJsonPath('positions_visible', true)
            ->assertJsonPath('state.fresh', true)
            ->assertJsonPath('state.withheld', null)
            ->assertJsonPath('state.lat', -41.2865)
            ->assertJsonPath('state.ignition', false)
            ->assertJsonPath('state.motion', 'stationary')
            ->assertJsonPath('state.trip_id', $trip->id)
            ->assertJsonPath('vehicle.home_site.name', 'Kōwhai House')
            ->assertJsonPath('vehicle.home_site.lat', -41.2838)
            ->assertJsonPath('observations.0.id', 'current')
            ->assertJsonPath('observations.1.id', 'trip:'.$trip->id)
            ->assertJsonPath('observations.1.address', 'Community pickup')
            ->assertJsonCount(2, 'observations')
            // Alert counts and telemetry follow their own permissions.
            ->assertJsonPath('alerts.open', null)
            ->assertJsonPath('can.view_telemetry', false)
            ->assertJsonPath('geofences.can.manage', false);
        $this->assertStringNotContainsString('HIDDEN', (string) $response->getContent());

        $this->actingAs($outsider)->getJson($url)->assertNotFound();
        $this->actingAs($reader)->getJson('/fleet-assets/vehicles/987654321/location')->assertNotFound();
        $this->actingAs($this->siteUser([$this->site], []))->getJson($url)->assertForbidden();

        // Twenty minutes later the same report is last known, not current.
        $this->travel(20)->minutes();
        $this->actingAs($reader)->getJson($url)->assertOk()->assertJsonPath('state.fresh', false);
    }

    public function test_positions_during_a_personal_trip_or_without_consent_are_withheld(): void
    {
        $reader = $this->siteUser([$this->site], ['fleet.viewAny']);
        $vehicle = $this->vehicle($this->site);
        $personal = $this->trip($vehicle, '2026-09-22 09:00', 0, ['is_personal' => true, 'ended_at' => null]);
        $event = $this->sample($vehicle, $this->local('2026-09-22 09:25'));
        $this->state($vehicle, $event, ['last_trip_id' => $personal->id]);
        $url = "/fleet-assets/vehicles/{$vehicle->id}/location";

        $this->actingAs($reader)->getJson($url)->assertOk()
            ->assertJsonPath('state.withheld', 'personal')
            ->assertJsonPath('state.lat', null)
            ->assertJsonPath('state.speed_kph', null)
            ->assertJsonPath('observations.0.lat', null);
        $this->actingAs($reader)->getJson("{$url}/trail?trip={$personal->id}")->assertOk()
            ->assertJsonPath('withheld', 'personal')
            ->assertJsonPath('points', []);

        FleetVehicleStateSnapshot::query()->whereKey($vehicle->id)->update([
            'consent_blocked' => true, 'latitude' => null, 'longitude' => null, 'last_trip_id' => null,
        ]);
        $this->actingAs($reader)->getJson($url)->assertOk()->assertJsonPath('state.withheld', 'consent');

        // A business trip's route leaves out consent-blocked reports.
        $business = $this->trip($vehicle, '2026-09-21 10:00', 10);
        foreach ([0, 2, 4, 6] as $minute) {
            $this->sample($vehicle, $this->local('2026-09-21 10:00')->addMinutes($minute), [
                'latitude' => -41.2838 - $minute * 0.0001, 'longitude' => 174.7743,
            ]);
        }
        $this->sample($vehicle, $this->local('2026-09-21 10:05'), ['consent_blocked' => true, 'latitude' => null, 'longitude' => null]);
        $this->actingAs($reader)->getJson("{$url}/trail?trip={$business->id}")->assertOk()
            ->assertJsonPath('withheld', null)
            ->assertJsonPath('recorded_points', 4)
            ->assertJsonCount(4, 'points')
            ->assertJsonPath('trip.id', $business->id);
        $foreignTrip = $this->trip($this->vehicle($this->foreignSite), '2026-09-21 10:00', 10);
        $this->actingAs($reader)->getJson("{$url}/trail?trip={$foreignTrip->id}")->assertNotFound();
    }

    public function test_selecting_existing_geofences_links_them_inactive_and_replays_safely(): void
    {
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage']);
        $reader = $this->siteUser([$this->site], ['fleet.viewAny']);
        $vehicle = $this->vehicle($this->site);
        $grounds = $this->boundary($this->site, 'Kōwhai House grounds');
        $pickup = $this->boundary($this->site, 'Community pickup area', ['lat' => -41.2865, 'lng' => 174.7762]);
        $foreign = $this->boundary($this->foreignSite, 'Rimu House grounds');
        $location = "/fleet-assets/vehicles/{$vehicle->id}/location";
        $url = "/fleet-assets/vehicles/{$vehicle->id}/geofences/selection";
        $version = $this->actingAs($manager)->getJson($location)->assertOk()->json('geofences.version');
        $body = ['keep_assignment_ids' => [], 'add_geofence_ids' => [$grounds->id], 'expected_version' => $version];

        $this->actingAs($reader)->putJson($url, $body)->assertForbidden();
        $this->actingAs($manager)->putJson($url, $body)->assertOk()
            ->assertJsonPath('geofences.items.0.geofence_id', $grounds->id)
            ->assertJsonPath('geofences.items.0.monitoring', 'inactive')
            ->assertJsonPath('geofences.items.0.origin', 'linked')
            ->assertJsonPath('geofences.items.0.source_state', 'current');
        // A retry of the same selection lands once.
        $this->actingAs($manager)->putJson($url, $body)->assertOk()->assertJsonCount(1, 'geofences.items');
        $this->assertSame(1, FleetVehicleGeofenceAssignment::query()->active()->where('asset_id', $vehicle->id)->count());
        // A different change against the old version is refused.
        $this->actingAs($manager)->putJson($url, ['add_geofence_ids' => [$pickup->id]] + $body)->assertStatus(409);

        $current = $this->actingAs($manager)->getJson($location)->json('geofences');
        $this->actingAs($manager)->putJson($url, [
            'keep_assignment_ids' => [$current['items'][0]['assignment_id']], 'add_geofence_ids' => [$foreign->id],
            'expected_version' => $current['version'],
        ])->assertUnprocessable()->assertJsonValidationErrors('add_geofence_ids.0');

        // Linking never starts monitoring: the evaluator ignores these links.
        $this->assertSame(0, DB::table('asset_geofence_assignments')->count());
        app(FleetGeofenceService::class)->evaluate($vehicle, -41.2838, 174.7743, now());
        $this->assertSame(0, FleetGeofenceState::query()->count());

        $this->actingAs($manager)->putJson($url, [
            'keep_assignment_ids' => [], 'add_geofence_ids' => [], 'expected_version' => $current['version'],
        ])->assertOk()->assertJsonCount(0, 'geofences.items');
        $removed = FleetVehicleGeofenceAssignment::query()->where('asset_id', $vehicle->id)->sole();
        $this->assertSame('removed', $removed->state);
        $this->assertSame($manager->id, (int) $removed->removed_by_user_id);
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'fleet.vehicle.geofence.selection')->count());
    }

    public function test_the_wizard_creates_an_inactive_shared_boundary_owned_by_the_vehicle_site(): void
    {
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'assets.geofences.manage']);
        $vehicle = $this->vehicle($this->site);
        $url = "/fleet-assets/vehicles/{$vehicle->id}/geofences";
        $body = [
            'source' => 'new',
            'geometry' => ['type' => 'circle', 'center' => ['lat' => -41.2865, 'lng' => 174.7762], 'radius_m' => 100],
            'label' => 'Community pickup area',
            'purpose' => 'Where the van waits for the library group.',
            'response_proposal' => null,
            'schedule' => $this->schedule(),
            'request_key' => 'geo-new-1',
        ];

        $saved = $this->actingAs($manager)->postJson($url, $body)->assertOk()
            ->assertJsonPath('geofences.items.0.origin', 'created')
            ->assertJsonPath('geofences.items.0.monitoring', 'inactive')
            ->assertJsonPath('geofences.items.0.schedule.weekdays', [1, 2, 3, 4, 5]);
        $assignment = FleetVehicleGeofenceAssignment::query()->findOrFail($saved->json('assignment_id'));
        $fence = AssetGeofence::query()->findOrFail($assignment->geofence_id);
        $this->assertNull($fence->asset_id);
        $this->assertSame($this->site->id, (int) $fence->site_id);
        $this->assertSame('vehicle', $fence->scope);
        $this->assertFalse($fence->is_active);
        $this->assertSame('Community pickup area', $fence->name);

        // The same request key returns the same assignment; a different payload is refused.
        $this->actingAs($manager)->postJson($url, $body)->assertOk()->assertJsonPath('assignment_id', $assignment->id);
        $this->actingAs($manager)->postJson($url, ['purpose' => 'Something else'] + $body)->assertStatus(409);
        $this->assertSame(1, AssetGeofence::query()->count());

        // Nothing monitors the new boundary.
        app(FleetGeofenceService::class)->evaluate($vehicle, -41.2865, 174.7762, now());
        $this->assertSame(0, FleetGeofenceState::query()->count());

        $crossing = ['type' => 'polygon', 'coordinates' => [
            ['lat' => -41.28, 'lng' => 174.77], ['lat' => -41.29, 'lng' => 174.78],
            ['lat' => -41.28, 'lng' => 174.78], ['lat' => -41.29, 'lng' => 174.77],
        ]];
        $this->actingAs($manager)->postJson($url, ['geometry' => $crossing, 'request_key' => 'geo-bad-1'] + $body)
            ->assertUnprocessable()->assertJsonValidationErrors('geometry');
        $this->actingAs($manager)->postJson($url, [
            'schedule' => ['start' => '22:00', 'end' => '06:00'] + $this->schedule(), 'request_key' => 'geo-bad-2',
        ] + $body)->assertUnprocessable()->assertJsonValidationErrors('schedule.end');
        $this->actingAs($manager)->postJson($url, [
            'schedule' => ['exception_dates' => ['2026-09-27']] + $this->schedule(), 'request_key' => 'geo-bad-3',
        ] + $body)->assertUnprocessable()->assertJsonValidationErrors('schedule.exception_dates');

        // A vehicle outside the manager's sites can't own a new boundary.
        $foreignVehicle = $this->vehicle($this->foreignSite);
        $this->actingAs($manager)->postJson("/fleet-assets/vehicles/{$foreignVehicle->id}/geofences", $body)->assertNotFound();
    }

    public function test_managing_an_assignment_is_versioned_reviews_changed_sources_and_waits_for_paused_monitoring(): void
    {
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage']);
        $vehicle = $this->vehicle($this->site);
        $grounds = $this->boundary($this->site, 'Kōwhai House grounds');
        $url = "/fleet-assets/vehicles/{$vehicle->id}/geofences";
        $hash = $this->actingAs($manager)->getJson("{$url}/catalogue?q=grounds")->assertOk()
            ->assertJsonPath('boundaries.0.id', $grounds->id)->json('boundaries.0.hash');
        $details = [
            'label' => 'Home grounds', 'purpose' => 'Overnight parking at the house.',
            'response_proposal' => 'Call the house lead.', 'schedule' => $this->schedule(),
        ];

        $this->actingAs($manager)->postJson($url, ['source' => 'existing', 'geofence_id' => $grounds->id,
            'geometry_hash' => str_repeat('a', 64), 'request_key' => 'link-stale'] + $details)->assertStatus(409);
        $id = $this->actingAs($manager)->postJson($url, ['source' => 'existing', 'geofence_id' => $grounds->id,
            'geometry_hash' => $hash, 'request_key' => 'link-1'] + $details)->assertOk()->json('assignment_id');
        $this->actingAs($manager)->postJson($url, ['source' => 'existing', 'geofence_id' => $grounds->id,
            'geometry_hash' => $hash, 'request_key' => 'link-2'] + $details)
            ->assertUnprocessable()->assertJsonValidationErrors('geofence_id');

        $edit = ['source' => 'keep', 'expected_version' => 1, 'geometry_hash' => $hash, 'purpose' => 'Parking and pick-ups.'] + $details;
        $this->actingAs($manager)->putJson("{$url}/{$id}", $edit)->assertOk()->assertJsonPath('lock_version', 2);
        // The same save again reports success; a stale different edit is refused.
        $this->actingAs($manager)->putJson("{$url}/{$id}", $edit)->assertOk()->assertJsonPath('lock_version', 2);
        $this->actingAs($manager)->putJson("{$url}/{$id}", ['purpose' => 'Stale edit.'] + $edit)->assertStatus(409);

        // A changed shared boundary is flagged and must be reviewed, not silently accepted.
        $grounds->update(['shape' => ['center' => ['lat' => -41.2838, 'lng' => 174.7743], 'radius_m' => 200]]);
        $this->actingAs($manager)->getJson("/fleet-assets/vehicles/{$vehicle->id}/location")->assertOk()
            ->assertJsonPath('geofences.items.0.source_state', 'changed');
        $newHash = $this->actingAs($manager)->getJson("{$url}/catalogue")->json('boundaries.0.hash');
        $review = ['source' => 'keep', 'expected_version' => 2, 'geometry_hash' => $newHash] + $details;
        $this->actingAs($manager)->putJson("{$url}/{$id}", $review)
            ->assertUnprocessable()->assertJsonValidationErrors('source_change_reviewed');
        $this->actingAs($manager)->putJson("{$url}/{$id}", ['source_change_reviewed' => true] + $review)
            ->assertOk()->assertJsonPath('lock_version', 3);

        // A custom copy is a new boundary; the shared one is untouched.
        $copy = ['source' => 'copy', 'expected_version' => 3, 'geometry' => ['type' => 'polygon', 'coordinates' => [
            ['lat' => -41.2835, 'lng' => 174.7740], ['lat' => -41.2835, 'lng' => 174.7748], ['lat' => -41.2842, 'lng' => 174.7748],
        ]], 'label' => 'Home grounds · custom copy'] + $details;
        $this->actingAs($manager)->putJson("{$url}/{$id}", $copy)->assertOk()
            ->assertJsonPath('geofences.items.0.origin', 'copied');
        $assignment = FleetVehicleGeofenceAssignment::query()->findOrFail($id);
        $this->assertNotSame($grounds->id, (int) $assignment->geofence_id);
        $this->assertSame(200, (int) $grounds->fresh()->shape['radius_m']);
        $this->assertFalse(AssetGeofence::query()->findOrFail($assignment->geofence_id)->is_active);

        // Monitoring set up in Fleet geofences must be paused before editing.
        $monitored = $this->boundary($this->site, 'Monitored depot', ['lat' => -41.29, 'lng' => 174.78]);
        $monitoredHash = $this->actingAs($manager)->getJson("{$url}/catalogue?q=depot")->json('boundaries.0.hash');
        $linkId = $this->actingAs($manager)->postJson($url, ['source' => 'existing', 'geofence_id' => $monitored->id,
            'geometry_hash' => $monitoredHash, 'request_key' => 'link-3'] + $details)->assertOk()->json('assignment_id');
        $monitored->assignedAssets()->attach($vehicle->id);
        $this->actingAs($manager)->putJson("{$url}/{$linkId}", ['source' => 'keep', 'expected_version' => 1,
            'geometry_hash' => $monitoredHash] + $details)->assertStatus(409);
        $items = collect($this->actingAs($manager)->getJson("/fleet-assets/vehicles/{$vehicle->id}/location")->json('geofences.items'));
        $this->assertSame('on', $items->firstWhere('assignment_id', $linkId)['monitoring']);
    }

    public function test_the_catalogue_lists_permitted_boundaries_and_marks_fleet_links(): void
    {
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage']);
        $vehicle = $this->vehicle($this->site);
        $grounds = $this->boundary($this->site, 'Kōwhai House grounds');
        $this->boundary($this->foreignSite, 'RIMU HIDDEN BOUNDARY');
        $own = AssetGeofence::query()->create([
            'asset_id' => $vehicle->id, 'site_id' => null, 'name' => 'Van depot', 'type' => 'circle', 'scope' => 'vehicle',
            'shape' => ['lat' => -41.29, 'lon' => 174.78, 'radius_m' => 80], 'breach_type' => 'both', 'is_active' => true,
        ]);
        $url = "/fleet-assets/vehicles/{$vehicle->id}/geofences/catalogue";

        $response = $this->actingAs($manager)->getJson($url)->assertOk()->assertJsonCount(2, 'boundaries');
        $this->assertStringNotContainsString('RIMU HIDDEN', (string) $response->getContent());
        $boundaries = collect($response->json('boundaries'))->keyBy('id');
        $this->assertTrue($boundaries[$own->id]['fleet_link']);
        $this->assertFalse($boundaries[$grounds->id]['fleet_link']);
        $this->assertSame('circle', $boundaries[$own->id]['geometry']['type']);
        $this->actingAs($manager)->getJson("{$url}?q=depot")->assertOk()
            ->assertJsonCount(1, 'boundaries')->assertJsonPath('boundaries.0.id', $own->id);

        // The vehicle's own Fleet geofence shows as linked with its monitoring state.
        $this->actingAs($manager)->getJson("/fleet-assets/vehicles/{$vehicle->id}/location")->assertOk()
            ->assertJsonPath('geofences.items.0.origin', 'fleet_rule')
            ->assertJsonPath('geofences.items.0.monitoring', 'on')
            ->assertJsonPath('geofences.items.0.assignment_id', null);
    }

    public function test_telemetry_needs_the_vehicle_technology_view_and_withholds_private_samples(): void
    {
        $fleetOnly = $this->siteUser([$this->site], ['fleet.viewAny']);
        $technician = $this->siteUser([$this->site], ['fleet.viewAny', 'securityDevices.devices.view']);
        $admin = User::factory()->create(['approved_at' => now()]);
        $admin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
        $vehicle = $this->vehicle($this->site, ['serial_number' => 'JTFSS22P900123456']);
        $foreignVehicle = $this->vehicle($this->foreignSite);
        $device = Device::factory()->tracking()->create([
            'name' => 'Van 14 tracker', 'category' => 'vehicle_tracker', 'model' => 'GV500CG', 'last_seen_at' => now(),
        ]);
        DeviceAssetLink::query()->create([
            'device_id' => $device->id, 'asset_id' => $vehicle->id, 'link_type' => LinkType::InstalledIn, 'linked_at' => now(),
        ]);
        $latest = $this->sample($vehicle, now()->subMinutes(3)->toImmutable(), [
            'device_id' => $device->id, 'ignition' => true, 'motion_status' => 'moving', 'speed_kph' => 42,
            'odometer_km' => 82460.4, 'battery_pct' => 92, 'external_power' => true,
        ]);
        $this->sample($vehicle, now()->subHours(2)->toImmutable(), [
            'device_id' => $device->id, 'consent_blocked' => true, 'latitude' => null, 'longitude' => null,
            'speed_kph' => null, 'ignition' => false,
        ]);
        $this->trip($vehicle, '2026-09-22 03:00', 120, ['is_personal' => true]);
        $this->sample($vehicle, $this->local('2026-09-22 04:00'), [
            'device_id' => $device->id, 'speed_kph' => 50, 'odometer_km' => 82400,
        ]);
        $url = "/fleet-assets/vehicles/{$vehicle->id}/telemetry";

        $this->actingAs($fleetOnly)->getJson($url)->assertForbidden();
        $this->actingAs($technician)->getJson("/fleet-assets/vehicles/{$foreignVehicle->id}/telemetry")->assertNotFound();

        $this->actingAs($admin)->getJson($url)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('technology.summary.total', 1)
            ->assertJsonPath('technology.devices.0.model', 'GV500CG')
            ->assertJsonPath('telemetry.tracker.device_id', $device->id)
            ->assertJsonPath('telemetry.tracker.family', 'gv500cg')
            ->assertJsonPath('telemetry.current_sample_id', $latest->id)
            ->assertJsonPath('telemetry.vehicle.vin', 'JTFSS22P900123456')
            ->assertJsonPath('telemetry.samples.0.id', $latest->id)
            ->assertJsonPath('telemetry.samples.0.ignition', true)
            ->assertJsonPath('telemetry.samples.0.motion', 'moving')
            ->assertJsonPath('telemetry.samples.0.withheld', null)
            ->assertJsonPath('telemetry.samples.1.withheld', 'consent')
            ->assertJsonPath('telemetry.samples.1.speed_kph', null)
            ->assertJsonPath('telemetry.samples.2.withheld', 'personal')
            ->assertJsonPath('telemetry.samples.2.speed_kph', null)
            ->assertJsonPath('telemetry.samples.2.odometer_km', null);

        // Twenty minutes on, the latest sample is no longer current.
        $this->travel(20)->minutes();
        $this->actingAs($admin)->getJson($url)->assertOk()->assertJsonPath('telemetry.current_sample_id', null);
    }

    /** @return array<string,mixed> */
    private function schedule(): array
    {
        return [
            'timezone' => 'Pacific/Auckland', 'weekdays' => [1, 2, 3, 4, 5], 'start' => '08:00', 'end' => '18:00',
            'following_day' => false, 'first_date' => '2026-09-22', 'last_date' => '2026-12-31', 'exception_dates' => [],
        ];
    }

    /** @param  array{lat:float,lng:float}|null  $center */
    private function boundary(Site $site, string $name, ?array $center = null): AssetGeofence
    {
        return AssetGeofence::query()->create([
            'asset_id' => null, 'site_id' => $site->id, 'name' => $name, 'type' => 'circle', 'scope' => 'house',
            'shape' => ['center' => $center ?? ['lat' => -41.2838, 'lng' => 174.7743], 'radius_m' => 160],
            'breach_type' => 'both', 'is_active' => true,
        ]);
    }

    private function local(string $wallTime): CarbonImmutable
    {
        return CarbonImmutable::parse($wallTime, self::ZONE)->utc();
    }

    /** @param  array<string,mixed>  $attributes */
    private function trip(Asset $vehicle, string $startLocal, int $minutes, array $attributes = []): FleetTrip
    {
        $start = $this->local($startLocal);

        return FleetTrip::query()->create([
            'asset_id' => $vehicle->id,
            'started_at' => $start,
            'ended_at' => $start->addMinutes($minutes),
            'start_latitude' => -41.2838,
            'start_longitude' => 174.7743,
            'end_latitude' => -41.2865,
            'end_longitude' => 174.7762,
            'distance_km' => 2.4,
            'duration_s' => $minutes * 60,
            'status' => 'closed',
            'consent_blocked' => false,
            'is_personal' => false,
            ...$attributes,
        ]);
    }

    /** @param  array<string,mixed>  $attributes */
    private function sample(Asset $vehicle, CarbonImmutable $at, array $attributes = []): FleetTelemetryEvent
    {
        return FleetTelemetryEvent::query()->create([
            'asset_id' => $vehicle->id,
            'vendor' => 'queclink',
            'vendor_message_id' => Str::uuid()->toString(),
            'occurred_at' => $at,
            'received_at' => $at,
            'latitude' => -41.2865,
            'longitude' => 174.7762,
            'speed_kph' => 12,
            'event_type' => 'location_report',
            'idempotency_key' => hash('sha256', Str::uuid()->toString()),
            'raw_payload' => [],
            'consent_blocked' => false,
            ...$attributes,
        ]);
    }

    /** @param  array<string,mixed>  $attributes */
    private function state(Asset $vehicle, FleetTelemetryEvent $event, array $attributes = []): FleetVehicleStateSnapshot
    {
        return FleetVehicleStateSnapshot::query()->create([
            'asset_id' => $vehicle->id,
            'last_event_id' => $event->id,
            'last_seen_at' => $event->received_at,
            'latitude' => -41.2865,
            'longitude' => 174.7762,
            'speed_kph' => 0,
            'heading_deg' => 90,
            'ignition' => false,
            'motion_status' => 'stationary',
            'battery_pct' => 92,
            'status' => 'online',
            'consent_blocked' => false,
            ...$attributes,
        ]);
    }

    /**
     * @param  list<Site>  $sites
     * @param  list<string>  $permissions
     */
    private function siteUser(array $sites, array $permissions): User
    {
        $user = User::factory()->create(['approved_at' => now(), 'role' => 'manager']);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id, 'primary_site_id' => $sites[0]->id,
            'secondary_site_ids' => collect($sites)->skip(1)->pluck('id')->values()->all(),
            'start_date' => today()->subYear(), 'end_date' => null, 'is_active' => true,
            'created_by' => $user->id, 'updated_by' => $user->id,
        ]);
        $user->permissionOverrides()->syncWithoutDetaching(collect($permissions)->mapWithKeys(fn (string $key): array => [
            Permission::query()->firstOrCreate(['key' => $key], [
                'description' => $key, 'group' => str($key)->before('.')->value(), 'module' => str($key)->before('.')->value(),
            ])->id => ['allowed' => true],
        ])->all());
        $user->unsetRelation('permissionOverrides');

        return $user;
    }

    /** @param  array<string,mixed>  $attributes */
    private function vehicle(Site $site, array $attributes = []): Asset
    {
        return Asset::factory()->vehicle()->create([
            'site_id' => $site->id, 'home_site_id' => $site->id, 'name' => 'Kōwhai van', 'status' => 'active',
            'registration_number' => 'KWH014', ...$attributes,
        ]);
    }
}
