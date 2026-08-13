<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\AssetGeofence;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ClientPersonalAsset;
use App\Models\ConsentType;
use App\Models\ControlRoom\AlertSla;
use App\Models\ControlRoom\SlaDefinition;
use App\Models\ControlRoomAlert;
use App\Models\LocationHardware;
use App\Models\Permission;
use App\Models\Queclink\QueclinkDevice;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteHouseRoom;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\Timeline\TimelineEmitter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

function grantClientLocationAssetPermissions(User $user, array $permissionKeys): void
{
    $role = Role::query()->create([
        'name' => 'client_location_asset_'.$user->id,
        'label' => 'Client Location Asset Test',
        'level' => 50,
        'type' => 'custom',
    ]);

    foreach ($permissionKeys as $key) {
        Permission::query()->firstOrCreate(
            ['key' => $key],
            ['description' => $key, 'group' => 'test', 'module' => 'Test'],
        );
    }

    $role->permissions()->sync(
        Permission::query()->whereIn('key', $permissionKeys)->pluck('id')->all(),
    );
    $user->roles()->attach($role->id);
}

function makeClientLocationAssetSite(string $name): Site
{
    return Site::factory()->create([
        'name' => $name,
        'is_active' => true,
    ]);
}

function makeClientLocationAssetRoom(Site $site, string $name): SiteHouseRoom
{
    return SiteHouseRoom::query()->create([
        'site_id' => $site->id,
        'name' => $name,
        'is_active' => true,
        'is_assignable' => true,
        'sort_order' => 1,
    ]);
}

function makeClientLocationAssetHardware(
    ?Site $site,
    string $name,
): LocationHardware {
    return LocationHardware::query()->create([
        'site_id' => $site?->id,
        'provider' => 'queclink',
        'category' => LocationHardware::CATEGORY_TRACKER,
        'name' => $name,
        'status' => LocationHardware::STATUS_ONLINE,
        'last_seen_at' => now(),
        'meta' => ['battery' => 82, 'lat' => -36.8485, 'lng' => 174.7633],
    ]);
}

function makeClientLocationAssetTracker(
    string $name,
    ?LocationHardware $hardware = null,
): Device {
    return Device::factory()->tracking()->create([
        'name' => $name,
        'provider' => 'queclink',
        'legacy_location_hardware_id' => $hardware?->id,
    ]);
}

function makeClientLocationAssetStaff(
    Site $site,
    array $permissionKeys,
    array $attributes = [],
): User {
    $user = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
        ...$attributes,
    ]);
    grantClientLocationAssetPermissions($user, $permissionKeys);
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'position_role' => 'support_worker',
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    return $user;
}

function grantClientLocationAssetTrackingConsent(Client $client, User $actor): ClientConsent
{
    $consentType = ConsentType::query()->firstOrCreate(
        ['name' => 'Personal Tracker (Wandering Risk)'],
        [
            'category' => 'safety',
            'description' => 'Consent to location monitoring of a personal tracker for safety.',
            'purpose' => 'Client personal safety location tracking',
            'legal_basis' => 'consent',
            'is_mandatory' => false,
            'requires_capacity_assessment' => false,
            'allows_withdrawal' => true,
            'validity_period_days' => 365,
            'renewal_required' => true,
            'renewal_reminder_days' => 30,
            'version' => 1,
            'active' => true,
        ],
    );

    return ClientConsent::query()->create([
        'client_id' => $client->id,
        'consent_type_id' => $consentType->id,
        'status' => 'given',
        'given_at' => now()->subMinute(),
        'expires_at' => now()->addMonth(),
        'given_by_user_id' => $actor->id,
        'given_by_relationship' => 'staff',
        'given_method' => 'written',
        'created_by' => $actor->id,
        'updated_by' => $actor->id,
    ]);
}

