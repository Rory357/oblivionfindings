<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Models\Device as CanonicalDevice;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Client;
use App\Models\ConsentType;
use App\Models\ControlRoom\Device as ControlRoomDevice;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoomAlert;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AuthoritativeConsentFixture;
use Tests\TestCase;

class ControlRoomDeviceVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Site $visibleSite;

    private Site $hiddenSite;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);

        $this->visibleSite = Site::factory()->create(['name' => 'Kowhai House']);
        $this->hiddenSite = Site::factory()->create(['name' => 'Rimu House']);
        $this->operator = User::factory()->create(['approved_at' => now()]);

        $permissions = Permission::query()
            ->whereIn('key', ['controlRoom.viewAny', 'securityDevices.devices.view'])
            ->get()
            ->keyBy('key');
        $this->assertCount(2, $permissions);
        $this->operator->permissionOverrides()->sync(
            $permissions->mapWithKeys(fn (Permission $permission): array => [
                $permission->id => ['allowed' => true],
            ]),
        );

        HrEmployeeProfile::factory()->create([
            'user_id' => $this->operator->id,
            'primary_site_id' => $this->visibleSite->id,
            'secondary_site_ids' => [],
        ]);
    }

    public function test_list_counts_filters_and_direct_objects_follow_site_access(): void
    {
        $visible = ControlRoomDevice::query()->create([
            'name' => 'Visible signal projection',
            'type' => ControlRoomDevice::TYPE_SENSOR,
            'site_id' => $this->visibleSite->id,
            'status' => 'online',
            'last_signal_at' => now(),
        ]);
        $hidden = ControlRoomDevice::query()->create([
            'name' => 'Hidden signal projection',
            'type' => ControlRoomDevice::TYPE_CAMERA,
            'site_id' => $this->hiddenSite->id,
            'status' => 'offline',
            'battery_level' => 10,
        ]);
        $hiddenCanonical = CanonicalDevice::factory()->security()->create();
        DeviceAssignment::query()->create([
            'device_id' => $hiddenCanonical->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $this->hiddenSite->id,
            'assigned_at' => now()->subHour(),
        ]);
        $forgedProjection = ControlRoomDevice::query()->create([
            'name' => 'Forged visible projection',
            'type' => ControlRoomDevice::TYPE_SENSOR,
            'site_id' => $this->visibleSite->id,
            'canonical_device_id' => $hiddenCanonical->id,
            'status' => 'online',
        ]);

        $this->actingAs($this->operator)
            ->get('/control-room/devices')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('devices.data', 1)
                ->where('devices.data.0.id', $visible->id)
                ->where('stats.signal_sources', 1)
                ->where('stats.active_24h', 1)
                ->where('stats.canonical_linked', 0)
                ->where('stats.reconciliation_needed', 1)
                ->has('sites', 1)
                ->where('sites.0.id', $this->visibleSite->id)
            );

        $this->actingAs($this->operator)
            ->get("/control-room/devices?site_id={$this->hiddenSite->id}")
            ->assertForbidden();

        $this->actingAs($this->operator)
            ->get("/control-room/devices/{$hidden->id}")
            ->assertNotFound();
        $this->actingAs($this->operator)
            ->get("/control-room/devices/{$forgedProjection->id}")
            ->assertNotFound();
    }

    public function test_linked_projection_uses_canonical_identity_and_never_exposes_raw_config_or_payload(): void
    {
        $canonical = CanonicalDevice::factory()->security()->create([
            'name' => 'Front entrance camera',
            'manufacturer' => 'Canonical Cameras',
            'model' => 'CC-4K',
            'config' => ['admin_password' => 'canonical-secret-sentinel'],
            'battery_level' => 88,
        ]);
        DeviceAssignment::query()->create([
            'device_id' => $canonical->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $this->visibleSite->id,
            'assignment_type' => 'permanent',
            'assigned_at' => now(),
            'assigned_by_user_id' => $this->operator->id,
        ]);
        $projection = ControlRoomDevice::query()->create([
            'name' => 'Stale duplicated name',
            'device_uid' => 'legacy-duplicate-uid',
            'type' => ControlRoomDevice::TYPE_CAMERA,
            'vendor' => 'Stale vendor',
            'model' => 'Stale model',
            'site_id' => $this->visibleSite->id,
            'status' => 'online',
            'config' => ['api_token' => 'projection-secret-sentinel'],
            'canonical_device_id' => $canonical->id,
        ]);
        Signal::query()->create([
            'device_id' => $projection->id,
            'site_id' => $this->visibleSite->id,
            'signal_type_code' => 'camera.offline',
            'severity_hint' => 'high',
            'occurred_at' => now(),
            'status' => 'processed',
            'payload' => [
                'raw_provider_payload' => 'raw-signal-sentinel',
                'credential' => 'signal-secret-sentinel',
            ],
            'normalized_data' => ['title' => 'Camera offline'],
        ]);

        $this->actingAs($this->operator)
            ->get("/control-room/devices/{$projection->id}")
            ->assertOk()
            ->assertInertia(function ($page) use ($canonical): void {
                $props = $page->toArray()['props'];
                $device = $props['device'];
                $signal = $props['signals'][0];

                $this->assertSame('Front entrance camera', $device['name']);
                $this->assertSame($canonical->device_uid, $device['device_uid']);
                $this->assertSame('Canonical Cameras', $device['vendor']);
                $this->assertSame('CC-4K', $device['model']);
                $this->assertSame('canonical', $device['identity_source']);
                $this->assertSame(
                    "/security-devices/devices/{$canonical->id}",
                    $device['canonical']['detail_url'],
                );
                $this->assertArrayNotHasKey('config', $device);
                $this->assertArrayNotHasKey('payload', $signal);
                $this->assertArrayNotHasKey('normalized_data', $signal);
                $this->assertSame('Processed', $signal['outcome']['label']);

                $encoded = json_encode($props, JSON_THROW_ON_ERROR);
                $this->assertStringNotContainsString('canonical-secret-sentinel', $encoded);
                $this->assertStringNotContainsString('projection-secret-sentinel', $encoded);
                $this->assertStringNotContainsString('raw-signal-sentinel', $encoded);
                $this->assertStringNotContainsString('signal-secret-sentinel', $encoded);
            });
    }

    public function test_visible_signal_only_projection_remains_explicitly_labelled(): void
    {
        $projection = ControlRoomDevice::query()->create([
            'name' => 'Legacy alarm receiver',
            'type' => ControlRoomDevice::TYPE_ALARM_PANEL,
            'site_id' => $this->visibleSite->id,
            'status' => 'online',
        ]);

        $this->actingAs($this->operator)
            ->get("/control-room/devices/{$projection->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('device.id', $projection->id)
                ->where('device.identity_source', 'signal_projection')
                ->where('device.canonical', null)
            );
    }

    public function test_canonical_identity_requires_both_control_room_and_security_devices_access(): void
    {
        $controlRoomOnly = User::factory()->create(['approved_at' => now()]);
        $controlRoomPermission = Permission::query()
            ->where('key', 'controlRoom.viewAny')
            ->firstOrFail();
        $controlRoomOnly->permissionOverrides()->sync([
            $controlRoomPermission->id => ['allowed' => true],
        ]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $controlRoomOnly->id,
            'primary_site_id' => $this->visibleSite->id,
            'secondary_site_ids' => [],
        ]);

        $canonical = CanonicalDevice::factory()->security()->create([
            'name' => 'Source-restricted canonical name',
        ]);
        DeviceAssignment::query()->create([
            'device_id' => $canonical->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $this->visibleSite->id,
            'assignment_type' => 'permanent',
            'assigned_at' => now(),
            'assigned_by_user_id' => $controlRoomOnly->id,
        ]);
        $projection = ControlRoomDevice::query()->create([
            'name' => 'Visible signal identity',
            'type' => ControlRoomDevice::TYPE_CAMERA,
            'site_id' => $this->visibleSite->id,
            'status' => 'online',
            'canonical_device_id' => $canonical->id,
        ]);

        $this->actingAs($controlRoomOnly)
            ->get('/control-room/devices')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('devices.data.0.identity_source', 'signal_projection')
                ->where('stats.canonical_linked', null)
                ->where('stats.reconciliation_needed', null)
                ->where('can.view_canonical_devices', false)
                ->where('canonicalIndexUrl', null)
            );

        $this->actingAs($controlRoomOnly)
            ->get('/control-room/devices?linkage=linked')
            ->assertForbidden();

        $this->actingAs($controlRoomOnly)
            ->get("/control-room/devices/{$projection->id}")
            ->assertOk()
            ->assertInertia(function ($page): void {
                $props = $page->toArray()['props'];

                $this->assertSame('Visible signal identity', $props['device']['name']);
                $this->assertSame('signal_projection', $props['device']['identity_source']);
                $this->assertNull($props['device']['canonical']);
                $this->assertStringNotContainsString(
                    'Source-restricted canonical name',
                    json_encode($props, JSON_THROW_ON_ERROR),
                );
            });
    }

    public function test_personal_tracker_projection_requires_exact_resident_location_consent(): void
    {
        $this->grant($this->operator, ['assets.telemetry.view', 'clients.viewAny']);
        $client = Client::factory()->create([
            'site_id' => $this->visibleSite->id,
            'status' => 'active',
        ]);
        $consentType = ConsentType::factory()->create([
            'name' => 'Fleet Tracking',
            'active' => true,
        ]);
        $consent = AuthoritativeConsentFixture::manualSelf($client, $consentType, $this->operator, [
            'status' => 'given',
            'given_at' => now()->subDay(),
        ]);
        $canonical = CanonicalDevice::factory()->tracking()->create();
        $collectionStartedAt = now()->subHour();
        DeviceAssignment::query()->create([
            'device_id' => $canonical->id,
            'assignable_type' => DeviceAssignment::TARGET_CLIENT,
            'assignable_id' => $client->id,
            'assigned_at' => $collectionStartedAt,
            'consent_id' => $consent->id,
        ]);
        $projection = ControlRoomDevice::query()->create([
            'name' => 'Resident location projection',
            'type' => ControlRoomDevice::TYPE_PERSONAL_TRACKER,
            'site_id' => $this->visibleSite->id,
            'client_id' => $client->id,
            'canonical_device_id' => $canonical->id,
            'status' => 'online',
            'latitude' => -36.84,
            'longitude' => 174.76,
            'location_description' => 'Private resident location',
        ]);
        foreach ([now()->subHours(2), now()] as $occurredAt) {
            Signal::query()->create([
                'device_id' => $projection->id,
                'site_id' => $this->visibleSite->id,
                'signal_type_code' => 'resident.position',
                'severity_hint' => 'info',
                'occurred_at' => $occurredAt,
                'status' => 'processed',
                'payload' => [],
                'normalized_data' => ['title' => 'Resident position'],
            ]);
            ControlRoomAlert::factory()->open()->create([
                'device_id' => $projection->id,
                'site_id' => $this->visibleSite->id,
                'client_id' => $client->id,
                'source' => 'resident_tracker',
                'alert_type' => 'wandering',
                'triggered_at' => $occurredAt,
            ]);
        }

        $this->actingAs($this->operator)
            ->get('/control-room/devices')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('devices.data', 0)
                ->where('stats.signal_sources', 0));
        $this->actingAs($this->operator)
            ->get("/control-room/devices/{$projection->id}")
            ->assertNotFound();

        $consentType->update(['name' => 'Personal Tracker (Wandering Risk)']);

        $this->actingAs($this->operator)
            ->get('/control-room/devices')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('devices.data', 1)
                ->where('devices.data.0.location_description', 'Private resident location'));
        $this->actingAs($this->operator)
            ->get("/control-room/devices/{$projection->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('device.latitude', -36.84)
                ->where('device.longitude', 174.76)
                ->has('signals', 1)
                ->has('alerts', 1));
    }

    /** @param list<string> $keys */
    private function grant(User $user, array $keys): void
    {
        $permissions = Permission::query()->whereIn('key', $keys)->get();
        $this->assertCount(count($keys), $permissions);
        $user->permissionOverrides()->syncWithoutDetaching(
            $permissions->mapWithKeys(fn (Permission $permission): array => [
                $permission->id => ['allowed' => true],
            ]),
        );
    }
}
