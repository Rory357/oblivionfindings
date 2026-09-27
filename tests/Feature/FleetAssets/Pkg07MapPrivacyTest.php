<?php

namespace Tests\Feature\FleetAssets;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Models\Asset;
use App\Models\AssetGeofence;
use App\Models\AssetTelemetrySnapshot;
use App\Models\ControlRoomAlert;
use App\Models\FleetSignal;
use App\Models\FleetTelemetryEvent;
use App\Models\FleetTrip;
use App\Models\FleetVehicleStateSnapshot;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\Fleet\FleetGeofenceService;
use App\Services\Fleet\VehicleLocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class Pkg07MapPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private function reader(Site $site): User
    {
        $user = User::factory()->create(['email' => 'pkg07-'.Str::uuid().'@example.test', 'approved_at' => now(), 'role' => 'support_worker']);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $site->id,
            'secondary_site_ids' => [], 'start_date' => today()->subYear(), 'end_date' => null, 'is_active' => true]);
        foreach (['fleet.viewAny', 'assets.viewAny', 'assets.telemetry.view'] as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'test', 'module' => 'Test']);
            $user->permissionOverrides()->attach($permission->id, ['allowed' => true]);
        }

        return $user;
    }

    public function test_directory_and_history_withhold_private_vehicle_evidence_even_without_a_trip_link(): void
    {
        $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $user = $this->reader($site);
        $vehicle = Asset::factory()->vehicle()->forSite($site)->create(['client_id' => null]);
        $boundary = AssetGeofence::create(['name' => 'Privacy test area', 'site_id' => $site->id, 'scope' => 'site',
            'type' => 'circle', 'is_active' => false, 'shape' => ['center' => ['lat' => -41.29, 'lng' => 174.77], 'radius_m' => 180]]);
        $event = FleetTelemetryEvent::create(['asset_id' => $vehicle->id, 'vendor' => 'test', 'vendor_message_id' => (string) Str::uuid(),
            'occurred_at' => now()->subMinute(), 'received_at' => now(), 'latitude' => -41.29, 'longitude' => 174.77,
            'accuracy_m' => 15, 'event_type' => 'position', 'idempotency_key' => (string) Str::uuid(), 'consent_blocked' => false]);
        FleetVehicleStateSnapshot::create(['asset_id' => $vehicle->id, 'last_event_id' => $event->id,
            'latitude' => $event->latitude, 'longitude' => $event->longitude, 'last_seen_at' => $event->occurred_at, 'status' => 'online', 'consent_blocked' => false]);
        $url = '/fleet-assets/geofences/resources?id='.$vehicle->id;
        $this->actingAs($user)->getJson($url)->assertOk()->assertJsonPath('data.0.position.lat', -41.29)->assertJsonPath('data.0.accuracy_m', 15);
        $locations = app(VehicleLocationService::class);
        $this->assertSame(-41.29, $locations->lastPermittedPosition($user, $vehicle)['lat']);
        $fleetMapUrl = '/fleet-assets/vehicles/fleet-map/data';
        $this->getJson($fleetMapUrl)->assertOk()->assertJsonPath('vehicles.0.id', $vehicle->id)->assertJsonPath('vehicles.0.position.lat', -41.29);
        $signal = FleetSignal::create(['asset_id' => $vehicle->id, 'geofence_id' => $boundary->id,
            'source_event_id' => $event->id,
            'signal_type' => 'geofence_enter', 'occurred_at' => $event->occurred_at, 'idempotency_key' => (string) Str::uuid(), 'payload' => []]);
        $eventsUrl = '/fleet-assets/geofences/events?boundary_id='.$boundary->id;
        $this->getJson($eventsUrl)->assertOk()->assertJsonPath('total', 1);
        ControlRoomAlert::factory()->create(['fleet_signal_id' => $signal->id, 'asset_id' => $vehicle->id, 'site_id' => $site->id, 'source' => 'fleet', 'alert_type' => 'geofence', 'status' => 'open']);
        $summaryUrl = '/fleet-assets/geofences/summary?site_id='.$site->id;
        $this->getJson($summaryUrl)->assertOk()->assertJsonPath('follow_up', 0);
        $this->getJson($eventsUrl.'&follow_up=1')->assertOk()->assertJsonPath('total', 0);
        $permission = Permission::firstOrCreate(['key' => 'controlRoom.alerts.view'], ['description' => 'test', 'group' => 'test', 'module' => 'Test']);
        $user->permissionOverrides()->attach($permission->id, ['allowed' => true]);
        $this->actingAs($user->fresh())->getJson($summaryUrl)->assertOk()->assertJsonPath('follow_up', 1);
        $this->getJson($eventsUrl.'&follow_up=1')->assertOk()->assertJsonPath('total', 1);
        $trip = FleetTrip::create(['asset_id' => $vehicle->id, 'started_at' => now()->subMinutes(10), 'ended_at' => null,
            'status' => 'open', 'is_personal' => true, 'consent_blocked' => false]);
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.position', null);
        $this->getJson($eventsUrl)->assertOk()->assertJsonPath('total', 0);
        $this->getJson($summaryUrl)->assertOk()->assertJsonPath('follow_up', 0);
        $this->getJson($eventsUrl.'&follow_up=1')->assertOk()->assertJsonPath('total', 0);
        $trip->update(['is_personal' => false, 'consent_blocked' => true]);
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.position', null);
        $this->getJson($eventsUrl)->assertOk()->assertJsonPath('total', 0);
        $trip->update(['consent_blocked' => false]);
        $event->update(['consent_blocked' => true]);
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.position', null);
        $this->assertNull($locations->lastPermittedPosition($user, $vehicle));
        $this->assertNull($locations->fleetMapState($user, $vehicle)['position']['lat']);
        $this->getJson($fleetMapUrl)->assertOk()->assertJsonPath('vehicles.0.position.lat', null)->assertJsonPath('vehicles.0.position.withheld', 'consent');
        $this->getJson($eventsUrl)->assertOk()->assertJsonPath('total', 0)->assertJsonCount(0, 'data');
        $this->getJson($summaryUrl)->assertOk()->assertJsonPath('follow_up', 0);
        $this->getJson($eventsUrl.'&follow_up=1')->assertOk()->assertJsonPath('total', 0);
        $this->assertDatabaseHas('fleet_signals', ['id' => $signal->id]);
    }

    public function test_event_visibility_uses_its_exact_source_and_keeps_unrelated_permitted_history(): void
    {
        $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $user = $this->reader($site);
        $permission = Permission::firstOrCreate(['key' => 'controlRoom.alerts.view'], ['description' => 'test', 'group' => 'test', 'module' => 'Test']);
        $user->permissionOverrides()->attach($permission->id, ['allowed' => true]);
        $vehicle = Asset::factory()->vehicle()->forSite($site)->create(['client_id' => null]);
        $other = Asset::factory()->vehicle()->forSite($site)->create(['client_id' => null]);
        $boundary = AssetGeofence::create(['name' => 'Retained source privacy', 'site_id' => $site->id, 'scope' => 'site',
            'type' => 'circle', 'is_active' => false, 'shape' => ['center' => ['lat' => -41.29, 'lng' => 174.77], 'radius_m' => 180]]);
        $makeEvent = fn (Asset $asset, $at, bool $blocked) => FleetTelemetryEvent::create([
            'asset_id' => $asset->id, 'vendor' => 'test', 'vendor_message_id' => (string) Str::uuid(),
            'occurred_at' => $at, 'received_at' => now(), 'latitude' => -41.29, 'longitude' => 174.77,
            'event_type' => 'position', 'idempotency_key' => (string) Str::uuid(), 'consent_blocked' => $blocked]);
        $permitted = $makeEvent($vehicle, now()->subMinutes(15), false);
        $blocked = $makeEvent($vehicle, now()->subMinute(), true);
        $foreign = $makeEvent($other, $permitted->occurred_at, false);
        // Same-time evidence is deliberately ambiguous without an explicit source.
        $makeEvent($vehicle, $permitted->occurred_at, true);
        FleetVehicleStateSnapshot::create(['asset_id' => $vehicle->id, 'last_event_id' => $blocked->id,
            'latitude' => $blocked->latitude, 'longitude' => $blocked->longitude, 'last_seen_at' => $blocked->occurred_at,
            'status' => 'online', 'consent_blocked' => true]);
        $makeSignal = function (?FleetTelemetryEvent $source, $at) use ($vehicle, $boundary, $site): FleetSignal {
            $signal = FleetSignal::create(['asset_id' => $vehicle->id, 'geofence_id' => $boundary->id,
                'source_event_id' => $source?->id, 'signal_type' => 'geofence.enter', 'occurred_at' => $at,
                'idempotency_key' => (string) Str::uuid(), 'payload' => []]);
            ControlRoomAlert::factory()->create(['fleet_signal_id' => $signal->id, 'asset_id' => $vehicle->id,
                'site_id' => $site->id, 'source' => 'fleet', 'alert_type' => 'geofence', 'status' => 'open']);

            return $signal;
        };
        $visible = $makeSignal($permitted, $permitted->occurred_at);
        $makeSignal($blocked, $blocked->occurred_at);
        $makeSignal(null, $permitted->occurred_at);
        $makeSignal(null, now()->subHours(2));
        $makeSignal($foreign, $foreign->occurred_at);
        $makeSignal($permitted, $blocked->occurred_at);
        $url = '/fleet-assets/geofences/events?boundary_id='.$boundary->id;
        $summary = '/fleet-assets/geofences/summary?site_id='.$site->id;
        $this->actingAs($user->fresh())->getJson('/fleet-assets/geofences/resources?id='.$vehicle->id)
            ->assertOk()->assertJsonPath('data.0.position', null);
        $this->getJson($url)->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $visible->id)
            ->assertJsonPath('data.0.follow_up_href', '/control-room/alerts/'.ControlRoomAlert::where('fleet_signal_id', $visible->id)->value('id'));
        $this->getJson($url.'&follow_up=1')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $visible->id);
        $this->getJson($summary)->assertOk()->assertJsonPath('follow_up', 1);
        $permitted->update(['consent_blocked' => true]);
        $this->getJson($url)->assertOk()->assertJsonPath('total', 0)->assertJsonCount(0, 'data');
        $this->getJson($url.'&follow_up=1')->assertOk()->assertJsonPath('total', 0);
        $this->getJson($summary)->assertOk()->assertJsonPath('follow_up', 0);
        $permitted->update(['consent_blocked' => false]);
        $outsider = $this->reader(Site::factory()->create(['is_active' => true, 'archived' => false]));
        $this->actingAs($outsider)->getJson($url)->assertOk()->assertJsonPath('total', 0);
        $permitted->delete();
        $this->actingAs($user->fresh())->getJson($url)->assertOk()->assertJsonPath('total', 0);
        $this->assertNull($visible->fresh()->source_event_id);
        $this->assertSame(6, FleetSignal::where('geofence_id', $boundary->id)->count());
    }

    public function test_geofence_evaluator_retains_the_source_for_entry_dwell_and_breach(): void
    {
        Queue::fake();
        config(['fleet.signals.dwell_threshold_minutes' => 10]);
        $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $vehicle = Asset::factory()->vehicle()->forSite($site)->create(['client_id' => null]);
        $boundary = AssetGeofence::create(['name' => 'Source lineage', 'site_id' => $site->id, 'asset_id' => $vehicle->id,
            'scope' => 'vehicle', 'type' => 'circle', 'is_active' => true,
            'shape' => ['center' => ['lat' => -41.29, 'lng' => 174.77], 'radius_m' => 180]]);
        $service = app(FleetGeofenceService::class);
        foreach ([['geofence.enter', 25, -41.29], ['geofence.dwell', 10, -41.29], ['geofence.breach', 5, -41.30]] as [$kind, $minutes, $lat]) {
            // Ingest receives fractional timestamps; persisted timestamps use seconds.
            $observedAt = now()->subMinutes($minutes)->microsecond(123000);
            $event = FleetTelemetryEvent::create(['asset_id' => $vehicle->id, 'vendor' => 'test', 'vendor_message_id' => (string) Str::uuid(),
                'occurred_at' => $observedAt, 'received_at' => now(), 'latitude' => $lat, 'longitude' => 174.77,
                'event_type' => 'position', 'idempotency_key' => (string) Str::uuid(), 'consent_blocked' => false]);
            $service->evaluate($vehicle, $lat, 174.77, $observedAt, $event);
            $this->assertDatabaseHas('fleet_signals', ['asset_id' => $vehicle->id, 'geofence_id' => $boundary->id,
                'signal_type' => $kind, 'source_event_id' => $event->id]);
        }
        $this->actingAs($this->reader($site))->getJson('/fleet-assets/geofences/events?boundary_id='.$boundary->id)
            ->assertOk()->assertJsonPath('total', 3);
    }

    public function test_asset_positions_require_observations_from_the_current_unambiguous_pairing(): void
    {
        $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $user = $this->reader($site);
        $asset = Asset::factory()->forSite($site)->create(['category' => 'equipment', 'asset_category_id' => null, 'client_id' => null]);
        $device = Device::factory()->tracking()->create(['subcategory' => 'asset_tracker', 'latitude' => -41.29, 'longitude' => 174.77]);
        $link = DeviceAssetLink::create(['device_id' => $device->id, 'asset_id' => $asset->id, 'link_type' => 'primary', 'linked_at' => now()->subMinutes(5)]);
        $url = '/fleet-assets/geofences/resources?id='.$asset->id;
        $this->actingAs($user)->getJson($url)->assertOk()->assertJsonPath('data.0.position', null);
        $sample = AssetTelemetrySnapshot::create(['device_id' => $device->id, 'asset_id' => $asset->id,
            'vendor_payload_hash' => hash('sha256', (string) Str::uuid()),
            'occurred_at' => now()->subMinutes(10), 'received_at' => now(), 'latitude' => -41.29, 'longitude' => 174.77, 'accuracy_m' => 18, 'consent_blocked' => false]);
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.position', null);
        $sample->update(['occurred_at' => now()->subMinute()]);
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.position.lat', -41.29)->assertJsonPath('data.0.fresh', true);
        $sample->update(['consent_blocked' => true]);
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.position', null);
        $sample->update(['consent_blocked' => false]);
        $other = Asset::factory()->forSite($site)->create(['client_id' => null]);
        DeviceAssetLink::create(['device_id' => $device->id, 'asset_id' => $other->id, 'link_type' => 'installed_in', 'linked_at' => now()]);
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.position', null);
        $link->update(['unlinked_at' => now()]);
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.position', null);
    }
}