it('denies finance and client-only viewers all staff location actions and tracker payloads', function () {
    $site = makeClientLocationAssetSite('Finance Hidden Home');
    $user = makeClientLocationAssetStaff($site, [
        'clients.viewAny',
        'client_funds.manage',
    ]);

    $client = Client::factory()->create([
        'site_id' => $site->id,
        'status' => 'active',
    ]);
    $hardware = makeClientLocationAssetHardware($site, 'Hidden Tracker Shadow');
    $device = makeClientLocationAssetTracker('Hidden Canonical Tracker', $hardware);
    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_CLIENT,
        'assignable_id' => $client->id,
        'assigned_at' => now(),
        'assigned_by_user_id' => $user->id,
    ]);
    ClientPersonalAsset::query()->create([
        'client_id' => $client->id,
        'name' => 'Tracked wheelchair',
        'tracker_device_id' => $device->id,
        'status' => 'active',
        'ownership' => 'client',
    ]);

    $this->actingAs($user)
        ->get("/operations/clients/{$client->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->missing('location')
            ->missing('available_trackers')
            ->missing('asset_locations')
            ->missing('personal_assets'));

    $this->get("/operations/clients/{$client->id}/location/history")
        ->assertForbidden();
    $this->post("/operations/clients/{$client->id}/location/locate-now")
        ->assertForbidden();
    $this->post("/operations/clients/{$client->id}/location/acknowledge-panic")
        ->assertForbidden();
});

it('separates telemetry visibility from tracker command management', function () {
    $site = makeClientLocationAssetSite('Tracking Home');
    $client = Client::factory()->create([
        'site_id' => $site->id,
        'status' => 'active',
    ]);
    $consentActor = makeClientLocationAssetStaff($site, []);
    $consent = grantClientLocationAssetTrackingConsent($client, $consentActor);
    $hardware = makeClientLocationAssetHardware($site, 'Resident Tracker Shadow');
    $device = makeClientLocationAssetTracker('Resident Tracker', $hardware);
    $device->forceFill([
        'imei' => '861106050009901',
        'device_uid' => '861106050009901',
        'meta' => ['panic_active' => true],
    ])->save();
    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_CLIENT,
        'assignable_id' => $client->id,
        'assigned_at' => now(),
        'assigned_by_user_id' => $consentActor->id,
        'consent_id' => $consent->id,
    ]);
    QueclinkDevice::query()->create([
        'imei' => $device->imei,
        'device_id' => $device->id,
        'status' => QueclinkDevice::STATUS_PAIRED,
        'model_hint' => 'GL30MEU',
    ]);

    $telemetryOnly = makeClientLocationAssetStaff($site, [
        'clients.viewAny',
        'assets.telemetry.view',
    ]);

    $this->actingAs($telemetryOnly)
        ->get("/operations/clients/{$client->id}/location/history")
        ->assertForbidden();

    $viewer = makeClientLocationAssetStaff($site, [
        'clients.viewAny',
        'assets.viewAny',
        'assets.telemetry.view',
    ]);

    $this->actingAs($viewer)
        ->get("/operations/clients/{$client->id}/location/history")
        ->assertOk();
    $this->get("/operations/clients/{$client->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('location.canManage', false)
            ->missing('location.tracker.locate_now_url')
            ->missing('location.tracker.acknowledge_panic_url'));

    $untrackedClient = Client::factory()->create([
        'site_id' => $site->id,
        'status' => 'active',
    ]);
    $this->get("/operations/clients/{$untrackedClient->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('location.canManage', false)
            ->where('location.tracker', null));

    $this->from("/operations/clients/{$client->id}?tab=location")
        ->post("/operations/clients/{$client->id}/location/locate-now")
        ->assertForbidden();
    $this->post("/operations/clients/{$client->id}/location/acknowledge-panic")
        ->assertForbidden();

    $assignedReader = makeClientLocationAssetStaff($site, [
        'clients.viewAssigned',
        'assets.viewAssigned',
        'assets.telemetry.view',
    ]);
    $client->supportWorkers()->attach($assignedReader->id);

    $this->actingAs($assignedReader)
        ->get("/operations/clients/{$client->id}/location/history")
        ->assertOk();

    $manager = makeClientLocationAssetStaff($site, [
        'clients.viewAny',
        'assets.viewAny',
        'assets.telemetry.view',
        'assets.trackers.manage',
        'securityDevices.devices.viewUnassigned',
    ]);

    $this->actingAs($manager)
        ->get("/operations/clients/{$client->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('location.canManage', true)
            ->has('location.tracker.locate_now_url')
            ->has('location.tracker.acknowledge_panic_url'));
    $this->from("/operations/clients/{$client->id}?tab=location")
        ->post("/operations/clients/{$client->id}/location/locate-now")
        ->assertRedirect("/security-devices/devices/{$device->id}?section=management&action=tracking.location_refresh")
        ->assertSessionHas('success');
    $this->from("/operations/clients/{$client->id}?tab=location")
        ->post("/operations/clients/{$client->id}/location/acknowledge-panic")
        ->assertRedirect("/operations/clients/{$client->id}?tab=location")
        ->assertSessionHas('success');

    expect(data_get($device->fresh()->meta, 'panic_active'))->toBeFalse();
});

