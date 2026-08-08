<?php

namespace Tests\Feature\Sites;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteHouseRoom;
use App\Models\SiteRoom;
use App\Models\User;
use App\Services\Sites\SiteClientPlacementService;
use App\Services\Sites\SitePhysicalRoomService;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SitePhysicalRoomBridgeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Site $site;

    private SitePhysicalRoomService $rooms;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
        $this->site = Site::factory()->create([
            'type' => 'house',
            'is_active' => true,
            'archived' => false,
            'archived_at' => null,
        ]);
        $this->rooms = app(SitePhysicalRoomService::class);
    }

    public function test_residential_room_asset_and_hardware_rename_share_one_canonical_identity(): void
    {
        $this->actingAs($this->admin)
            ->postJson(route('sites.rooms.store', $this->site), [
                'name' => 'Bedroom 1',
                'is_assignable' => true,
            ])
            ->assertRedirect();
        $residential = SiteHouseRoom::query()
            ->where('site_id', $this->site->id)
            ->where('name', 'Bedroom 1')
            ->firstOrFail();
        $canonicalId = (int) $residential->site_room_id;
        $asset = Asset::factory()->create(['site_id' => $this->site->id]);
        $this->rooms->placeAsset($this->site, $residential, $asset);
        $device = Device::factory()->itInfrastructure()->create();
        $assignment = DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_ROOM,
            'assignable_id' => $canonicalId,
            'assigned_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('sites.hardware.manageRooms', $this->site), [
                'action' => 'rename',
                'room_id' => $canonicalId,
                'name' => 'Bedroom North',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('site_rooms', [
            'id' => $canonicalId,
            'site_id' => $this->site->id,
            'name' => 'Bedroom North',
        ]);
        $this->assertDatabaseHas('site_house_rooms', [
            'id' => $residential->id,
            'site_id' => $this->site->id,
            'site_room_id' => $canonicalId,
            'name' => 'Bedroom North',
        ]);
        $this->assertDatabaseHas('assets', [
            'id' => $asset->id,
            'room_id' => $residential->id,
            'site_room_id' => $canonicalId,
        ]);
        $this->assertDatabaseHas('device_assignments', [
            'id' => $assignment->id,
            'assignable_type' => DeviceAssignment::TARGET_ROOM,
            'assignable_id' => $canonicalId,
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('sites.hardware.manageRooms', $this->site), [
                'action' => 'delete',
                'room_id' => $canonicalId,
            ])
            ->assertStatus(409);

        $this->assertDatabaseHas('site_rooms', ['id' => $canonicalId]);
    }

    public function test_deactivation_closes_client_placement_history_without_deleting_the_physical_room(): void
    {
        $room = $this->rooms->createResidentialRoom($this->site, [
            'name' => 'Bedroom 2',
            'is_active' => true,
            'is_assignable' => true,
        ]);
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        app(SiteClientPlacementService::class)->assignRoom(
            $this->site,
            $room,
            $client->id,
            $this->admin,
        );
        $historyId = (int) $room->history()->value('id');

        $this->actingAs($this->admin)
            ->delete(route('sites.rooms.destroy', [$this->site, $room]))
            ->assertRedirect();

        $this->assertDatabaseHas('site_house_rooms', [
            'id' => $room->id,
            'site_room_id' => $room->site_room_id,
            'assigned_client_id' => null,
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('site_rooms', ['id' => $room->site_room_id]);
        $this->assertDatabaseHas('site_house_room_history', ['id' => $historyId]);
        $this->assertNotNull($room->history()->whereKey($historyId)->value('assigned_until'));
        $this->assertNull($client->fresh()->room_id);

        $this->actingAs($this->admin)
            ->post(route('sites.rooms.restore', [$this->site, $room]))
            ->assertRedirect();
        $this->assertTrue((bool) $room->fresh()->is_active);
        $this->assertDatabaseHas('site_rooms', ['id' => $room->site_room_id]);
    }

    public function test_residential_reorder_updates_the_canonical_room_order_atomically(): void
    {
        $first = $this->rooms->createResidentialRoom($this->site, [
            'name' => 'First bedroom',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $second = $this->rooms->createResidentialRoom($this->site, [
            'name' => 'Second bedroom',
            'is_active' => true,
            'sort_order' => 2,
        ]);

        $this->actingAs($this->admin)
            ->patchJson(route('sites.rooms.reorder', $this->site), [
                'ordered_ids' => [$second->id, $first->id],
            ])
            ->assertRedirect();

        $this->assertSame(2, $first->fresh()->sort_order);
        $this->assertSame(2, $first->canonicalRoom()->value('sort_order'));
        $this->assertSame(1, $second->fresh()->sort_order);
        $this->assertSame(1, $second->canonicalRoom()->value('sort_order'));
    }

    public function test_room_mutations_fail_closed_for_another_site_without_partial_changes(): void
    {
        $otherSite = Site::factory()->create(['type' => 'house']);
        $otherRoom = $this->rooms->createResidentialRoom($otherSite, [
            'name' => 'Private bedroom',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->putJson(route('sites.rooms.update', [$this->site, $otherRoom]), [
                'name' => 'Leaked rename',
            ])
            ->assertNotFound();
        $this->actingAs($this->admin)
            ->postJson(route('sites.hardware.manageRooms', $this->site), [
                'action' => 'rename',
                'room_id' => $otherRoom->site_room_id,
                'name' => 'Leaked hardware rename',
            ])
            ->assertNotFound();

        $this->assertSame('Private bedroom', $otherRoom->fresh()->name);
        $this->assertSame('Private bedroom', $otherRoom->canonicalRoom()->value('name'));
    }

    public function test_residential_updates_cannot_rewrite_canonical_site_or_occupancy_identity(): void
    {
        $room = $this->rooms->createResidentialRoom($this->site, [
            'name' => 'Protected bedroom',
            'is_active' => true,
        ]);
        $otherSite = Site::factory()->create(['type' => 'house']);
        $otherCanonical = $this->rooms->createCanonicalRoom($otherSite, 'Other Site room');

        $updated = $this->rooms->updateResidentialRoom($this->site, $room, [
            'notes' => 'Allowed note',
            'site_id' => $otherSite->id,
            'site_room_id' => $otherCanonical->id,
            'assigned_client_id' => 999999,
        ]);

        $this->assertSame($this->site->id, $updated->site_id);
        $this->assertSame($room->site_room_id, $updated->site_room_id);
        $this->assertNull($updated->assigned_client_id);
        $this->assertSame('Allowed note', $updated->notes);
    }

    public function test_linked_room_resolution_rejects_cross_site_and_ambiguous_legacy_links(): void
    {
        $unlinkedResidential = SiteHouseRoom::query()->create([
            'site_id' => $this->site->id,
            'name' => 'Legacy bedroom',
            'is_active' => true,
        ]);
        $otherSite = Site::factory()->create(['type' => 'house']);
        $crossSite = SiteRoom::query()->create([
            'site_id' => $otherSite->id,
            'name' => 'Wrong Site room',
            'linked_room_type' => 'house_room',
            'linked_room_id' => $unlinkedResidential->id,
        ]);
        $first = SiteRoom::query()->create([
            'site_id' => $this->site->id,
            'name' => 'Legacy pointer one',
            'linked_room_type' => 'facility_zone',
            'linked_room_id' => 999,
        ]);
        $second = SiteRoom::query()->create([
            'site_id' => $this->site->id,
            'name' => 'Legacy pointer two',
            'linked_room_type' => 'facility_zone',
            'linked_room_id' => 999,
        ]);

        $this->assertNull($crossSite->linkedRoom());
        $this->assertNull($first->linkedRoom());
        $this->assertNull($second->linkedRoom());
    }
}
