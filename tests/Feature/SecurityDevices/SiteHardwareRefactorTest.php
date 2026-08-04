<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\AuditLog;
use App\Models\LocationHardware;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Models\SiteTypePlanPin;
use App\Models\User;
use App\Services\Integration\UnifiOperationalBridgeService;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SiteHardwareRefactorTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $noPerms;

    private Site $siteA;

    private Site $siteB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->noPerms = User::factory()->create();

        $this->siteA = Site::factory()->create(['name' => 'Site Alpha']);
        $this->siteB = Site::factory()->create(['name' => 'Site Beta']);
    }

    // ── Authorization ─────────────────────────────────────────────

    public function test_requires_authentication(): void
    {
        $this->get("/sites/{$this->siteA->id}/hardware")->assertRedirect('/login');
    }

    // ── Data source is canonical devices ──────────────────────────

    public function test_returns_devices_assigned_to_site(): void
    {
        $device = Device::factory()->create(['name' => 'Camera Alpha']);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'site',
            'assignable_id' => $this->siteA->id,
            'assigned_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/sites/{$this->siteA->id}/hardware");

        $response->assertOk();
        $response->assertInertia(function ($page) {
            $devices = $page->toArray()['props']['devices'];
            $this->assertCount(1, $devices);
            $this->assertEquals('Camera Alpha', $devices[0]['name']);
            $this->assertArrayHasKey('device_uid', $devices[0]);
            $this->assertArrayHasKey('domain', $devices[0]);
            $this->assertArrayHasKey('health_status', $devices[0]);
            $this->assertArrayNotHasKey('legacy_location_hardware_id', $devices[0]);
        });
    }

    public function test_returns_devices_assigned_to_rooms_within_site(): void
    {
        $room = SiteRoom::create([
            'site_id' => $this->siteA->id,
            'name' => 'Server Room',
        ]);

        $device = Device::factory()->itInfrastructure()->create(['name' => 'Core Switch']);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'room',
            'assignable_id' => $room->id,
            'assigned_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/sites/{$this->siteA->id}/hardware");

        $response->assertInertia(function ($page) use ($room) {
            $devices = $page->toArray()['props']['devices'];
            $this->assertCount(1, $devices);
            $this->assertEquals('Core Switch', $devices[0]['name']);
            $this->assertEquals('room', $devices[0]['assignment_type']);
            $this->assertEquals($room->id, $devices[0]['assignment_id']);
        });
    }

    public function test_does_not_return_devices_from_other_sites(): void
    {
        // Device at Site A.
        $deviceA = Device::factory()->create(['name' => 'Device at A']);
        DeviceAssignment::create([
            'device_id' => $deviceA->id,
            'assignable_type' => 'site',
            'assignable_id' => $this->siteA->id,
            'assigned_at' => now(),
        ]);

        // Device at Site B.
        $deviceB = Device::factory()->create(['name' => 'Device at B']);
        DeviceAssignment::create([
            'device_id' => $deviceB->id,
            'assignable_type' => 'site',
            'assignable_id' => $this->siteB->id,
            'assigned_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/sites/{$this->siteA->id}/hardware");

        $response->assertInertia(function ($page) {
            $devices = $page->toArray()['props']['devices'];
            $this->assertCount(1, $devices);
            $this->assertEquals('Device at A', $devices[0]['name']);
        });
    }

    public function test_does_not_return_released_assignments(): void
    {
        $device = Device::factory()->create(['name' => 'Former Device']);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'site',
            'assignable_id' => $this->siteA->id,
            'assigned_at' => now()->subDays(30),
            'released_at' => now()->subDays(5), // released
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/sites/{$this->siteA->id}/hardware");

        $response->assertInertia(function ($page) {
            $devices = $page->toArray()['props']['devices'];
            $this->assertCount(0, $devices);
        });
    }

    public function test_unassigned_devices_do_not_appear(): void
    {
        // Device with no assignment.
        Device::factory()->create(['name' => 'Floating Device']);

        $response = $this->actingAs($this->admin)
            ->get("/sites/{$this->siteA->id}/hardware");

        $response->assertInertia(function ($page) {
            $this->assertCount(0, $page->toArray()['props']['devices']);
        });
    }

    // ── Device data shape ─────────────────────────────────────────

    public function test_device_data_includes_canonical_fields(): void
    {
        $device = Device::factory()->security()->create([
            'name' => 'Dome Camera',
            'manufacturer' => 'Hikvision',
            'model' => 'DS-2CD2143G2',
            'serial_number' => 'HIK-001',
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'ip_address' => '192.168.1.100',
            'firmware_version' => '5.7.1',
            'battery_level' => null,
        ]);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'site',
            'assignable_id' => $this->siteA->id,
            'assigned_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/sites/{$this->siteA->id}/hardware");

        $response->assertInertia(function ($page) {
            $d = $page->toArray()['props']['devices'][0];
            $this->assertEquals('Dome Camera', $d['name']);
            $this->assertNotEmpty($d['device_uid']);
            $this->assertEquals('security', $d['domain']);
            $this->assertEquals('cctv', $d['category']);
            $this->assertEquals('Hikvision', $d['manufacturer']);
            $this->assertEquals('DS-2CD2143G2', $d['model']);
            $this->assertEquals('HIK-001', $d['serial_number']);
            $this->assertEquals('AA:BB:CC:DD:EE:FF', $d['mac_address']);
            $this->assertEquals('192.168.1.100', $d['ip_address']);
            $this->assertEquals('5.7.1', $d['firmware_version']);
            $this->assertArrayHasKey('status', $d);
            $this->assertArrayHasKey('health_status', $d);
        });
    }

    // ── Integration/rooms data still present ──────────────────────

    public function test_rooms_and_unifi_data_still_passed_without_legacy_site_hardware_props(): void
    {
        SiteRoom::create([
            'site_id' => $this->siteA->id,
            'name' => 'Reception',
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/sites/{$this->siteA->id}/hardware");

        $response->assertInertia(function ($page) {
            $props = $page->toArray()['props'];
            $this->assertNotEmpty($props['rooms']);
            $this->assertArrayHasKey('unifi', $props);
            $this->assertArrayHasKey('can', $props);
            $this->assertArrayNotHasKey('hardware', $props);
            $this->assertArrayNotHasKey('assets', $props);
            $this->assertArrayNotHasKey('categories', $props);
        });
    }

    public function test_legacy_site_hardware_crud_routes_are_removed_but_room_bridge_routes_remain(): void
    {
        $this->assertFalse(Route::has('sites.hardware.store'));
        $this->assertFalse(Route::has('sites.hardware.update'));
        $this->assertFalse(Route::has('sites.hardware.destroy'));
        $this->assertFalse(Route::has('sites.hardware.linkAsset'));
        $this->assertFalse(Route::has('sites.hardware.refreshStatus'));

        $this->assertTrue(Route::has('sites.hardware.assignRoom'));
        $this->assertTrue(Route::has('sites.hardware.manageRooms'));
    }

    public function test_room_names_are_unique_within_a_site(): void
    {
        SiteRoom::create([
            'site_id' => $this->siteA->id,
            'name' => 'Network room',
            'sort_order' => 1,
        ]);

        $this->actingAs($this->admin)
            ->postJson("/sites/{$this->siteA->id}/hardware/rooms", [
                'action' => 'add',
                'name' => ' Network room ',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->assertSame(1, SiteRoom::query()
            ->where('site_id', $this->siteA->id)
            ->where('name', 'Network room')
            ->count());
    }

    public function test_room_reorder_rejects_a_room_from_another_site_without_partial_mutation(): void
    {
        $roomA = SiteRoom::create([
            'site_id' => $this->siteA->id,
            'name' => 'First room',
            'sort_order' => 1,
        ]);
        $roomB = SiteRoom::create([
            'site_id' => $this->siteA->id,
            'name' => 'Second room',
            'sort_order' => 2,
        ]);
        $otherSiteRoom = SiteRoom::create([
            'site_id' => $this->siteB->id,
            'name' => 'Private room',
            'sort_order' => 9,
        ]);

        $this->actingAs($this->admin)
            ->postJson("/sites/{$this->siteA->id}/hardware/rooms", [
                'action' => 'reorder',
                'rooms' => [
                    ['id' => $roomA->id, 'sort_order' => 2],
                    ['id' => $otherSiteRoom->id, 'sort_order' => 1],
                ],
            ])
            ->assertNotFound();

        $this->assertSame(1, $roomA->fresh()->sort_order);
        $this->assertSame(2, $roomB->fresh()->sort_order);
        $this->assertSame(9, $otherSiteRoom->fresh()->sort_order);
    }

    public function test_room_with_a_current_device_assignment_cannot_be_deleted(): void
    {
        $room = SiteRoom::create([
            'site_id' => $this->siteA->id,
            'name' => 'Occupied room',
            'sort_order' => 1,
        ]);
        $device = Device::factory()->itInfrastructure()->create();
        $assignment = DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_ROOM,
            'assignable_id' => $room->id,
            'assigned_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->postJson("/sites/{$this->siteA->id}/hardware/rooms", [
                'action' => 'delete',
                'room_id' => $room->id,
            ])
            ->assertStatus(409);

        $this->assertDatabaseHas('site_rooms', ['id' => $room->id]);
        $this->assertDatabaseHas('device_assignments', [
            'id' => $assignment->id,
            'released_at' => null,
        ]);
    }

    public function test_assign_room_route_updates_canonical_assignment_for_unifi_devices(): void
    {
        $room = SiteRoom::create([
            'site_id' => $this->siteA->id,
            'name' => 'Network Closet',
        ]);

        $shadow = LocationHardware::create([
            'site_id' => $this->siteA->id,
            'provider' => 'unifi',
            'category' => LocationHardware::CATEGORY_SWITCH,
            'name' => 'Core Switch',
            'status' => LocationHardware::STATUS_ONLINE,
            'external_ref' => ['provider_entity_id' => 'switch-1'],
        ]);

        $device = Device::factory()->itInfrastructure()->create([
            'provider' => 'unifi',
            'name' => 'Core Switch',
            'legacy_location_hardware_id' => $shadow->id,
            'external_ref' => ['provider_entity_id' => 'switch-1'],
        ]);

        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'site',
            'assignable_id' => $this->siteA->id,
            'assigned_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->post("/sites/{$this->siteA->id}/hardware/{$device->id}/assign-room", [
                'room_id' => $room->id,
            ])
            ->assertRedirect();

        $active = $device->fresh()->assignments()->active()->first();

        $this->assertNotNull($active);
        $this->assertEquals('room', $active->assignable_type);
        $this->assertEquals($room->id, $active->assignable_id);

        // The canonical DeviceAssignment is authoritative; legacy
        // LocationHardware.room_id is intentionally not synced — see
        // UnifiOperationalBridgeService::syncRoomAssignment.
    }

    public function test_assign_room_route_does_not_reveal_missing_or_inaccessible_rooms_or_mutate_state(): void
    {
        config()->set('app.debug', false);
        $unrelatedSite = Site::factory()->create([]);
        $unrelatedRoom = SiteRoom::create([
            'site_id' => $unrelatedSite->id,
            'name' => 'Unrelated Site room',
        ]);
        $wrongSiteRoom = SiteRoom::create([
            'site_id' => $this->siteB->id,
            'name' => 'Wrong route site room',
        ]);
        $contradictoryRoom = SiteRoom::create([
            'site_id' => $unrelatedSite->id,
            'name' => 'Contradictory room and parent Site',
        ]);
        $shadow = LocationHardware::create([
            'site_id' => $this->siteA->id,
            'provider' => 'unifi',
            'category' => LocationHardware::CATEGORY_SWITCH,
            'name' => 'Protected shadow',
            'status' => LocationHardware::STATUS_ONLINE,
            'external_ref' => ['provider_entity_id' => 'protected-site-route-switch'],
        ]);
        $device = Device::factory()->itInfrastructure()->create([
            'provider' => 'unifi',
            'legacy_location_hardware_id' => $shadow->id,
            'external_ref' => ['provider_entity_id' => 'protected-site-route-switch'],
            'latitude' => '-36.84850000',
            'longitude' => '174.76330000',
            'location_description' => 'Protected rack',
        ]);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $this->siteA->id,
            'assigned_at' => now(),
        ]);

        $before = $this->captureRoomAssignmentMutationState($device->id);
        $roomIds = [
            SiteRoom::query()->max('id') + 1000,
            $unrelatedRoom->id,
            $wrongSiteRoom->id,
            $contradictoryRoom->id,
        ];
        $responses = collect($roomIds)->map(fn (int $roomId) => $this->actingAs($this->admin)
            ->postJson("/sites/{$this->siteA->id}/hardware/{$device->id}/assign-room", [
                'room_id' => $roomId,
            ]));

        $this->assertSame([
            'statuses' => [404, 404, 404, 404],
            'responses_match' => true,
            'state_unchanged' => true,
        ], [
            'statuses' => $responses->map->getStatusCode()->all(),
            'responses_match' => $responses->map->getContent()->unique()->count() === 1,
            'state_unchanged' => $before === $this->captureRoomAssignmentMutationState($device->id),
        ]);
    }

    public function test_assign_room_route_clears_room_to_the_current_site(): void
    {
        $room = SiteRoom::create([
            'site_id' => $this->siteA->id,
            'name' => 'Network Closet',
        ]);
        $device = Device::factory()->itInfrastructure()->create([
            'provider' => 'unifi',
        ]);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_ROOM,
            'assignable_id' => $room->id,
            'assigned_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->post("/sites/{$this->siteA->id}/hardware/{$device->id}/assign-room", [
                'room_id' => null,
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Hardware room assignment updated.');

        $active = $device->fresh()->assignments()->active()->sole();
        $this->assertSame(DeviceAssignment::TARGET_SITE, $active->assignable_type);
        $this->assertSame($this->siteA->id, $active->assignable_id);
    }

    public function test_clear_rechecks_the_authorized_route_site_after_a_stale_provenance_move(): void
    {
        config()->set('app.debug', false);
        $roomA = SiteRoom::create([
            'site_id' => $this->siteA->id,
            'name' => 'Initially authorized room',
        ]);
        $roomB = SiteRoom::create([
            'site_id' => $this->siteB->id,
            'name' => 'Concurrently moved room',
        ]);
        $shadow = LocationHardware::create([
            'site_id' => $this->siteA->id,
            'provider' => 'unifi',
            'category' => LocationHardware::CATEGORY_AP,
            'name' => 'Stale authorization shadow',
            'status' => LocationHardware::STATUS_ONLINE,
            'external_ref' => ['provider_entity_id' => 'stale-site-clear-ap'],
        ]);
        $device = Device::factory()->itInfrastructure()->create([
            'provider' => 'unifi',
            'legacy_location_hardware_id' => $shadow->id,
            'external_ref' => ['provider_entity_id' => 'stale-site-clear-ap'],
            'latitude' => '-36.84850000',
            'longitude' => '174.76330000',
            'location_description' => 'Initially authorized rack',
        ]);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_ROOM,
            'assignable_id' => $roomA->id,
            'assigned_at' => now(),
        ]);

        $racedState = null;
        $bridge = new class(function () use ($device, $roomB, &$racedState): void {
            $active = $device->fresh()->assignments()->active()->sole();
            $active->update([
                'released_at' => now(),
                'released_by' => $this->admin->id,
            ]);
            DeviceAssignment::create([
                'device_id' => $device->id,
                'assignable_type' => DeviceAssignment::TARGET_ROOM,
                'assignable_id' => $roomB->id,
                'assigned_at' => now(),
                'assigned_by' => $this->admin->id,
            ]);
            $racedState = $this->captureRoomAssignmentMutationState($device->id);
        }) extends UnifiOperationalBridgeService
        {

            public function __construct(private readonly \Closure $beforeTransaction) {}

            public function syncRoomAssignment(
                Device $device,
                ?SiteRoom $room,
                ?int $userId,
                ?int $expectedSiteId,
            ): DeviceAssignment {
                ($this->beforeTransaction)();

                return parent::syncRoomAssignment($device, $room, $userId, $expectedSiteId);
            }
        };
        $this->app->instance(UnifiOperationalBridgeService::class, $bridge);

        $denied = $this->actingAs($this->admin)->postJson(
            "/sites/{$this->siteA->id}/hardware/{$device->id}/assign-room",
            ['room_id' => $roomB->id],
        );
        $response = $this->actingAs($this->admin)->postJson(
            "/sites/{$this->siteA->id}/hardware/{$device->id}/assign-room",
            ['room_id' => null],
        );

        $this->assertSame([
            'statuses' => [404, 404],
            'responses_match' => true,
            'race_captured' => true,
            'state_unchanged_after_race' => true,
        ], [
            'statuses' => [$denied->getStatusCode(), $response->getStatusCode()],
            'responses_match' => $denied->getContent() === $response->getContent(),
            'race_captured' => $racedState !== null,
            'state_unchanged_after_race' => $racedState === $this->captureRoomAssignmentMutationState($device->id),
        ]);
    }

    public function test_release_marks_device_plan_pin_stale(): void
    {
        $device = Device::factory()->security()->create(['name' => 'Front Camera']);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'site',
            'assignable_id' => $this->siteA->id,
            'assigned_at' => now()->subHour(),
        ]);
        $pin = $this->createDevicePlanPin($device, [
            'meta' => ['device_id' => $device->id, 'stale' => false],
        ]);

        $this->actingAs($this->admin)
            ->post("/security-devices/devices/{$device->id}/release")
            ->assertRedirect();

        $pin->refresh();
        $this->assertTrue($pin->meta['stale'] ?? false);
        $this->assertArrayHasKey('released_at', $pin->meta);
        $this->assertSame('assignment_released', $pin->meta['stale_reason'] ?? null);
    }

    private function captureRoomAssignmentMutationState(int $deviceId): array
    {
        return [
            'device' => Device::query()->findOrFail($deviceId)->getAttributes(),
            'location_hardware' => LocationHardware::withTrashed()
                ->orderBy('id')
                ->get()
                ->map(fn (LocationHardware $hardware) => $hardware->getAttributes())
                ->all(),
            'device_assignments' => DeviceAssignment::query()
                ->where('device_id', $deviceId)
                ->orderBy('id')
                ->get()
                ->map(fn (DeviceAssignment $assignment) => $assignment->getAttributes())
                ->all(),
            'audit_logs' => AuditLog::query()
                ->orderBy('id')
                ->get()
                ->map(fn (AuditLog $audit) => $audit->getAttributes())
                ->all(),
        ];
    }

    public function test_room_move_marks_device_plan_pin_stale(): void
    {
        $roomA = SiteRoom::create([
            'site_id' => $this->siteA->id,
            'name' => 'Hallway',
        ]);
        $roomB = SiteRoom::create([
            'site_id' => $this->siteA->id,
            'name' => 'Network Closet',
        ]);

        $device = Device::factory()->itInfrastructure()->create([
            'provider' => 'unifi',
            'name' => 'Access Point',
        ]);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'room',
            'assignable_id' => $roomA->id,
            'assigned_at' => now()->subHour(),
        ]);
        $pin = $this->createDevicePlanPin($device, [
            'meta' => ['device_id' => $device->id, 'stale' => false],
        ]);

        $this->actingAs($this->admin)
            ->post("/sites/{$this->siteA->id}/hardware/{$device->id}/assign-room", [
                'room_id' => $roomB->id,
            ])
            ->assertRedirect();

        $pin->refresh();
        $this->assertTrue($pin->meta['stale'] ?? false);
        $this->assertArrayHasKey('replaced_at', $pin->meta);
        $this->assertSame('assignment_replaced', $pin->meta['stale_reason'] ?? null);
    }

    public function test_pin_room_assigned_device_creates_a_draft_without_mutating_the_published_plan(): void
    {
        $planId = DB::table('site_type_plans')->insertGetId([
            'site_id' => $this->siteA->id,
            'site_type' => $this->siteA->type,
            'status' => 'published',
            'current_slot' => 'published',
            'version' => 1,
            'layout' => json_encode([
                'schema_version' => 1,
                'canvas' => ['width' => 1000, 'height' => 700, 'unit' => 'rel'],
                'rooms' => [],
                'walls' => [],
                'doors' => [],
                'windows' => [],
                'labels' => [],
            ]),
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $room = SiteRoom::create([
            'site_id' => $this->siteA->id,
            'name' => 'Reception',
        ]);
        $device = Device::factory()->security()->create(['name' => 'Front Camera']);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_ROOM,
            'assignable_id' => $room->id,
            'assigned_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->postJson("/sites/{$this->siteA->id}/hardware/{$device->id}/pin", [
                'x' => 0.42,
                'y' => 0.33,
                'label' => 'Front door camera',
            ])
            ->assertOk()
            ->assertJsonPath('pin.kind', 'device')
            ->assertJsonPath('pin.device_id', $device->id);

        $draftId = DB::table('site_type_plans')
            ->where('site_id', $this->siteA->id)
            ->where('current_slot', 'draft')
            ->value('id');

        $this->assertNotNull($draftId);
        $this->assertDatabaseHas('site_type_plan_pins', [
            'site_type_plan_id' => $draftId,
            'kind' => 'device',
            'device_id' => $device->id,
            'label' => 'Front door camera',
        ]);
        $this->assertDatabaseMissing('site_type_plan_pins', [
            'site_type_plan_id' => $planId,
            'device_id' => $device->id,
        ]);
    }

    public function test_unpin_device_removes_the_draft_copy_and_preserves_the_published_pin(): void
    {
        $planId = DB::table('site_type_plans')->insertGetId([
            'site_id' => $this->siteA->id,
            'site_type' => $this->siteA->type,
            'status' => 'published',
            'current_slot' => 'published',
            'version' => 1,
            'layout' => json_encode(['schema_version' => 1, 'canvas' => ['width' => 1000, 'height' => 700]]),
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $device = Device::factory()->security()->create();
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $this->siteA->id,
            'assigned_at' => now(),
        ]);

        DB::table('site_type_plan_pins')->insert([
            'site_type_plan_id' => $planId,
            'kind' => 'device',
            'device_id' => $device->id,
            'label' => 'Front camera',
            'x' => 0.5,
            'y' => 0.5,
            'rotation_deg' => 0,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->deleteJson("/sites/{$this->siteA->id}/hardware/{$device->id}/pin")
            ->assertOk();

        $draftId = DB::table('site_type_plans')
            ->where('site_id', $this->siteA->id)
            ->where('current_slot', 'draft')
            ->value('id');

        $this->assertNotNull($draftId);
        $this->assertDatabaseHas('site_type_plan_pins', [
            'site_type_plan_id' => $planId,
            'kind' => 'device',
            'device_id' => $device->id,
        ]);
        $this->assertDatabaseMissing('site_type_plan_pins', [
            'site_type_plan_id' => $draftId,
            'kind' => 'device',
            'device_id' => $device->id,
        ]);

        $this->actingAs($this->admin)
            ->get("/sites/{$this->siteA->id}/hardware")
            ->assertInertia(fn ($page) => $page->where('devices.0.plan_pin', null));
    }

    public function test_pin_device_from_another_site_is_concealed_before_a_draft_is_created(): void
    {
        DB::table('site_type_plans')->insert([
            'site_id' => $this->siteA->id,
            'site_type' => $this->siteA->type,
            'status' => 'published',
            'current_slot' => 'published',
            'version' => 1,
            'layout' => json_encode(['schema_version' => 1, 'canvas' => ['width' => 1000, 'height' => 700]]),
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $device = Device::factory()->security()->create();
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $this->siteB->id,
            'assigned_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->postJson("/sites/{$this->siteA->id}/hardware/{$device->id}/pin", [
                'x' => 0.42,
                'y' => 0.33,
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('site_type_plans', [
            'site_id' => $this->siteA->id,
            'current_slot' => 'draft',
        ]);
        $this->assertDatabaseCount('site_type_plan_pins', 0);
    }

    private function createDevicePlanPin(Device $device, array $overrides = []): SiteTypePlanPin
    {
        $planId = DB::table('site_type_plans')->insertGetId([
            'site_id' => $this->siteA->id,
            'site_type' => $this->siteA->type,
            'status' => 'published',
            'current_slot' => 'published',
            'version' => 1,
            'layout' => json_encode(['schema_version' => 1, 'canvas' => ['width' => 1000, 'height' => 700]]),
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return SiteTypePlanPin::create(array_merge([
            'site_type_plan_id' => $planId,
            'kind' => SiteTypePlanPin::KIND_DEVICE,
            'device_id' => $device->id,
            'label' => $device->name,
            'meta' => ['stale' => false],
            'x' => 0.5,
            'y' => 0.5,
            'rotation_deg' => 0,
            'sort_order' => 0,
        ], $overrides));
    }
}