it('does not expose telemetry to fleet or asset viewers without the telemetry capability', function () {
    $site = makeClientLocationAssetSite('Telemetry Restricted Home');
    $client = Client::factory()->create([
        'site_id' => $site->id,
        'status' => 'active',
    ]);
    $hardware = makeClientLocationAssetHardware($site, 'Telemetry Restricted Shadow');
    $device = makeClientLocationAssetTracker('Telemetry Restricted Tracker', $hardware);
    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_CLIENT,
        'assignable_id' => $client->id,
        'assigned_at' => now(),
    ]);

    ClientPersonalAsset::query()->create([
        'client_id' => $client->id,
        'name' => 'Telemetry restricted asset',
        'tracker_device_id' => $device->id,
        'status' => 'active',
        'ownership' => 'client',
    ]);

    $viewer = makeClientLocationAssetStaff($site, [
        'clients.viewAny',
        'fleet.viewAny',
        'assets.viewAny',
    ]);

    $this->actingAs($viewer)
        ->get("/operations/clients/{$client->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->missing('location')
            ->where('personal_assets.0.tracker_device_id', null)
            ->where('personal_assets.0.tracker', null));

    $this->get("/operations/clients/{$client->id}/location/history")
        ->assertForbidden();
});

it('uses the canonical client site and device assignment for tracking and picker queries', function () {
    $clientSite = makeClientLocationAssetSite('Canonical Client Home');
    $otherSite = makeClientLocationAssetSite('Other Client Home');
    $user = makeClientLocationAssetStaff($clientSite, [
        'clients.viewAny',
        'clients.update',
        'assets.viewAny',
        'assets.telemetry.view',
        'assets.trackers.manage',
        'securityDevices.devices.viewUnassigned',
    ]);

    $client = Client::factory()->create([
        'site_id' => $clientSite->id,
        'status' => 'active',
    ]);
    $consent = grantClientLocationAssetTrackingConsent($client, $user);

    $clientFence = AssetGeofence::query()->create([
        'site_id' => $clientSite->id,
        'name' => 'Canonical client home fence',
        'type' => 'circle',
        'scope' => 'house',
        'shape' => ['lat' => -36.8, 'lng' => 174.7, 'radius_m' => 100],
        'is_active' => true,
    ]);
    AssetGeofence::query()->create([
        'site_id' => $otherSite->id,
        'name' => 'Other Site must not leak',
        'type' => 'circle',
        'scope' => 'house',
        'shape' => ['lat' => -36.9, 'lng' => 174.8, 'radius_m' => 100],
        'is_active' => true,
    ]);

    $assignedTracker = makeClientLocationAssetTracker('Canonically Assigned Tracker');
    DeviceAssignment::query()->create([
        'device_id' => $assignedTracker->id,
        'assignable_type' => DeviceAssignment::TARGET_CLIENT,
        'assignable_id' => $client->id,
        'assigned_at' => now(),
        'assigned_by_user_id' => $user->id,
        'consent_id' => $consent->id,
    ]);

    $clientHardware = makeClientLocationAssetHardware($clientSite, 'Canonical Picker Shadow');
    $clientAvailable = makeClientLocationAssetTracker('Canonical Available Tracker', $clientHardware);
    $otherHardware = makeClientLocationAssetHardware($otherSite, 'Other Site Picker Shadow');
    $otherAvailable = makeClientLocationAssetTracker('Other Site Available Tracker', $otherHardware);

    $this->actingAs($user)
        ->get("/operations/clients/{$client->id}")
        ->assertOk()
        ->assertInertia(function (Assert $page) use ($assignedTracker, $clientAvailable, $clientFence, $clientSite, $otherAvailable, $otherSite): void {
            $props = $page->toArray()['props'];

            expect(data_get($props, 'location.tracker.name'))
                ->toBe($assignedTracker->name)
                ->and(collect(data_get($props, 'location.geofences'))->pluck('id')->all())
                ->toBe([(string) $clientFence->id])
                ->and(collect($props['available_trackers'])->pluck('id')->all())
                ->toBe([$clientAvailable->id])
                ->not->toContain($otherAvailable->id)
                ->and(collect($props['asset_locations'])->pluck('id')->all())
                ->toBe([$clientSite->id])
                ->not->toContain($otherSite->id);
        });
});

