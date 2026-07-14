<?php

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

function makeClientLocationAssetSite(int $tenantId, string $name): Site
{
    return Site::factory()->create([
        'tenant_id' => $tenantId,
        'name' => $name,
        'is_active' => true,
    ]);
}

function makeClientLocationAssetRoom(Site $site, string $name): SiteHouseRoom
{
    return SiteHouseRoom::query()->create([
        'site_id' => $site->id,
        'tenant_id' => $site->tenant_id,
        'name' => $name,
        'is_active' => true,
        'is_assignable' => true,
        'sort_order' => 1,
    ]);
}

function makeClientLocationAssetHardware(
    int $tenantId,
    ?Site $site,
    string $name,
): LocationHardware {
    return LocationHardware::query()->create([
        'tenant_id' => $tenantId,
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
    int $tenantId,
    string $name,
    ?LocationHardware $legacyHardware = null,
): Device {
    return Device::factory()->tracking()->create([
        'tenant_id' => $tenantId,
        'name' => $name,
        'provider' => 'queclink',
        'legacy_location_hardware_id' => $legacyHardware?->id,
    ]);
}

function grantClientLocationAssetTrackingConsent(Client $client, User $actor): ClientConsent
{
    $consentType = ConsentType::query()->firstOrCreate(
        ['name' => 'Asset Location Tracking (Safety)'],
        ConsentType::factory()->make()->only([
            'category',
            'description',
            'purpose',
            'legal_basis',
            'is_mandatory',
            'requires_capacity_assessment',
            'allows_withdrawal',
            'withdrawal_notice_days',
            'validity_period_days',
            'renewal_required',
            'renewal_reminder_days',
            'version',
            'active',
        ]),
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
    $user = User::factory()->create(['organization_id' => 1]);
    grantClientLocationAssetPermissions($user, [
        'clients.viewAny',
        'client_funds.manage',
    ]);

    $site = makeClientLocationAssetSite(1, 'Finance Hidden Home');
    $client = Client::factory()->create([
        'organization_id' => 1,
        'site_id' => $site->id,
        'status' => 'active',
    ]);
    $hardware = makeClientLocationAssetHardware(1, $site, 'Hidden Tracker Shadow');
    $device = makeClientLocationAssetTracker(1, 'Hidden Canonical Tracker', $hardware);
    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_CLIENT,
        'assignable_id' => $client->id,
        'assigned_at' => now(),
    ]);
    ClientPersonalAsset::query()->create([
        'client_id' => $client->id,
        'name' => 'Tracked wheelchair',
        'tracker_hardware_id' => $hardware->id,
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
    $site = makeClientLocationAssetSite(1, 'Tracking Home');
    $client = Client::factory()->create([
        'organization_id' => 1,
        'site_id' => $site->id,
        'status' => 'active',
    ]);
    $consentActor = User::factory()->create(['organization_id' => 1]);
    grantClientLocationAssetTrackingConsent($client, $consentActor);
    $hardware = makeClientLocationAssetHardware(1, $site, 'Resident Tracker Shadow');
    $device = makeClientLocationAssetTracker(1, 'Resident Tracker', $hardware);
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
    ]);
    QueclinkDevice::query()->create([
        'imei' => $device->imei,
        'device_id' => $device->id,
        'tenant_id' => 1,
        'status' => QueclinkDevice::STATUS_PAIRED,
        'model_hint' => 'GL30MEU',
    ]);

    $telemetryOnly = User::factory()->create(['organization_id' => 1]);
    grantClientLocationAssetPermissions($telemetryOnly, [
        'clients.viewAny',
        'assets.telemetry.view',
    ]);

    $this->actingAs($telemetryOnly)
        ->get("/operations/clients/{$client->id}/location/history")
        ->assertForbidden();

    $viewer = User::factory()->create(['organization_id' => 1]);
    grantClientLocationAssetPermissions($viewer, [
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
        'organization_id' => 1,
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

    $assignedReader = User::factory()->create([
        'organization_id' => 1,
        'role' => 'support_worker',
    ]);
    grantClientLocationAssetPermissions($assignedReader, [
        'clients.viewAssigned',
        'assets.viewAssigned',
        'assets.telemetry.view',
    ]);
    $client->supportWorkers()->attach($assignedReader->id);

    $this->actingAs($assignedReader)
        ->get("/operations/clients/{$client->id}/location/history")
        ->assertOk();

    $manager = User::factory()->create(['organization_id' => 1]);
    grantClientLocationAssetPermissions($manager, [
        'clients.viewAny',
        'assets.viewAny',
        'assets.telemetry.view',
        'assets.trackers.manage',
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
        ->assertRedirect("/operations/clients/{$client->id}?tab=location")
        ->assertSessionHas('success');
    $this->from("/operations/clients/{$client->id}?tab=location")
        ->post("/operations/clients/{$client->id}/location/acknowledge-panic")
        ->assertRedirect("/operations/clients/{$client->id}?tab=location")
        ->assertSessionHas('success');

    expect(data_get($device->fresh()->meta, 'panic_active'))->toBeFalse();
});

it('does not expose telemetry to fleet or asset viewers without the telemetry capability', function () {
    $site = makeClientLocationAssetSite(1, 'Telemetry Restricted Home');
    $client = Client::factory()->create([
        'organization_id' => 1,
        'site_id' => $site->id,
        'status' => 'active',
    ]);
    $hardware = makeClientLocationAssetHardware(1, $site, 'Telemetry Restricted Shadow');
    $device = makeClientLocationAssetTracker(1, 'Telemetry Restricted Tracker', $hardware);
    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_CLIENT,
        'assignable_id' => $client->id,
        'assigned_at' => now(),
    ]);

    ClientPersonalAsset::query()->create([
        'client_id' => $client->id,
        'name' => 'Telemetry restricted asset',
        'tracker_hardware_id' => $hardware->id,
        'status' => 'active',
        'ownership' => 'client',
    ]);

    $viewer = User::factory()->create(['organization_id' => 1]);
    grantClientLocationAssetPermissions($viewer, [
        'clients.viewAny',
        'fleet.viewAny',
        'assets.viewAny',
    ]);

    $this->actingAs($viewer)
        ->get("/operations/clients/{$client->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->missing('location')
            ->where('personal_assets.0.tracker_hardware_id', null)
            ->where('personal_assets.0.tracker', null));

    $this->get("/operations/clients/{$client->id}/location/history")
        ->assertForbidden();
});

it('uses the client organization for location and picker queries without a global geofence fallback', function () {
    $user = User::factory()->create(['organization_id' => 2]);
    grantClientLocationAssetPermissions($user, [
        'clients.viewAny',
        'clients.update',
        'assets.viewAny',
        'assets.telemetry.view',
        'assets.trackers.manage',
    ]);

    $client = Client::factory()->create([
        'organization_id' => 2,
        'site_id' => null,
        'status' => 'active',
    ]);
    grantClientLocationAssetTrackingConsent($client, $user);

    $foreignSite = makeClientLocationAssetSite(1, 'Foreign Home');
    $localSite = makeClientLocationAssetSite(2, 'Local Home');
    AssetGeofence::query()->create([
        'site_id' => $foreignSite->id,
        'name' => 'Global fallback must not leak',
        'type' => 'circle',
        'scope' => 'house',
        'shape' => ['lat' => -36.8, 'lng' => 174.7, 'radius_m' => 100],
        'is_active' => true,
    ]);

    $foreignAssigned = makeClientLocationAssetTracker(1, 'Wrong Tenant Assigned Tracker');
    DeviceAssignment::query()->create([
        'device_id' => $foreignAssigned->id,
        'assignable_type' => DeviceAssignment::TARGET_CLIENT,
        'assignable_id' => $client->id,
        'assigned_at' => now(),
    ]);
    $localAssigned = makeClientLocationAssetTracker(2, 'Correct Tenant Assigned Tracker');
    DeviceAssignment::query()->create([
        'device_id' => $localAssigned->id,
        'assignable_type' => DeviceAssignment::TARGET_CLIENT,
        'assignable_id' => $client->id,
        'assigned_at' => now(),
    ]);

    $foreignHardware = makeClientLocationAssetHardware(1, $foreignSite, 'Foreign Picker Shadow');
    $foreignAvailable = makeClientLocationAssetTracker(1, 'Foreign Available Tracker', $foreignHardware);
    $localHardware = makeClientLocationAssetHardware(2, $localSite, 'Local Picker Shadow');
    $localAvailable = makeClientLocationAssetTracker(2, 'Local Available Tracker', $localHardware);

    $this->actingAs($user)
        ->get("/operations/clients/{$client->id}")
        ->assertOk()
        ->assertInertia(function (Assert $page) use ($foreignAvailable, $foreignSite, $localAvailable, $localSite): void {
            $props = $page->toArray()['props'];

            expect(data_get($props, 'location.tracker.name'))
                ->toBe('Correct Tenant Assigned Tracker')
                ->and(data_get($props, 'location.geofences'))->toBe([])
                ->and(collect($props['available_trackers'])->pluck('id')->all())
                ->toBe([$localAvailable->id])
                ->not->toContain($foreignAvailable->id)
                ->and(collect($props['asset_locations'])->pluck('id')->all())
                ->toBe([$localSite->id])
                ->not->toContain($foreignSite->id);
        });
});

it('does not emit tracker choices to ordinary client editors and rejects direct tracker injection', function () {
    $user = User::factory()->create(['organization_id' => 1]);
    grantClientLocationAssetPermissions($user, [
        'clients.viewAny',
        'clients.update',
    ]);
    $site = makeClientLocationAssetSite(1, 'Editor Home');
    $client = Client::factory()->create([
        'organization_id' => 1,
        'site_id' => $site->id,
        'status' => 'active',
    ]);
    $hardware = makeClientLocationAssetHardware(1, $site, 'Editor Hidden Shadow');
    $device = makeClientLocationAssetTracker(1, 'Editor Hidden Tracker', $hardware);

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
            'tracker_hardware_id' => $device->id,
        ])
        ->assertRedirect("/operations/clients/{$client->id}?tab=personal_assets")
        ->assertSessionHasErrors('tracker_hardware_id');

    expect(ClientPersonalAsset::query()->count())->toBe(0);
});

