<?php

namespace Tests\Feature\FleetAssets;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Asset;
use App\Models\AssetGeofence;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ConsentType;
use App\Models\ConsentTypeVersion;
use App\Models\ControlRoom\Device as ControlRoomDevice;
use App\Models\ControlRoomAlert;
use App\Models\FleetFuelLog;
use App\Models\FleetOuting;
use App\Models\FleetServiceSchedule;
use App\Models\FleetSignal;
use App\Models\FleetTrip;
use App\Models\FleetVehicleBooking;
use App\Models\FleetVehicleStateSnapshot;
use App\Models\FleetWorkOrder;
use App\Models\LocationHardware;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AuthoritativeConsentFixture;
use Tests\TestCase;

class FleetDashboardResidentSiteIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Site $localSite;

    private Site $foreignSite;

    private Asset $localVehicle;

    private Asset $foreignVehicle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->travelTo(Carbon::parse('2026-07-14 19:30:00'));

        $this->localSite = Site::factory()->create([
            'name' => 'Harbour House',
            'type' => 'house',
            'latitude' => -36.8509,
            'longitude' => 174.7645,
        ]);
        $this->foreignSite = Site::factory()->create([
            'name' => 'Forest House',
            'type' => 'house',
            'latitude' => -36.8700,
            'longitude' => 174.7800,
        ]);

        $this->localVehicle = Asset::factory()->vehicle()->create([
            'site_id' => $this->localSite->id,
            'home_site_id' => $this->localSite->id,
            'name' => 'Harbour Van',
            'status' => 'active',
        ]);
        $this->foreignVehicle = Asset::factory()->vehicle()->create([
            'site_id' => $this->foreignSite->id,
            'home_site_id' => $this->foreignSite->id,
            'name' => 'Forest Van',
            'status' => 'retired',
        ]);
    }

    public function test_dashboard_scopes_every_site_attributed_metric_and_detail_row(): void
    {
        $user = $this->makeSiteUser($this->localSite, [
            'fleet.viewAny',
            'securityDevices.devices.view',
        ]);
        $this->seedDashboardSurfaces($user);

        $this->actingAs($user)
            ->get('/fleet-assets')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('fleet-assets/dashboard')
                ->has('overview.vehicles', 1)
                ->where('overview.vehicles.0.id', $this->localVehicle->id)
                ->where('overview.vehicles.0.site_id', $this->localSite->id)
                ->has('overview.sites', 1)
                ->where('overview.sites.0.id', $this->localSite->id)
                ->where('overview.attention', fn ($rows) => collect($rows)
                    ->every(fn ($row) => (int) $row['site_id'] === $this->localSite->id))
                ->missing('stats')->missing('recent_signals')
                ->missing('recent_alerts')->missing('today_outings')
            );
    }

    public function test_explicit_all_site_access_broadens_only_the_overview_resource_scope(): void
    {
        $manager = $this->makeSiteUser($this->localSite, [
            'fleet.viewAny',
            'fleet.manage',
            'securityDevices.devices.view',
            'securityDevices.devices.viewAllSites',
        ]);
        $this->seedDashboardSurfaces($manager);

        $this->actingAs($manager)
            ->get('/fleet-assets')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('fleet-assets/dashboard')
                ->has('overview.vehicles', 2)
                ->has('overview.sites', 2)
                ->missing('stats')->missing('recent_signals')
                ->missing('recent_alerts')->missing('today_outings')
            );
    }

    public function test_dashboard_marks_device_metrics_restricted_without_security_devices_permission(): void
    {
        $user = $this->makeSiteUser($this->localSite, ['fleet.viewAny', 'assets.telemetry.view']);
        $this->seedDashboardSurfaces($user);

        $this->actingAs($user)
            ->get('/fleet-assets')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('fleet-assets/dashboard')
                ->has('overview.vehicles', 1)
                ->missing('stats')->missing('recent_signals')
                ->missing('today_outings')->missing('after_hours_trips')
            );
    }

    public function test_resident_tracking_scopes_geofences_outings_and_assign_modal_identifiers(): void
    {
        $user = $this->makeSiteUser($this->localSite, [
            'fleet.viewAny',
            'assets.telemetry.view',
            'clients.viewAssigned',
        ]);
        $ids = $this->seedResidentTrackingSurfaces();
        Client::query()->findOrFail($ids['local_client'])->supportWorkers()->attach($user->id);

        $this->actingAs($user)
            ->get('/fleet-assets/resident-tracking?new=1')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('fleet-assets/resident-tracking/index')
                ->has('residents', 1)
                ->where('residents.0.client_id', $ids['local_client'])
                ->has('geofences', 1)
                ->where('geofences.0.id', (string) $ids['local_geofence'])
                ->has('active_outings', 1)
                ->where('active_outings.0.id', $ids['local_outing'])
                ->has('assign.clients', 1)
                ->where('assign.clients.0.id', $ids['local_client'])
                ->has('assign.available_trackers', 1)
                ->where('assign.available_trackers.0.id', $ids['local_spare'])
                ->where('assign.available_trackers.0.device_uid', 'LOCAL-SPARE-TRACKER')
                ->has('assign.assigned_trackers', 1)
                ->where('assign.assigned_trackers.0.id', $ids['local_assigned'])
                ->where('assign.assigned_trackers.0.client_id', $ids['local_client'])
                ->where('assign.assigned_trackers.0.device_uid', 'LOCAL-ASSIGNED-TRACKER')
            );
    }

    public function test_cross_site_resident_tracking_requires_fleet_and_security_devices_permissions(): void
    {
        $permissions = [
            'fleet.viewAny',
            'fleet.manage',
            'assets.telemetry.view',
            'clients.viewAny',
            'securityDevices.devices.viewAllSites',
        ];
        // Residents are client records: seeing another Site's also needs the
        // explicit all-Sites client grant, not device or Fleet reach alone.
        $siteScopedManager = $this->makeSiteUser($this->localSite, $permissions, 'manager');
        $manager = $this->makeSiteUser($this->localSite, [...$permissions, 'sites.viewAll'], 'manager');
        $ids = $this->seedResidentTrackingSurfaces();

        $this->actingAs($siteScopedManager)
            ->get('/fleet-assets/resident-tracking?new=1')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('fleet-assets/resident-tracking/index')
                ->has('residents', 1)
                ->where('residents.0.client_id', $ids['local_client'])
            );

        $this->actingAs($manager)
            ->get('/fleet-assets/resident-tracking?new=1')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('fleet-assets/resident-tracking/index')
                ->has('residents', 2)
                ->has('geofences', 2)
                ->has('active_outings', 2)
                ->has('assign.clients', 2)
                ->has('assign.available_trackers', 2)
                ->has('assign.assigned_trackers', 2)
            );
    }

    public function test_resident_history_conceals_foreign_client_after_parent_permission_and_preserves_parent_denial(): void
    {
        $hiddenSiteUser = $this->makeSiteUser($this->localSite, [
            'fleet.viewAny',
            'assets.telemetry.view',
            'assets.telemetry.export',
        ]);
        $sourceDeniedUser = $this->makeSiteUser($this->foreignSite, []);
        $manager = $this->makeSiteUser($this->localSite, [
            'fleet.viewAny',
            'fleet.manage',
            'assets.telemetry.view',
            'clients.viewAny',
            'securityDevices.devices.viewAllSites',
            'sites.viewAll',
        ], 'manager');
        $foreignClient = Client::factory()->create([
            'site_id' => $this->foreignSite->id,
            'status' => 'active',
        ]);
        $foreignConsent = $this->createTrackingConsent($foreignClient);
        $foreignDevice = Device::factory()->tracking()->create([
            'device_uid' => 'FOREIGN-HISTORY-TRACKER',
        ]);
        $this->assignDeviceToClient($foreignDevice, $foreignClient, $foreignConsent);

        $this->actingAs($hiddenSiteUser)
            ->get("/fleet-assets/resident-tracking/history/{$foreignClient->id}")
            ->assertNotFound();

        $this->actingAs($sourceDeniedUser)
            ->get("/fleet-assets/resident-tracking/history/{$foreignClient->id}")
            ->assertForbidden();

        $this->actingAs($manager)
            ->get("/fleet-assets/resident-tracking/history/{$foreignClient->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('fleet-assets/resident-tracking/history')
                ->where('client.id', $foreignClient->id)
            );
    }

    public function test_live_map_uses_asset_site_only_when_geofence_has_no_authoritative_site(): void
    {
        $user = $this->makeSiteUser($this->localSite, ['fleet.viewAny']);

        $local = $this->createGeofence('Local authoritative fence', $this->localVehicle, $this->localSite);
        $this->createGeofence('Foreign authoritative fence', $this->localVehicle, $this->foreignSite);
        $localFallback = $this->createGeofence('Local asset fallback', $this->localVehicle);
        $this->createGeofence('Foreign asset fallback', $this->foreignVehicle);

        $this->actingAs($user)
            ->get('/fleet-assets/map')
            ->assertRedirect('/fleet-assets/geofences?tab=map');
        $boundaries = $this->getJson('/fleet-assets/geofences/catalogue')->assertOk()
            ->assertJsonCount(2, 'data')->json('data');
        $this->assertEqualsCanonicalizing([$local->id, $localFallback->id], collect($boundaries)->pluck('id')->all());
    }

    public function test_dashboard_fleet_manage_is_application_wide_across_operational_sites(): void
    {
        $manager = $this->makeSiteUser($this->localSite, [
            'fleet.viewAny',
            'fleet.manage',
            'securityDevices.devices.view',
            'securityDevices.devices.viewAllSites',
        ]);
        $this->seedDashboardSurfaces($manager);

        $remoteSite = Site::factory()->create([
            'name' => 'Remote House',
            'type' => 'house',
            'latitude' => -36.9000,
            'longitude' => 174.8000,
        ]);
        $remoteVehicle = Asset::factory()->vehicle()->create([
            'site_id' => $remoteSite->id,
            'home_site_id' => $remoteSite->id,
            'name' => 'Remote Van',
            'status' => 'active',
        ]);
        FleetVehicleStateSnapshot::query()->create([
            'asset_id' => $remoteVehicle->id,
            'last_seen_at' => now(),
            'latitude' => -36.90,
            'longitude' => 174.80,
            'status' => 'online',
        ]);
        $remoteDevice = Device::factory()->tracking()->create([
            'device_uid' => 'REMOTE-TRACKER',
            'last_seen_at' => now(),
        ]);
        $this->linkDeviceToVehicle($remoteDevice, $remoteVehicle);

        $this->actingAs($manager)
            ->get('/fleet-assets')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('fleet-assets/dashboard')
                ->has('overview.vehicles', 3)
                ->has('overview.sites', 3)
                ->missing('stats')->missing('recent_signals')
                ->where('overview.vehicles', fn ($vehicles) => collect($vehicles)
                    ->pluck('id')
                    ->contains($remoteVehicle->id))
            );
    }

    public function test_resident_tracker_mutations_require_device_site_access_and_consent(): void
    {
        $manager = $this->makeSiteUser($this->localSite, [
            'fleet.viewAny',
            'fleet.manage',
            'assets.telemetry.view',
            'clients.viewAny',
            'securityDevices.devices.viewUnassigned',
        ], 'manager');
        $localClient = Client::factory()->create([
            'site_id' => $this->localSite->id,
            'status' => 'active',
        ]);
        $outsideSite = Site::factory()->create();
        $outsideClient = Client::factory()->create([
            'site_id' => $outsideSite->id,
            'status' => 'active',
        ]);
        $localHardware = LocationHardware::query()->create([
            'site_id' => $this->localSite->id,
            'provider' => 'manual',
            'category' => LocationHardware::CATEGORY_TRACKER,
            'name' => 'Local mutation tracker hardware',
            'status' => LocationHardware::STATUS_ONLINE,
        ]);
        $localDevice = Device::factory()->tracking()->inStock()->create([
            'device_uid' => 'LOCAL-MUTATION-TRACKER',
            'legacy_location_hardware_id' => $localHardware->id,
        ]);
        $outsideDevice = Device::factory()->tracking()->create([
            'device_uid' => 'OUTSIDE-MUTATION-TRACKER',
        ]);
        $this->assignDeviceToClient($outsideDevice, $outsideClient);

        $localConsent = $this->createTrackingConsent($localClient);
        $outsideConsent = $this->createTrackingConsent($outsideClient);

        $this->actingAs($manager)
            ->post('/fleet-assets/resident-tracking/assign', [
                'tracker_id' => $outsideDevice->id,
                'client_id' => $localClient->id,
                'consent_id' => $localConsent->id,
            ])
            ->assertNotFound();

        $this->actingAs($manager)
            ->post('/fleet-assets/resident-tracking/assign', [
                'tracker_id' => $localDevice->id,
                'client_id' => $outsideClient->id,
                'consent_id' => $outsideConsent->id,
            ])
            ->assertNotFound();

        $this->actingAs($manager)
            ->post('/fleet-assets/resident-tracking/assign', [
                'tracker_id' => $localDevice->id,
                'client_id' => $localClient->id,
                'consent_id' => $outsideConsent->id,
            ])
            ->assertSessionHasErrors('consent_id');

        $expiredConsent = $this->createTrackingConsent($localClient, [
            'expires_at' => now()->subMinute(),
        ]);
        $this->actingAs($manager)
            ->post('/fleet-assets/resident-tracking/assign', [
                'tracker_id' => $localDevice->id,
                'client_id' => $localClient->id,
                'consent_id' => $expiredConsent->id,
            ])
            ->assertSessionHasErrors('consent_id');

        $this->actingAs($manager)
            ->post('/fleet-assets/resident-tracking/assign', [
                'tracker_id' => $localDevice->id,
                'client_id' => $localClient->id,
                'consent_id' => $localConsent->id,
            ])
            ->assertRedirect();

        $this->actingAs($manager)
            ->post("/fleet-assets/resident-tracking/{$outsideDevice->id}/unassign")
            ->assertNotFound();
        $this->assertDatabaseHas('device_assignments', [
            'device_id' => $outsideDevice->id,
            'assignable_type' => DeviceAssignment::TARGET_CLIENT,
            'assignable_id' => $outsideClient->id,
            'released_at' => null,
        ]);

        $this->actingAs($manager)
            ->post("/fleet-assets/resident-tracking/{$outsideClient->id}/locate-now")
            ->assertNotFound();
        $this->actingAs($manager)
            ->post("/fleet-assets/resident-tracking/{$outsideClient->id}/acknowledge-panic")
            ->assertNotFound();
    }

    public function test_resident_location_surfaces_fail_closed_after_tracking_consent_is_withdrawn(): void
    {
        $manager = $this->makeSiteUser($this->localSite, [
            'fleet.viewAny',
            'fleet.manage',
            'assets.telemetry.view',
            'clients.viewAny',
        ], 'manager');
        $client = Client::factory()->create([
            'site_id' => $this->localSite->id,
            'status' => 'active',
        ]);
        $consent = $this->createTrackingConsent($client, [
            'status' => 'withdrawn',
            'withdrawn_at' => now()->subMinute(),
        ]);
        $device = Device::factory()->tracking()->create([
            'device_uid' => 'WITHDRAWN-CONSENT-TRACKER',
        ]);
        $this->assignDeviceToClient($device, $client, $consent);

        $this->actingAs($manager)
            ->get('/fleet-assets/resident-tracking')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('fleet-assets/resident-tracking/index')
                ->where('residents', fn ($residents) => collect($residents)
                    ->pluck('client_id')
                    ->doesntContain($client->id))
            );

        $this->actingAs($manager)
            ->get("/fleet-assets/resident-tracking/history/{$client->id}")
            ->assertForbidden();
        $this->actingAs($manager)
            ->post("/fleet-assets/resident-tracking/{$client->id}/locate-now")
            ->assertForbidden();
        $this->actingAs($manager)
            ->post("/fleet-assets/resident-tracking/{$client->id}/acknowledge-panic")
            ->assertForbidden();
    }

    public function test_withdrawn_tracking_consent_excludes_recent_alerts_hero_counts_and_wandering_payload(): void
    {
        $manager = $this->makeSiteUser($this->localSite, [
            'fleet.viewAny',
            'fleet.manage',
            'assets.telemetry.view',
            'clients.viewAny',
        ], 'manager');
        $withdrawnClient = Client::factory()->create([
            'site_id' => $this->localSite->id,
            'status' => 'active',
        ]);
        $consentedClient = Client::factory()->create([
            'site_id' => $this->localSite->id,
            'status' => 'active',
        ]);
        $withdrawnConsent = $this->createTrackingConsent($withdrawnClient, [
            'status' => 'withdrawn',
            'withdrawn_at' => now()->subMinute(),
        ]);
        $activeConsent = $this->createTrackingConsent($consentedClient);
        $withdrawnDevice = Device::factory()->tracking()->create([
            'device_uid' => 'WITHDRAWN-ALERT-TRACKER',
            'latitude' => -36.8111,
            'longitude' => 174.8111,
            'meta' => [
                'lat' => -36.8111,
                'lng' => 174.8111,
                'last_location' => 'Withdrawn private location',
            ],
        ]);
        $consentedDevice = Device::factory()->tracking()->create([
            'device_uid' => 'CONSENTED-ALERT-TRACKER',
            'latitude' => -36.8222,
            'longitude' => 174.8222,
        ]);
        $this->assignDeviceToClient($withdrawnDevice, $withdrawnClient, $withdrawnConsent);
        $this->assignDeviceToClient($consentedDevice, $consentedClient, $activeConsent);
        $withdrawnProjection = ControlRoomDevice::query()->create([
            'name' => 'Withdrawn resident alert projection',
            'type' => ControlRoomDevice::TYPE_PERSONAL_TRACKER,
            'site_id' => $this->localSite->id,
            'client_id' => $withdrawnClient->id,
            'canonical_device_id' => $withdrawnDevice->id,
            'status' => 'online',
        ]);
        $consentedProjection = ControlRoomDevice::query()->create([
            'name' => 'Consented resident alert projection',
            'type' => ControlRoomDevice::TYPE_PERSONAL_TRACKER,
            'site_id' => $this->localSite->id,
            'client_id' => $consentedClient->id,
            'canonical_device_id' => $consentedDevice->id,
            'status' => 'online',
        ]);

        $withdrawnAlert = ControlRoomAlert::factory()->open()->create([
            'site_id' => $this->localSite->id,
            'client_id' => $withdrawnClient->id,
            'device_id' => $withdrawnProjection->id,
            'source' => 'tracker',
            'alert_type' => 'wandering',
            'triggered_at' => now()->subMinute(),
            'context' => [
                'lat' => -36.8111,
                'lng' => 174.8111,
                'geofence_name' => 'Withdrawn private zone',
                'device_uid' => 'WITHDRAWN-ALERT-TRACKER',
            ],
        ]);
        $visibleAlert = ControlRoomAlert::factory()->open()->create([
            'site_id' => $this->localSite->id,
            'client_id' => $consentedClient->id,
            'device_id' => $consentedProjection->id,
            'source' => 'tracker',
            'alert_type' => 'wandering',
            'triggered_at' => now(),
        ]);

        $this->actingAs($manager)
            ->get('/fleet-assets/resident-tracking?tab=wandering')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('fleet-assets/resident-tracking/index')
                ->where('stats.active_alerts', 1)
                ->where('stats.wandering_7d', 1)
                ->where('stats.panic_7d', 0)
                ->has('recent_alerts', 1)
                ->where('recent_alerts.0.id', $visibleAlert->id)
                ->where('recent_alerts', fn ($alerts) => collect($alerts)
                    ->pluck('id')
                    ->doesntContain($withdrawnAlert->id))
                ->where('wandering.stats.active_alerts', 1)
                ->where('wandering.stats.total_this_week', 1)
                ->where('wandering.alerts.meta.total', 1)
                ->has('wandering.alerts.data', 1)
                ->where('wandering.alerts.data.0.id', $visibleAlert->id)
                ->where('wandering.alerts.data', fn ($alerts) => collect($alerts)
                    ->pluck('id')
                    ->doesntContain($withdrawnAlert->id))
            );
    }

    public function test_resident_tracking_rejects_mixed_outing_provenance_and_redacts_foreign_nested_asset(): void
    {
        $user = $this->makeSiteUser($this->localSite, [
            'fleet.viewAny',
            'assets.telemetry.view',
            'clients.viewAssigned',
        ]);
        $localClient = Client::factory()->create([
            'site_id' => $this->localSite->id,
            'status' => 'active',
        ]);
        $otherSiteClient = Client::factory()->create([
            'site_id' => $this->foreignSite->id,
            'status' => 'active',
        ]);
        $localClient->supportWorkers()->attach($user->id);

        $valid = $this->createActiveOuting($this->localVehicle, null, 'Valid local outing');
        $valid->clients()->attach($localClient->id);
        $foreignAsset = $this->createActiveOuting($this->foreignVehicle, null, 'Foreign asset mixed outing');
        $foreignAsset->clients()->attach($localClient->id);
        $foreignClient = $this->createActiveOuting($this->localVehicle, null, 'Foreign client mixed outing');
        $foreignClient->clients()->attach($otherSiteClient->id);

        $poisonedFence = $this->createGeofence(
            'Local fence with foreign nested asset',
            $this->foreignVehicle,
            $this->localSite,
        );

        $this->actingAs($user)
            ->get('/fleet-assets/resident-tracking')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('fleet-assets/resident-tracking/index')
                ->has('active_outings', 1)
                ->where('active_outings.0.id', $valid->id)
            );

        $this->actingAs($user)
            ->get('/fleet-assets/map')
            ->assertRedirect('/fleet-assets/geofences?tab=map');
        $boundaries = $this->getJson('/fleet-assets/geofences/catalogue')->assertOk()->json('data');
        $fence = collect($boundaries)->firstWhere('id', $poisonedFence->id);
        $this->assertNotNull($fence);
        $this->assertArrayNotHasKey('asset', $fence);
        $this->assertArrayNotHasKey('asset_id', $fence);
    }

    /**
     * @return array{local_signal: int, local_trip: int, local_outing: int}
     */
    private function seedDashboardSurfaces(User $actor): array
    {
        $complianceDue = now()->addDays(10);
        $this->localVehicle->update([
            'wof_expires_at' => $complianceDue,
            'registration_expires_at' => $complianceDue,
            'cof_expires_at' => $complianceDue,
        ]);
        $this->foreignVehicle->update([
            'wof_expires_at' => $complianceDue,
            'registration_expires_at' => $complianceDue,
            'cof_expires_at' => $complianceDue,
        ]);

        foreach ([$this->localVehicle, $this->foreignVehicle] as $vehicle) {
            FleetVehicleStateSnapshot::query()->create([
                'asset_id' => $vehicle->id,
                'last_seen_at' => now(),
                'latitude' => -36.85,
                'longitude' => 174.76,
                'status' => 'online',
            ]);
        }

        FleetWorkOrder::query()->create([
            'asset_id' => $this->localVehicle->id,
            'reported_by_user_id' => $actor->id,
            'title' => 'Local tyre check',
            'category' => 'tyre',
            'priority' => 'medium',
            'status' => 'open',
        ]);
        FleetWorkOrder::query()->create([
            'asset_id' => $this->foreignVehicle->id,
            'reported_by_user_id' => $actor->id,
            'title' => 'Foreign electrical repair',
            'category' => 'electrical',
            'priority' => 'high',
            'status' => 'in_progress',
        ]);

        foreach ([$this->localVehicle, $this->foreignVehicle] as $vehicle) {
            FleetVehicleBooking::query()->create([
                'asset_id' => $vehicle->id,
                'user_id' => $actor->id,
                'purpose' => 'Upcoming appointment',
                'starts_at' => now()->addHour(),
                'ends_at' => now()->addHours(2),
                'status' => 'pending',
            ]);
            FleetVehicleBooking::query()->create([
                'asset_id' => $vehicle->id,
                'user_id' => $actor->id,
                'purpose' => 'Overdue return',
                'starts_at' => now()->subHours(4),
                'ends_at' => now()->subHour(),
                'status' => 'checked_out',
            ]);
        }

        $localOuting = $this->createActiveOuting($this->localVehicle, $actor, 'Local outing');
        $this->createActiveOuting($this->foreignVehicle, $actor, 'Foreign outing');

        foreach ([$this->localVehicle, $this->foreignVehicle] as $vehicle) {
            FleetServiceSchedule::query()->create([
                'asset_id' => $vehicle->id,
                'name' => 'Scheduled service',
                'next_due_at' => now()->addDays(10),
                'is_active' => true,
            ]);
        }

        $localTrip = $this->createTrip($this->localVehicle, 5);
        $this->createTrip($this->foreignVehicle, 50);

        $this->createFuelLog($this->localVehicle, $actor, 10);
        $this->createFuelLog($this->foreignVehicle, $actor, 90);

        $localSignal = $this->createSignal($this->localVehicle, 'local-signal');
        $this->createSignal($this->foreignVehicle, 'foreign-signal');

        $localDevice = Device::factory()->tracking()->create([
            'device_uid' => 'LOCAL-DASHBOARD-DEVICE',
            'name' => 'Local dashboard tracker',
            'last_seen_at' => now(),
        ]);
        $foreignDevice = Device::factory()->tracking()->create([
            'device_uid' => 'FOREIGN-DASHBOARD-DEVICE',
            'name' => 'Foreign dashboard tracker',
            'last_seen_at' => now(),
        ]);
        $this->linkDeviceToVehicle($localDevice, $this->localVehicle);
        $this->linkDeviceToVehicle($foreignDevice, $this->foreignVehicle);

        return [
            'local_signal' => $localSignal->id,
            'local_trip' => $localTrip->id,
            'local_outing' => $localOuting->id,
        ];
    }

    /**
     * @return array{
     *     local_client: int,
     *     local_geofence: int,
     *     local_outing: int,
     *     local_spare: int,
     *     local_assigned: int
     * }
     */
    private function seedResidentTrackingSurfaces(): array
    {
        $localClient = Client::factory()->create([
            'site_id' => $this->localSite->id,
            'first_name' => 'Local',
            'last_name' => 'Resident',
            'status' => 'active',
        ]);
        $foreignClient = Client::factory()->create([
            'site_id' => $this->foreignSite->id,
            'first_name' => 'Foreign',
            'last_name' => 'Resident',
            'status' => 'active',
        ]);

        $localGeofence = $this->createGeofence('Local resident fence', $this->localVehicle, $this->localSite);
        $this->createGeofence('Foreign resident fence', $this->foreignVehicle, $this->foreignSite);

        $localOuting = $this->createActiveOuting($this->localVehicle, null, 'Local resident outing');
        $localOuting->clients()->attach($localClient->id);
        $foreignOuting = $this->createActiveOuting($this->foreignVehicle, null, 'Foreign resident outing');
        $foreignOuting->clients()->attach($foreignClient->id);

        $localAssigned = Device::factory()->tracking()->create([
            'device_uid' => 'LOCAL-ASSIGNED-TRACKER',
            'name' => 'Local assigned tracker',
            'serial_number' => 'SERIAL-LOCAL-ASSIGNED',
        ]);
        $foreignAssigned = Device::factory()->tracking()->create([
            'device_uid' => 'FOREIGN-ASSIGNED-TRACKER',
            'name' => 'Foreign assigned tracker',
            'serial_number' => 'SERIAL-FOREIGN-ASSIGNED',
        ]);
        $this->assignDeviceToClient($localAssigned, $localClient, $this->createTrackingConsent($localClient));
        $this->assignDeviceToClient($foreignAssigned, $foreignClient, $this->createTrackingConsent($foreignClient));

        $localSpare = Device::factory()->tracking()->inStock()->create([
            'device_uid' => 'LOCAL-SPARE-TRACKER',
            'name' => 'Local spare tracker',
            'serial_number' => 'SERIAL-LOCAL-SPARE',
        ]);
        $foreignSpare = Device::factory()->tracking()->inStock()->create([
            'device_uid' => 'FOREIGN-SPARE-TRACKER',
            'name' => 'Foreign spare tracker',
            'serial_number' => 'SERIAL-FOREIGN-SPARE',
        ]);
        $this->linkDeviceToVehicle($localSpare, $this->localVehicle);
        $this->linkDeviceToVehicle($foreignSpare, $this->foreignVehicle);

        return [
            'local_client' => $localClient->id,
            'local_geofence' => $localGeofence->id,
            'local_outing' => $localOuting->id,
            'local_spare' => $localSpare->id,
            'local_assigned' => $localAssigned->id,
        ];
    }

    private function createActiveOuting(Asset $vehicle, ?User $actor, string $title): FleetOuting
    {
        return FleetOuting::query()->create([
            'title' => $title,
            'destination' => 'Community centre',
            'purpose' => 'Community participation',
            'planned_departure' => now()->subHours(2),
            'planned_return' => now()->subHour(),
            'actual_departure' => now()->subHours(2),
            'asset_id' => $vehicle->id,
            'driver_user_id' => $actor?->id,
            'status' => 'active',
            'created_by_user_id' => $actor?->id,
        ]);
    }

    private function createTrip(Asset $vehicle, float $distance): FleetTrip
    {
        return FleetTrip::query()->create([
            'asset_id' => $vehicle->id,
            'started_at' => now()->startOfDay()->addHours(19),
            'ended_at' => now()->startOfDay()->addHours(20),
            'distance_km' => $distance,
            'duration_s' => 3600,
            'status' => 'closed',
            'consent_blocked' => false,
        ]);
    }

    private function createFuelLog(Asset $vehicle, User $actor, float $total): FleetFuelLog
    {
        return FleetFuelLog::query()->create([
            'asset_id' => $vehicle->id,
            'user_id' => $actor->id,
            'logged_at' => now(),
            'fuel_type' => 'petrol',
            'quantity_litres' => 10,
            'cost_per_litre' => $total / 10,
            'total_cost' => $total,
        ]);
    }

    private function createSignal(Asset $vehicle, string $key): FleetSignal
    {
        return FleetSignal::query()->create([
            'asset_id' => $vehicle->id,
            'signal_type' => 'ignition_on',
            'severity_hint' => 'low',
            'occurred_at' => now(),
            'idempotency_key' => $key,
            'payload' => ['source' => $key],
        ]);
    }

    private function createGeofence(string $name, Asset $asset, ?Site $site = null): AssetGeofence
    {
        return AssetGeofence::query()->create([
            'asset_id' => $asset->id,
            'site_id' => $site?->id,
            'name' => $name,
            'type' => 'circle',
            'scope' => 'vehicle',
            'shape' => [
                'lat' => -36.8509,
                'lng' => 174.7645,
                'radius_m' => 100,
            ],
            'breach_type' => 'soft',
            'is_active' => true,
        ]);
    }

    private function linkDeviceToVehicle(Device $device, Asset $vehicle): void
    {
        DeviceAssetLink::query()->create([
            'device_id' => $device->id,
            'asset_id' => $vehicle->id,
            'link_type' => 'installed_in',
            'linked_at' => now(),
        ]);
    }

    private function assignDeviceToClient(
        Device $device,
        Client $client,
        ?ClientConsent $consent = null,
    ): void {
        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => 'client',
            'assignable_id' => $client->id,
            'assignment_type' => 'permanent',
            'assigned_at' => now(),
            'consent_id' => $consent?->id,
        ]);
    }

    private function createTrackingConsent(Client $client, array $overrides = []): ClientConsent
    {
        $type = ConsentType::query()->firstOrCreate(
            ['name' => 'Personal Tracker (Wandering Risk)'],
            [
                'category' => 'operational',
                'description' => 'Resident personal location tracking',
                'purpose' => 'Resident tracker safety',
                'legal_basis' => 'consent',
                'allows_withdrawal' => true,
                'active' => true,
            ],
        );
        $version = ConsentTypeVersion::query()->firstOrCreate(
            ['consent_type_id' => $type->id, 'version' => 1],
            [
                'description' => 'Fleet tracking v1',
                'purpose' => 'Resident tracker safety',
                'legal_basis' => 'consent',
                'effective_from' => now()->subDay(),
            ],
        );

        return AuthoritativeConsentFixture::manualSelf($client, $type, User::factory()->create(), array_merge([
            'status' => 'given',
            'given_at' => now(),
        ], $overrides));
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function makeSiteUser(Site $site, array $permissions, string $role = 'support_worker'): User
    {
        $user = User::factory()->create([
            'approved_at' => now(),
            'role' => $role,
        ]);

        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'position_title' => 'Support Worker',
            'position_role' => $role,
            'employment_type' => 'full_time',
            'start_date' => now()->subMonth()->toDateString(),
            'is_active' => true,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
        ]);

        $permissionMap = collect($permissions)
            ->map(function (string $key): int {
                $module = str($key)->before('.')->value() ?: 'fleet';

                return Permission::query()->firstOrCreate(
                    ['key' => $key],
                    [
                        'description' => $key,
                        'group' => $module,
                        'module' => $module,
                    ],
                )->id;
            })
            ->mapWithKeys(fn (int $id): array => [$id => ['allowed' => true]])
            ->all();

        $user->permissionOverrides()->syncWithoutDetaching($permissionMap);

        return $user;
    }
}