it('does not emit tracker choices to ordinary client editors and rejects direct tracker injection', function () {
    $site = makeClientLocationAssetSite('Editor Home');
    $user = makeClientLocationAssetStaff($site, [
        'clients.viewAny',
        'clients.update',
    ]);
    $client = Client::factory()->create([
        'site_id' => $site->id,
        'status' => 'active',
    ]);
    $hardware = makeClientLocationAssetHardware($site, 'Editor Hidden Shadow');
    $device = makeClientLocationAssetTracker('Editor Hidden Tracker', $hardware);

    $this->actingAs($user)
        ->get("/operations/clients/{$client->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('asset_locations', 1)
            ->missing('available_trackers'));

    $this->from("/operations/clients/{$client->id}?tab=personal_assets")
        ->post("/operations/clients/{$client->id}/personal-assets", [
            'name' => 'Injected tracker asset',
            'site_id' => $site->id,
            'tracker_device_id' => $device->id,
        ])
        ->assertRedirect("/operations/clients/{$client->id}?tab=personal_assets")
        ->assertSessionHasErrors('tracker_device_id');

    expect(ClientPersonalAsset::query()->count())->toBe(0);
});

it('rejects the retired legacy tracker hardware write field', function () {
    $site = makeClientLocationAssetSite('Legacy Tracker Write Home');
    $user = makeClientLocationAssetStaff($site, [
        'clients.viewAny',
        'clients.update',
        'assets.trackers.manage',
    ]);
    $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
    $hardware = makeClientLocationAssetHardware($site, 'Historical tracker hardware');

    $this->actingAs($user)
        ->post("/operations/clients/{$client->id}/personal-assets", [
            'name' => 'Rejected compatibility write',
            'tracker_hardware_id' => $hardware->id,
        ])
        ->assertSessionHasErrors('tracker_hardware_id');

    expect(ClientPersonalAsset::query()->count())->toBe(0);
});