it('rejects foreign or ineligible asset picker ids and persists an eligible canonical tracker through its legacy bridge', function () {
    $user = User::factory()->create(['organization_id' => 1]);
    grantClientLocationAssetPermissions($user, [
        'clients.viewAny',
        'clients.update',
        'assets.trackers.manage',
    ]);

    $localSite = makeClientLocationAssetSite(1, 'Local Inventory Home');
    $otherLocalSite = makeClientLocationAssetSite(1, 'Other Local Home');
    $foreignSite = makeClientLocationAssetSite(2, 'Foreign Inventory Home');
    $localRoom = makeClientLocationAssetRoom($localSite, 'Local Room');
    $mismatchedRoom = makeClientLocationAssetRoom($otherLocalSite, 'Wrong Local Room');
    $foreignRoom = makeClientLocationAssetRoom($foreignSite, 'Foreign Room');
    $client = Client::factory()->create([
        'organization_id' => 1,
        'site_id' => $localSite->id,
        'status' => 'active',
    ]);

    $localHardware = makeClientLocationAssetHardware(1, $localSite, 'Eligible Shadow');
    $eligibleDevice = makeClientLocationAssetTracker(1, 'Eligible Tracker', $localHardware);
    $foreignHardware = makeClientLocationAssetHardware(2, $foreignSite, 'Foreign Shadow');
    $foreignDevice = makeClientLocationAssetTracker(2, 'Foreign Tracker', $foreignHardware);
    $unbridgedDevice = makeClientLocationAssetTracker(1, 'Unbridged Tracker');

    $basePayload = ['name' => 'Inventory item'];
    $returnUrl = "/operations/clients/{$client->id}?tab=personal_assets";

    $this->actingAs($user)
        ->from($returnUrl)
        ->post("/operations/clients/{$client->id}/personal-assets", $basePayload + [
            'site_id' => $foreignSite->id,
            'room_id' => $foreignRoom->id,
        ])
        ->assertSessionHasErrors(['site_id', 'room_id']);

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
            'tracker_hardware_id' => $foreignDevice->id,
        ])
        ->assertSessionHasErrors('tracker_hardware_id');

    $this->from($returnUrl)
        ->post("/operations/clients/{$client->id}/personal-assets", $basePayload + [
            'site_id' => $localSite->id,
            'room_id' => $localRoom->id,
            'tracker_hardware_id' => $unbridgedDevice->id,
        ])
        ->assertSessionHasErrors('tracker_hardware_id');

    $this->from($returnUrl)
        ->post("/operations/clients/{$client->id}/personal-assets", $basePayload + [
            'name' => 'Safely tracked item',
            'site_id' => $localSite->id,
            'room_id' => $localRoom->id,
            'tracker_hardware_id' => $eligibleDevice->id,
        ])
        ->assertRedirect($returnUrl)
        ->assertSessionHasNoErrors();

    $asset = ClientPersonalAsset::query()->sole();

    expect($asset->site_id)->toBe($localSite->id)
        ->and($asset->room_id)->toBe($localRoom->id)
        ->and($asset->tracker_hardware_id)->toBe($localHardware->id);
});