it('enforces the canonical client site on asset writes and persists eligible linked trackers', function () {
    $localSite = makeClientLocationAssetSite('Local Inventory Home');
    $otherSite = makeClientLocationAssetSite('Other Inventory Home');
    $thirdSite = makeClientLocationAssetSite('Third Inventory Home');
    $user = makeClientLocationAssetStaff($localSite, [
        'clients.viewAny',
        'clients.update',
        'assets.trackers.manage',
        'securityDevices.devices.viewUnassigned',
    ]);

    $localRoom = makeClientLocationAssetRoom($localSite, 'Local Room');
    $mismatchedRoom = makeClientLocationAssetRoom($otherSite, 'Wrong Site Room');
    $thirdSiteRoom = makeClientLocationAssetRoom($thirdSite, 'Third Site Room');
    $client = Client::factory()->create([
        'site_id' => $localSite->id,
        'status' => 'active',
    ]);
    $consent = grantClientLocationAssetTrackingConsent($client, $user);

    $localHardware = makeClientLocationAssetHardware($localSite, 'Eligible Shadow');
    $eligibleDevice = makeClientLocationAssetTracker('Eligible Tracker', $localHardware);
    $otherSiteHardware = makeClientLocationAssetHardware($otherSite, 'Other Site Shadow');
    $otherSiteDevice = makeClientLocationAssetTracker('Other Site Tracker', $otherSiteHardware);
    $thirdSiteHardware = makeClientLocationAssetHardware($thirdSite, 'Third Site Shadow');
    $thirdSiteDevice = makeClientLocationAssetTracker('Third Site Tracker', $thirdSiteHardware);
    $unbridgedDevice = makeClientLocationAssetTracker('Unbridged Tracker');

    $basePayload = ['name' => 'Inventory item'];
    $returnUrl = "/operations/clients/{$client->id}?tab=personal_assets";

    $this->actingAs($user)
        ->from($returnUrl)
        ->post("/operations/clients/{$client->id}/personal-assets", $basePayload + [
            'site_id' => $thirdSite->id,
            'room_id' => $thirdSiteRoom->id,
        ])
        ->assertSessionHasErrors(['site_id', 'room_id']);

    $this->from($returnUrl)
        ->post("/operations/clients/{$client->id}/personal-assets", $basePayload + [
            'site_id' => $otherSite->id,
        ])
        ->assertSessionHasErrors('site_id');

    $this->from($returnUrl)
        ->post("/operations/clients/{$client->id}/personal-assets", $basePayload + [
            'site_id' => $localSite->id,
            'room_id' => $mismatchedRoom->id,
        ])
        ->assertSessionHasErrors('room_id');

    $this->from($returnUrl)
        ->post("/operations/clients/{$client->id}/personal-assets", $basePayload + [
            'site_id' => $localSite->id,
            'room_id' => $localRoom->id,
            'tracker_device_id' => $otherSiteDevice->id,
        ])
        ->assertSessionHasErrors('tracker_device_id');

    $this->from($returnUrl)
        ->post("/operations/clients/{$client->id}/personal-assets", $basePayload + [
            'site_id' => $localSite->id,
            'room_id' => $localRoom->id,
            'tracker_device_id' => $thirdSiteDevice->id,
        ])
        ->assertSessionHasErrors('tracker_device_id');

    $this->from($returnUrl)
        ->post("/operations/clients/{$client->id}/personal-assets", $basePayload + [
            'site_id' => $localSite->id,
            'room_id' => $localRoom->id,
            'tracker_device_id' => $unbridgedDevice->id,
        ])
        ->assertSessionHasErrors('tracker_device_id');

    $this->from($returnUrl)
        ->post("/operations/clients/{$client->id}/personal-assets", $basePayload + [
            'name' => 'Safely tracked item',
            'site_id' => $localSite->id,
            'room_id' => $localRoom->id,
            'tracker_device_id' => $eligibleDevice->id,
        ])
        ->assertRedirect($returnUrl)
        ->assertSessionHasNoErrors();

    $asset = ClientPersonalAsset::query()->sole();

    expect($asset->site_id)->toBe($localSite->id)
        ->and($asset->room_id)->toBe($localRoom->id)
        ->and($asset->tracker_device_id)->toBe($eligibleDevice->id)
        ->and($asset->tracker_hardware_id)->toBeNull();
    $assignment = DeviceAssignment::query()
        ->where('device_id', $eligibleDevice->id)
        ->active()
        ->sole();
    expect($assignment->assignable_type)->toBe(DeviceAssignment::TARGET_CLIENT)
        ->and($assignment->assignable_id)->toBe($client->id)
        ->and($assignment->consent_id)->toBe($consent->id);
});

it('records multiple personal asset status transitions with actor and source context', function () {
    $site = makeClientLocationAssetSite('Asset Status Home');
    $user = makeClientLocationAssetStaff($site, [
        'clients.viewAny',
        'clients.update',
    ]);
    $client = Client::factory()->create(['site_id' => $site->id]);
    $asset = ClientPersonalAsset::query()->create([
        'client_id' => $client->id,
        'name' => 'Mobility scooter',
        'status' => 'active',
        'ownership' => 'client',
        'recorded_by_user_id' => $user->id,
    ]);

    $this->actingAs($user)
        ->put("/operations/clients/{$client->id}/personal-assets/{$asset->id}", [
            'name' => $asset->name,
            'status' => 'lost',
            'notes' => 'Last seen near the garden.',
        ])
        ->assertRedirect();
    $this->actingAs($user)
        ->patch("/operations/clients/{$client->id}/personal-assets/{$asset->id}/status", [
            'status' => 'damaged',
            'disposal_reason' => 'Recovered with wheel damage.',
        ])
        ->assertRedirect();

    $events = TimelineEvent::query()
        ->where('client_id', $client->id)
        ->where('type', 'personal_asset_status_changed')
        ->orderBy('id')
        ->get();

    expect($asset->fresh()->status)->toBe('damaged')
        ->and($events)->toHaveCount(2)
        ->and($events->pluck('actor_user_id')->all())->toBe([$user->id, $user->id])
        ->and($events->pluck('source_type')->filter())->toBeEmpty()
        ->and($events->pluck('source_id')->filter())->toBeEmpty()
        ->and($events->pluck('meta.personal_asset_id')->all())->toBe([$asset->id, $asset->id])
        ->and($events->pluck('meta.from_status')->all())->toBe(['active', 'lost'])
        ->and($events->pluck('meta.to_status')->all())->toBe(['lost', 'damaged']);
});

it('replaces a personal asset tracker through canonical assignment history and consent', function () {
    $site = makeClientLocationAssetSite('Tracker Replacement Home');
    $user = makeClientLocationAssetStaff($site, [
        'clients.viewAny',
        'clients.update',
        'assets.trackers.manage',
        'securityDevices.devices.viewUnassigned',
    ]);
    $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
    $consent = grantClientLocationAssetTrackingConsent($client, $user);
    $oldDevice = makeClientLocationAssetTracker(
        'Original personal asset tracker',
        makeClientLocationAssetHardware($site, 'Original tracker shadow'),
    );
    $newDevice = makeClientLocationAssetTracker(
        'Replacement personal asset tracker',
        makeClientLocationAssetHardware($site, 'Replacement tracker shadow'),
    );
    $oldAssignment = DeviceAssignment::query()->create([
        'device_id' => $oldDevice->id,
        'assignable_type' => DeviceAssignment::TARGET_CLIENT,
        'assignable_id' => $client->id,
        'assigned_at' => now()->subDay(),
        'assigned_by_user_id' => $user->id,
        'consent_id' => $consent->id,
    ]);
    $asset = ClientPersonalAsset::query()->create([
        'client_id' => $client->id,
        'name' => 'Tracked wheelchair',
        'status' => 'active',
        'ownership' => 'client',
        'tracker_device_id' => $oldDevice->id,
        'recorded_by_user_id' => $user->id,
    ]);

    $this->actingAs($user)
        ->put("/operations/clients/{$client->id}/personal-assets/{$asset->id}", [
            'name' => $asset->name,
            'status' => 'active',
            'tracker_device_id' => $newDevice->id,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $newAssignment = DeviceAssignment::query()
        ->where('device_id', $newDevice->id)
        ->active()
        ->sole();
    expect($asset->fresh()->tracker_device_id)->toBe($newDevice->id)
        ->and($asset->fresh()->tracker_hardware_id)->toBeNull()
        ->and($oldAssignment->fresh()->released_at)->not->toBeNull()
        ->and($oldAssignment->fresh()->collection_stopped_at)->not->toBeNull()
        ->and($newAssignment->assignable_type)->toBe(DeviceAssignment::TARGET_CLIENT)
        ->and($newAssignment->assignable_id)->toBe($client->id)
        ->and($newAssignment->consent_id)->toBe($consent->id)
        ->and(DeviceAssignment::query()->where('device_id', $oldDevice->id)->count())->toBe(1);
});

it('releases tracker collection on personal asset return disposal and deletion while retaining history', function () {
    $site = makeClientLocationAssetSite('Tracker Lifecycle Home');
    $user = makeClientLocationAssetStaff($site, [
        'clients.viewAny',
        'clients.update',
    ]);
    $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
    $consent = grantClientLocationAssetTrackingConsent($client, $user);

    $records = collect(['returned', 'disposed', 'deleted'])->mapWithKeys(function (string $lifecycle) use (
        $site,
        $client,
        $consent,
        $user,
    ): array {
        $device = makeClientLocationAssetTracker(
            ucfirst($lifecycle).' tracker',
            makeClientLocationAssetHardware($site, ucfirst($lifecycle).' tracker shadow'),
        );
        $assignment = DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_CLIENT,
            'assignable_id' => $client->id,
            'assigned_at' => now()->subHour(),
            'assigned_by_user_id' => $user->id,
            'consent_id' => $consent->id,
        ]);
        $asset = ClientPersonalAsset::query()->create([
            'client_id' => $client->id,
            'name' => ucfirst($lifecycle).' tracked asset',
            'status' => 'active',
            'ownership' => 'client',
            'tracker_device_id' => $device->id,
            'recorded_by_user_id' => $user->id,
        ]);

        return [$lifecycle => compact('device', 'assignment', 'asset')];
    });

    $returned = $records->get('returned');
    $this->actingAs($user)
        ->patch("/operations/clients/{$client->id}/personal-assets/{$returned['asset']->id}/status", [
            'status' => 'returned',
        ])
        ->assertRedirect();

    $disposed = $records->get('disposed');
    $this->put("/operations/clients/{$client->id}/personal-assets/{$disposed['asset']->id}", [
        'name' => $disposed['asset']->name,
        'status' => 'disposed',
        'disposal_reason' => 'End of service life',
    ])->assertRedirect();

    $deleted = $records->get('deleted');
    $this->delete("/operations/clients/{$client->id}/personal-assets/{$deleted['asset']->id}")
        ->assertRedirect();

    foreach ($records as $lifecycle => $record) {
        $freshAsset = ClientPersonalAsset::withTrashed()->findOrFail($record['asset']->id);
        expect($record['assignment']->fresh()->released_at)->not->toBeNull()
            ->and($record['assignment']->fresh()->collection_stopped_at)->not->toBeNull()
            ->and($freshAsset->tracker_device_id)->toBe($record['device']->id);
    }
    expect($returned['asset']->fresh()->status)->toBe('returned')
        ->and($disposed['asset']->fresh()->status)->toBe('disposed')
        ->and(ClientPersonalAsset::withTrashed()->findOrFail($deleted['asset']->id)->trashed())->toBeTrue();
});