it('records multiple personal asset status transitions with actor and source context', function () {
    $user = User::factory()->create(['organization_id' => 1]);
    grantClientLocationAssetPermissions($user, [
        'clients.viewAny',
        'clients.update',
    ]);
    $client = Client::factory()->create(['organization_id' => 1]);
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

it('rolls back a personal asset status endpoint when timeline emission fails', function () {
    $user = User::factory()->create(['organization_id' => 1]);
    grantClientLocationAssetPermissions($user, [
        'clients.viewAny',
        'clients.update',
    ]);
    $client = Client::factory()->create(['organization_id' => 1]);
    $asset = ClientPersonalAsset::query()->create([
        'client_id' => $client->id,
        'name' => 'Communication tablet',
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
        ->patch("/operations/clients/{$client->id}/personal-assets/{$asset->id}/status", [
            'status' => 'lost',
        ]))->toThrow(RuntimeException::class, 'Timeline unavailable');

    expect($asset->fresh()->status)->toBe('active');
});

it('rolls back a full personal asset update when timeline emission fails', function () {
    $user = User::factory()->create(['organization_id' => 1]);
    grantClientLocationAssetPermissions($user, [
        'clients.viewAny',
        'clients.update',
    ]);
    $client = Client::factory()->create(['organization_id' => 1]);
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
            'notes' => 'Unauthorized partial commit',
            'status' => 'damaged',
        ]))->toThrow(RuntimeException::class, 'Timeline unavailable');

    $asset->refresh();
    expect($asset->status)->toBe('active')
        ->and($asset->notes)->toBe('Original note');
});

it('preserves the original personal asset photo and removes the replacement when timeline emission fails', function () {
    Storage::fake('public');

    $user = User::factory()->create(['organization_id' => 1]);
    grantClientLocationAssetPermissions($user, [
        'clients.viewAny',
        'clients.update',
    ]);
    $client = Client::factory()->create(['organization_id' => 1]);
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
    $site = makeClientLocationAssetSite(1, 'Panic Lifecycle Home');
    $client = Client::factory()->create([
        'organization_id' => 1,
        'site_id' => $site->id,
        'status' => 'active',
    ]);
    $manager = User::factory()->create(['organization_id' => 1]);
    grantClientLocationAssetPermissions($manager, [
        'clients.viewAny',
        'assets.viewAny',
        'assets.telemetry.view',
        'assets.trackers.manage',
    ]);
    grantClientLocationAssetTrackingConsent($client, $manager);

    $openAlert = ControlRoomAlert::factory()->open()->create([
        'client_id' => $client->id,
        'source' => 'tracker',
    ]);
    $triagingAlert = ControlRoomAlert::factory()->triaging()->create([
        'client_id' => $client->id,
        'source' => 'resident_tracker',
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