it('rolls back a personal asset status endpoint when timeline emission fails', function () {
    $site = makeClientLocationAssetSite('Asset Status Rollback Home');
    $user = makeClientLocationAssetStaff($site, [
        'clients.viewAny',
        'clients.update',
    ]);
    $client = Client::factory()->create(['site_id' => $site->id]);
    $consent = grantClientLocationAssetTrackingConsent($client, $user);
    $device = makeClientLocationAssetTracker(
        'Rollback tracker',
        makeClientLocationAssetHardware($site, 'Rollback tracker shadow'),
    );
    $assignment = DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_CLIENT,
        'assignable_id' => $client->id,
        'assigned_at' => now(),
        'assigned_by_user_id' => $user->id,
        'consent_id' => $consent->id,
    ]);
    $asset = ClientPersonalAsset::query()->create([
        'client_id' => $client->id,
        'name' => 'Communication tablet',
        'status' => 'active',
        'ownership' => 'client',
        'tracker_device_id' => $device->id,
        'recorded_by_user_id' => $user->id,
    ]);
    $emitter = Mockery::mock(TimelineEmitter::class);
    $emitter->shouldReceive('record')
        ->once()
        ->andThrow(new RuntimeException('Timeline unavailable'));
    $this->app->instance(TimelineEmitter::class, $emitter);
    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($user)
        ->patch("/operations/clients/{$client->id}/personal-assets/{$asset->id}/status", [
            'status' => 'returned',
        ]))->toThrow(RuntimeException::class, 'Timeline unavailable');

    expect($asset->fresh()->status)->toBe('active')
        ->and($assignment->fresh()->released_at)->toBeNull()
        ->and($assignment->fresh()->collection_stopped_at)->toBeNull();
});

it('rolls back a full personal asset update when timeline emission fails', function () {
    $site = makeClientLocationAssetSite('Asset Update Rollback Home');
    $user = makeClientLocationAssetStaff($site, [
        'clients.viewAny',
        'clients.update',
    ]);
    $client = Client::factory()->create(['site_id' => $site->id]);
    $asset = ClientPersonalAsset::query()->create([
        'client_id' => $client->id,
        'name' => 'Powered wheelchair',
        'notes' => 'Original note',
        'status' => 'active',
        'ownership' => 'client',
        'recorded_by_user_id' => $user->id,
    ]);
    $emitter = Mockery::mock(TimelineEmitter::class);
    $emitter->shouldReceive('record')
        ->once()
        ->andThrow(new RuntimeException('Timeline unavailable'));
    $this->app->instance(TimelineEmitter::class, $emitter);
    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($user)
        ->put("/operations/clients/{$client->id}/personal-assets/{$asset->id}", [
            'name' => $asset->name,
            'notes' => 'Must not commit partially',
            'status' => 'damaged',
        ]))->toThrow(RuntimeException::class, 'Timeline unavailable');

    $asset->refresh();
    expect($asset->status)->toBe('active')
        ->and($asset->notes)->toBe('Original note');
});

it('preserves the original personal asset photo and removes the replacement when timeline emission fails', function () {
    Storage::fake('public');

    $site = makeClientLocationAssetSite('Asset Photo Rollback Home');
    $user = makeClientLocationAssetStaff($site, [
        'clients.viewAny',
        'clients.update',
    ]);
    $client = Client::factory()->create(['site_id' => $site->id]);
    $originalPath = "clients/{$client->id}/assets/original.jpg";
    Storage::disk('public')->put($originalPath, 'original photo');
    $asset = ClientPersonalAsset::query()->create([
        'client_id' => $client->id,
        'name' => 'Photo-tracked wheelchair',
        'photo_path' => $originalPath,
        'status' => 'active',
        'ownership' => 'client',
        'recorded_by_user_id' => $user->id,
    ]);
    $emitter = Mockery::mock(TimelineEmitter::class);
    $emitter->shouldReceive('record')
        ->once()
        ->andThrow(new RuntimeException('Timeline unavailable'));
    $this->app->instance(TimelineEmitter::class, $emitter);
    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($user)
        ->put("/operations/clients/{$client->id}/personal-assets/{$asset->id}", [
            'name' => $asset->name,
            'status' => 'damaged',
            'photo' => UploadedFile::fake()->image('replacement.jpg', 100, 100),
        ]))->toThrow(RuntimeException::class, 'Timeline unavailable');

    expect($asset->fresh()->photo_path)->toBe($originalPath)
        ->and(Storage::disk('public')->exists($originalPath))->toBeTrue()
        ->and(Storage::disk('public')->get($originalPath))->toBe('original photo')
        ->and(Storage::disk('public')->allFiles("clients/{$client->id}/assets"))
        ->toBe([$originalPath]);
});

it('acknowledges only open client panic alerts through the canonical lifecycle', function () {
    $site = makeClientLocationAssetSite('Panic Lifecycle Home');
    $client = Client::factory()->create([
        'site_id' => $site->id,
        'status' => 'active',
    ]);
    $manager = makeClientLocationAssetStaff($site, [
        'clients.viewAny',
        'assets.viewAny',
        'assets.telemetry.view',
        'assets.trackers.manage',
    ]);
    $consent = grantClientLocationAssetTrackingConsent($client, $manager);
    $hardware = makeClientLocationAssetHardware($site, 'Panic Tracker Shadow');
    $device = makeClientLocationAssetTracker('Panic Tracker', $hardware);
    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_CLIENT,
        'assignable_id' => $client->id,
        'assigned_at' => now(),
        'assigned_by_user_id' => $manager->id,
        'consent_id' => $consent->id,
    ]);

    $openAlert = ControlRoomAlert::factory()->open()->create([
        'client_id' => $client->id,
        'site_id' => $site->id,
        'source' => 'tracker',
        'created_by_user_id' => $manager->id,
    ]);
    $triagingAlert = ControlRoomAlert::factory()->triaging()->create([
        'client_id' => $client->id,
        'site_id' => $site->id,
        'source' => 'resident_tracker',
        'created_by_user_id' => $manager->id,
    ]);
    $definition = SlaDefinition::query()->create([
        'name' => 'Client panic acknowledgement',
        'code' => 'client-panic-acknowledgement',
        'acknowledge_target_minutes' => 5,
        'response_target_minutes' => 10,
        'resolution_target_minutes' => 60,
        'is_active' => true,
    ]);
    $openSla = AlertSla::createFromDefinition($openAlert, $definition);
    $triagingSla = AlertSla::createFromDefinition($triagingAlert, $definition);

    $this->actingAs($manager)
        ->from("/operations/clients/{$client->id}?tab=location")
        ->post(route('operations.clients.location.acknowledge-panic', $client, false))
        ->assertRedirect("/operations/clients/{$client->id}?tab=location")
        ->assertSessionHasNoErrors();

    expect($openAlert->fresh()->status)->toBe(ControlRoomAlert::STATUS_ACK)
        ->and($openAlert->fresh()->acknowledged_by_user_id)->toBe($manager->id)
        ->and($openSla->fresh()->acknowledged_at)->not->toBeNull()
        ->and($triagingAlert->fresh()->status)->toBe(ControlRoomAlert::STATUS_TRIAGING)
        ->and($triagingSla->fresh()->acknowledged_at)->toBeNull();
});
