<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceDocument;
use App\Domain\SecurityDevices\Models\DeviceRelationship;
use App\Domain\SecurityDevices\Services\DeviceDocumentLifecycleService;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeviceMutationAccessBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $admin;

    private Site $allowedSite;

    private Site $hiddenSite;

    private Device $allowedDevice;

    private Device $hiddenDevice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);

        $this->allowedSite = Site::factory()->create([]);
        $this->hiddenSite = Site::factory()->create([]);
        $this->manager = User::factory()->create();
        $this->manager->roles()->attach(Role::query()->where('name', 'facilities_manager')->firstOrFail());
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->manager->id,
            'primary_site_id' => $this->allowedSite->id,
            'secondary_site_ids' => [],
        ]);
        $this->admin = User::factory()->create();
        $this->admin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());

        $this->allowedDevice = $this->deviceAt($this->allowedSite, 'Allowed device');
        $this->hiddenDevice = $this->deviceAt($this->hiddenSite, 'Hidden device');
    }

    public function test_retained_device_mutations_reject_a_device_outside_site_scope(): void
    {
        Storage::fake('local');

        $this->actingAs($this->manager)
            ->get("/security-devices/devices/{$this->hiddenDevice->id}/edit")
            ->assertNotFound();
        $this->actingAs($this->manager)
            ->patch("/security-devices/devices/{$this->hiddenDevice->id}/fields", ['asset_tag' => 'FORBIDDEN'])
            ->assertNotFound();
        $this->actingAs($this->manager)
            ->put("/security-devices/devices/{$this->hiddenDevice->id}", [
                'name' => 'FORBIDDEN',
                'domain' => $this->hiddenDevice->domain,
                'category' => $this->hiddenDevice->category,
            ])
            ->assertNotFound();
        $document = DeviceDocument::query()->create([
            'device_id' => $this->hiddenDevice->id,
            'uploaded_by_user_id' => $this->admin->id,
            'title' => 'Hidden manual',
            'category' => 'manual',
            'storage_disk' => 'local',
            'storage_path' => 'device_documents/hidden.pdf',
            'original_name' => 'hidden.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 10,
        ]);
        $this->actingAs($this->manager)
            ->get("/security-devices/devices/{$this->hiddenDevice->id}/documents/{$document->id}")
            ->assertNotFound();
        $this->actingAs($this->manager)
            ->delete("/security-devices/devices/{$this->hiddenDevice->id}/documents/{$document->id}")
            ->assertNotFound();
        $this->actingAs($this->manager)
            ->post("/security-devices/devices/{$this->hiddenDevice->id}/release")
            ->assertNotFound();
        $this->actingAs($this->manager)
            ->getJson("/security-devices/devices/{$this->hiddenDevice->id}/assignments")
            ->assertNotFound();
        $this->actingAs($this->manager)
            ->post("/security-devices/devices/{$this->hiddenDevice->id}/documents", [
                'title' => 'Forbidden document',
                'category' => 'manual',
                'file' => UploadedFile::fake()->create('forbidden.pdf', 4, 'application/pdf'),
            ])
            ->assertNotFound();

        $this->assertSame('Hidden device', $this->hiddenDevice->fresh()->name);
        $this->assertNull($this->hiddenDevice->fresh()->asset_tag);
        $this->assertDatabaseHas('device_documents', ['id' => $document->id]);
    }

    public function test_assignment_rejects_hidden_and_unrelated_site_targets(): void
    {
        $unrelatedSite = Site::factory()->create([]);
        $hiddenRoom = SiteRoom::query()->create([
            'site_id' => $this->hiddenSite->id,
            'name' => 'Hidden comms room',
        ]);
        $hiddenClient = Client::factory()->create([

            'site_id' => $this->hiddenSite->id,
        ]);
        $hiddenStaff = User::factory()->create();
        HrEmployeeProfile::factory()->create([
            'user_id' => $hiddenStaff->id,
            'primary_site_id' => $this->hiddenSite->id,
            'secondary_site_ids' => [],
        ]);
        $hiddenVehicle = Asset::factory()->create([
            'site_id' => $this->hiddenSite->id,
            'category' => 'Vehicle',
        ]);
        $sameSiteClient = Client::factory()->create([

            'site_id' => $this->allowedSite->id,
        ]);
        $sameSiteStaff = User::factory()->create();
        HrEmployeeProfile::factory()->create([
            'user_id' => $sameSiteStaff->id,
            'primary_site_id' => $this->allowedSite->id,
            'secondary_site_ids' => [],
        ]);
        $sameSiteVehicle = Asset::factory()->create([
            'site_id' => $this->allowedSite->id,
            'category' => 'Vehicle',
        ]);
        $ordinaryAsset = Asset::factory()->create([
            'site_id' => $this->allowedSite->id,
            'category' => 'Equipment',
        ]);

        $this->actingAs($this->manager)
            ->get("/security-devices/devices/{$this->allowedDevice->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('assignmentTargets.clients', fn ($targets): bool => collect($targets)->doesntContain('id', $sameSiteClient->id))
                ->where('assignmentTargets.staff', fn ($targets): bool => collect($targets)->doesntContain('id', $sameSiteStaff->id))
                ->where('assignmentTargets.vehicles', fn ($targets): bool => collect($targets)->doesntContain('id', $sameSiteVehicle->id)));

        foreach ([
            [DeviceAssignment::TARGET_SITE, $this->hiddenSite->id],
            [DeviceAssignment::TARGET_SITE, $unrelatedSite->id],
            [DeviceAssignment::TARGET_ROOM, $hiddenRoom->id],
            [DeviceAssignment::TARGET_CLIENT, $hiddenClient->id],
            [DeviceAssignment::TARGET_STAFF, $hiddenStaff->id],
            [DeviceAssignment::TARGET_VEHICLE, $hiddenVehicle->id],
            [DeviceAssignment::TARGET_CLIENT, $sameSiteClient->id],
            [DeviceAssignment::TARGET_STAFF, $sameSiteStaff->id],
            [DeviceAssignment::TARGET_VEHICLE, $sameSiteVehicle->id],
            [DeviceAssignment::TARGET_VEHICLE, $ordinaryAsset->id],
        ] as [$targetType, $targetId]) {
            $this->actingAs($this->manager)
                ->post("/security-devices/devices/{$this->allowedDevice->id}/assign", [
                    'assignable_type' => $targetType,
                    'assignable_id' => $targetId,
                ])
                ->assertNotFound();
        }

        $this->assertDatabaseMissing('device_assignments', [
            'device_id' => $this->allowedDevice->id,
            'assignable_id' => $this->hiddenSite->id,
        ]);
        $this->assertDatabaseMissing('device_assignments', [
            'device_id' => $this->allowedDevice->id,
            'assignable_id' => $unrelatedSite->id,
        ]);
    }

    public function test_visible_asset_link_does_not_expose_or_allow_replacement_of_hidden_assignment(): void
    {
        $this->manager->permissionOverrides()->syncWithoutDetaching([
            Permission::query()->where('key', 'fleet.viewAny')->value('id') => ['allowed' => true],
        ]);
        $device = Device::factory()->create([
            'name' => 'Mixed provenance device',
            'domain' => 'it_infrastructure',
            'category' => 'networking',
        ]);
        $hiddenAssignment = DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $this->hiddenSite->id,
            'assigned_at' => now(),
            'notes' => 'HIDDEN-ASSIGNMENT-NOTE',
        ]);
        $visibleAsset = Asset::factory()->create([
            'site_id' => $this->allowedSite->id,
            'category' => 'Vehicle',
        ]);
        DeviceAssetLink::query()->create([
            'device_id' => $device->id,
            'asset_id' => $visibleAsset->id,
            'link_type' => 'primary',
            'linked_at' => now(),
            'linked_by_user_id' => $this->admin->id,
        ]);

        $this->actingAs($this->manager)
            ->get("/security-devices/devices/{$device->id}")
            ->assertNotFound();

        $this->actingAs($this->manager)
            ->getJson("/security-devices/devices/{$device->id}/assignments")
            ->assertNotFound();

        $this->actingAs($this->manager)
            ->post("/security-devices/devices/{$device->id}/release")
            ->assertNotFound();
        $this->actingAs($this->manager)
            ->post("/security-devices/devices/{$device->id}/assign", [
                'assignable_type' => DeviceAssignment::TARGET_SITE,
                'assignable_id' => $this->allowedSite->id,
            ])
            ->assertNotFound();

        $this->assertNull($hiddenAssignment->fresh()->released_at);
        $this->assertSame(1, DeviceAssignment::query()->where('device_id', $device->id)->count());
    }

    public function test_facilities_manager_with_canonical_context_can_transfer_to_same_site_targets_and_keep_access(): void
    {
        $this->manager->permissionOverrides()->syncWithoutDetaching([
            Permission::query()->where('key', 'clients.viewAny')->value('id') => ['allowed' => true],
            Permission::query()->where('key', 'staff.viewAny')->value('id') => ['allowed' => true],
            Permission::query()->where('key', 'hazards.view')->value('id') => ['allowed' => true],
            Permission::query()->where('key', 'fleet.viewAny')->value('id') => ['allowed' => true],
        ]);
        $client = Client::factory()->create([
            'site_id' => $this->allowedSite->id,
            'status' => 'active',
        ]);
        $staff = User::factory()->create();
        HrEmployeeProfile::factory()->create([
            'user_id' => $staff->id,
            'primary_site_id' => $this->allowedSite->id,
            'secondary_site_ids' => [],
        ]);
        $vehicle = Asset::factory()->create([
            'site_id' => $this->allowedSite->id,
            'category' => 'Vehicle',
        ]);
        $showHref = "/security-devices/devices/{$this->allowedDevice->id}";

        $this->actingAs($this->manager)
            ->get($showHref)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('assignmentTargets.clients', fn ($targets): bool => collect($targets)->contains('id', $client->id))
                ->where('assignmentTargets.staff', fn ($targets): bool => collect($targets)->contains('id', $staff->id))
                ->where('assignmentTargets.vehicles', fn ($targets): bool => collect($targets)->contains('id', $vehicle->id)));

        foreach ([
            [DeviceAssignment::TARGET_CLIENT, $client->id],
            [DeviceAssignment::TARGET_STAFF, $staff->id],
            [DeviceAssignment::TARGET_VEHICLE, $vehicle->id],
        ] as [$targetType, $targetId]) {
            $this->actingAs($this->manager)
                ->from($showHref)
                ->post("{$showHref}/assign", [
                    'assignable_type' => $targetType,
                    'assignable_id' => $targetId,
                ])
                ->assertRedirect($showHref);
            $this->actingAs($this->manager)->get($showHref)->assertOk();
            $this->assertDatabaseHas('device_assignments', [
                'device_id' => $this->allowedDevice->id,
                'assignable_type' => $targetType,
                'assignable_id' => $targetId,
                'released_at' => null,
            ]);
        }
    }

    public function test_vehicle_with_valid_site_access_remains_visible(): void
    {
        $unrelatedSite = Site::factory()->create([]);
        $mismatchedClient = Client::factory()->create([
            'site_id' => $unrelatedSite->id,
            'status' => 'active',
        ]);
        $vehicle = Asset::factory()->create([
            'category' => 'Vehicle',
            'site_id' => null,
            'home_site_id' => null,
            'client_id' => $mismatchedClient->id,
        ]);
        $platformAdmin = User::factory()->create();
        $platformAdmin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
        $access = app(SecurityDevicesAccessService::class);

        $this->actingAs($this->admin)
            ->get("/security-devices/devices/{$this->allowedDevice->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('assignmentTargets.vehicles', fn ($targets): bool => collect($targets)->contains('id', $vehicle->id)));
        $this->assertTrue($access->canAccessAssignmentTarget(
            $platformAdmin,
            $this->allowedDevice,
            DeviceAssignment::TARGET_VEHICLE,
            $vehicle->id,
        ));
        $this->assertTrue($access->assignableVehicles($this->admin)->contains('id', $vehicle->id));

        $this->actingAs($this->admin)
            ->post("/security-devices/devices/{$this->allowedDevice->id}/assign", [
                'assignable_type' => DeviceAssignment::TARGET_VEHICLE,
                'assignable_id' => $vehicle->id,
            ])
            ->assertRedirect();
        $this->assertDatabaseHas('device_assignments', [
            'device_id' => $this->allowedDevice->id,
            'assignable_type' => DeviceAssignment::TARGET_VEHICLE,
            'assignable_id' => $vehicle->id,
            'released_at' => null,
        ]);
    }

    public function test_vehicle_assignment_requires_canonical_site_or_client_site_evidence(): void
    {
        $siteLessClient = Client::factory()->create(['site_id' => null, 'status' => 'active']);
        $siteClient = Client::factory()->create(['site_id' => $this->allowedSite->id, 'status' => 'active']);
        $vehicles = [
            Asset::factory()->create([
                'category' => 'Vehicle', 'site_id' => null, 'home_site_id' => null, 'client_id' => $siteLessClient->id,
            ]),
            Asset::factory()->create([
                'category' => 'Vehicle', 'site_id' => null, 'home_site_id' => null, 'client_id' => $siteClient->id,
            ]),
            Asset::factory()->create([
                'category' => 'Vehicle', 'site_id' => $this->allowedSite->id, 'home_site_id' => null, 'client_id' => null,
            ]),
            Asset::factory()->create([
                'category' => 'Vehicle', 'site_id' => null, 'home_site_id' => $this->allowedSite->id, 'client_id' => null,
            ]),
        ];
        $platformAdmin = User::factory()->create();
        $platformAdmin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
        $access = app(SecurityDevicesAccessService::class);
        $assignableIds = $access->assignableVehicles($this->admin)->pluck('id')->map(fn ($id): int => (int) $id)->all();

        foreach ($vehicles as $index => $vehicle) {
            if ($index === 0) {
                $this->assertFalse($access->canAccessAssignmentTarget(
                    $platformAdmin,
                    $this->allowedDevice,
                    DeviceAssignment::TARGET_VEHICLE,
                    $vehicle->id,
                ));
                $this->assertFalse($access->canAccessAssignmentTarget(
                    $this->admin,
                    $this->allowedDevice,
                    DeviceAssignment::TARGET_VEHICLE,
                    $vehicle->id,
                ));
                $this->assertNotContains($vehicle->id, $assignableIds);

                continue;
            }

            $this->assertTrue($access->canAccessAssignmentTarget(
                $platformAdmin,
                $this->allowedDevice,
                DeviceAssignment::TARGET_VEHICLE,
                $vehicle->id,
            ));
            $this->assertTrue($access->canAccessAssignmentTarget(
                $this->admin,
                $this->allowedDevice,
                DeviceAssignment::TARGET_VEHICLE,
                $vehicle->id,
            ));
            $this->assertContains($vehicle->id, $assignableIds);

            $this->actingAs($this->admin)
                ->post("/security-devices/devices/{$this->allowedDevice->id}/assign", [
                    'assignable_type' => DeviceAssignment::TARGET_VEHICLE,
                    'assignable_id' => $vehicle->id,
                ])
                ->assertRedirect();
            $this->assertDatabaseHas('device_assignments', [
                'device_id' => $this->allowedDevice->id,
                'assignable_type' => DeviceAssignment::TARGET_VEHICLE,
                'assignable_id' => $vehicle->id,
            ]);
        }
    }

    public function test_asset_and_topology_links_reject_targets_outside_site_scope(): void
    {
        $hiddenAsset = Asset::factory()->create(['site_id' => $this->hiddenSite->id]);

        $this->actingAs($this->manager)
            ->post("/security-devices/devices/{$this->allowedDevice->id}/asset-links", [
                'asset_id' => $hiddenAsset->id,
                'link_type' => 'primary',
            ])
            ->assertNotFound();
        $this->actingAs($this->manager)
            ->post("/security-devices/devices/{$this->allowedDevice->id}/relationships", [
                'other_device_id' => $this->hiddenDevice->id,
                'relationship_type' => 'connected_to',
                'direction' => 'downstream',
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('device_asset_links', [
            'device_id' => $this->allowedDevice->id,
            'asset_id' => $hiddenAsset->id,
        ]);
        $this->assertDatabaseMissing('device_relationships', [
            'parent_device_id' => $this->allowedDevice->id,
            'child_device_id' => $this->hiddenDevice->id,
        ]);
    }

    public function test_topology_relationship_lifecycle_retains_reasoned_history_and_audit_evidence(): void
    {
        $other = $this->deviceAt($this->allowedSite, 'Allowed downstream device');

        $this->actingAs($this->manager)
            ->post("/security-devices/devices/{$this->allowedDevice->id}/relationships", [
                'other_device_id' => $other->id,
                'relationship_type' => 'connected_to',
                'direction' => 'downstream',
                'port' => 'Port 8',
            ])
            ->assertRedirect();

        $relationship = DeviceRelationship::query()->sole();
        $this->assertSame($this->manager->id, $relationship->created_by_user_id);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'security_devices.relationship.created',
            'auditable_id' => $relationship->id,
        ]);

        $this->actingAs($this->manager)
            ->delete("/security-devices/devices/{$this->allowedDevice->id}/relationships/{$relationship->id}")
            ->assertSessionHasErrors('reason');
        $this->assertNull($relationship->fresh()->unlinked_at);

        $this->actingAs($this->manager)
            ->delete("/security-devices/devices/{$this->allowedDevice->id}/relationships/{$relationship->id}", [
                'reason' => 'Approved network path replacement.',
            ])
            ->assertRedirect();

        $relationship->refresh();
        $this->assertNotNull($relationship->unlinked_at);
        $this->assertSame($this->manager->id, $relationship->unlinked_by_user_id);
        $this->assertSame('Approved network path replacement.', $relationship->unlink_reason);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'security_devices.relationship.unlinked',
            'auditable_id' => $relationship->id,
        ]);
        $this->assertCount(0, $this->allowedDevice->fresh()->childRelationships);
        try {
            DB::table('device_relationships')
                ->where('id', $relationship->id)
                ->update(['unlink_reason' => 'Rewritten without lifecycle service']);
            $this->fail('Retained relationship evidence was rewritten through a bulk update.');
        } catch (QueryException) {
            $this->assertSame('Approved network path replacement.', $relationship->fresh()->unlink_reason);
        }
        try {
            DB::table('device_relationships')->where('id', $relationship->id)->delete();
            $this->fail('Retained relationship evidence was deleted through a bulk delete.');
        } catch (QueryException) {
            $this->assertDatabaseHas('device_relationships', ['id' => $relationship->id]);
        }
        $this->actingAs($this->manager)
            ->get("/security-devices/devices/{$this->allowedDevice->id}")
            ->assertInertia(fn ($page) => $page
                ->where('relationships.children', [])
                ->where('relationshipHistory.children.0.id', $relationship->id)
                ->where('relationshipHistory.children.0.device_id', $other->id)
                ->where('relationshipHistory.children.0.unlinked_by', $this->manager->name)
                ->where('relationshipHistory.children.0.unlink_reason', 'Approved network path replacement.'));

        $this->actingAs($this->manager)
            ->post("/security-devices/devices/{$this->allowedDevice->id}/relationships", [
                'other_device_id' => $other->id,
                'relationship_type' => 'connected_to',
                'direction' => 'downstream',
            ])
            ->assertRedirect();
        $this->assertSame(2, DeviceRelationship::query()->count());
        $this->assertSame(1, DeviceRelationship::query()->active()->count());

        $active = DeviceRelationship::query()->active()->sole();
        try {
            DB::table('device_relationships')
                ->where('id', $active->id)
                ->update(['port' => 'Unaudited bulk rewrite']);
            $this->fail('Active Device relationship evidence was rewritten through a bulk update.');
        } catch (QueryException) {
            $this->assertNull($active->fresh()->port);
        }
        try {
            $active->update(['port' => 'Unaudited rewrite']);
            $this->fail('Active Device relationship evidence was rewritten in place.');
        } catch (\UnexpectedValueException $exception) {
            $this->assertStringContainsString('remove and recreate', $exception->getMessage());
        }

        try {
            $relationship->delete();
            $this->fail('Retained Device relationship evidence was deleted.');
        } catch (\UnexpectedValueException $exception) {
            $this->assertStringContainsString('cannot be deleted', $exception->getMessage());
        }
    }

    public function test_topology_relationship_actor_evidence_survives_user_deletion_and_lossy_rollback_is_refused(): void
    {
        $actor = User::factory()->create();
        $other = $this->deviceAt($this->allowedSite, 'Actor evidence peer');
        $relationship = DeviceRelationship::query()->create([
            'parent_device_id' => $this->allowedDevice->id,
            'child_device_id' => $other->id,
            'relationship_type' => 'connected_to',
            'created_by_user_id' => $actor->id,
        ]);

        try {
            DB::table('device_relationships')->insert([
                'parent_device_id' => $other->id,
                'child_device_id' => $this->allowedDevice->id,
                'relationship_type' => 'powered_by',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('A Device relationship was inserted without creation actor evidence.');
        } catch (QueryException) {
            $this->assertDatabaseMissing('device_relationships', [
                'parent_device_id' => $other->id,
                'child_device_id' => $this->allowedDevice->id,
                'relationship_type' => 'powered_by',
            ]);
        }

        try {
            DB::table('users')->where('id', $actor->id)->delete();
            $this->fail('The relationship creation actor was deleted and its evidence was lost.');
        } catch (QueryException) {
            $this->assertDatabaseHas('users', ['id' => $actor->id]);
            $this->assertSame($actor->id, $relationship->fresh()->created_by_user_id);
        }

        $migration = require database_path('migrations/2026_08_06_000041_retain_device_relationship_history.php');
        try {
            $migration->down();
            $this->fail('The retention migration rolled back after attributed lifecycle activity.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('attributed activity', $exception->getMessage());
        }
    }

    public function test_existing_child_links_cannot_bypass_target_scope_when_removed(): void
    {
        $hiddenAsset = Asset::factory()->create(['site_id' => $this->hiddenSite->id]);
        $assetLink = DeviceAssetLink::query()->create([
            'device_id' => $this->allowedDevice->id,
            'asset_id' => $hiddenAsset->id,
            'link_type' => 'primary',
            'linked_at' => now(),
            'linked_by_user_id' => $this->admin->id,
        ]);
        $relationship = DeviceRelationship::query()->create([
            'parent_device_id' => $this->allowedDevice->id,
            'child_device_id' => $this->hiddenDevice->id,
            'relationship_type' => 'connected_to',
            'created_by_user_id' => $this->admin->id,
        ]);

        $this->actingAs($this->manager)
            ->delete("/security-devices/devices/{$this->allowedDevice->id}/asset-links/{$assetLink->id}")
            ->assertNotFound();
        $this->actingAs($this->manager)
            ->delete("/security-devices/devices/{$this->allowedDevice->id}/relationships/{$relationship->id}")
            ->assertNotFound();

        $this->assertNull($assetLink->fresh()->unlinked_at);
        $this->assertDatabaseHas('device_relationships', ['id' => $relationship->id]);

        // Remove the unrelated hidden Asset provenance as an authorised repair
        // before proving that retained topology history itself stays concealed.
        DB::table('device_asset_links')->where('id', $assetLink->id)->update([
            'unlinked_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('device_relationships')->where('id', $relationship->id)->update([
            'unlinked_at' => now(),
            'unlinked_by_user_id' => $this->admin->id,
            'unlink_reason' => 'Hidden Site topology correction.',
            'updated_at' => now(),
        ]);
        $this->actingAs($this->manager)
            ->get("/security-devices/devices/{$this->allowedDevice->id}")
            ->assertInertia(fn ($page) => $page
                ->where('relationships.parents', [])
                ->where('relationships.children', [])
                ->where('relationshipHistory.parents', [])
                ->where('relationshipHistory.children', []));
    }

    public function test_admin_can_manage_unassigned_stock_with_all_sites_access(): void
    {
        $unrelated = Device::factory()->create(['name' => 'Unrelated device']);

        $this->actingAs($this->admin)
            ->patch("/security-devices/devices/{$unrelated->id}/fields", ['asset_tag' => 'FORBIDDEN'])
            ->assertRedirect();
        $this->actingAs($this->admin)
            ->delete("/security-devices/devices/{$unrelated->id}")
            ->assertRedirect();

        $this->assertSame('FORBIDDEN', $unrelated->fresh()->asset_tag);
        $this->assertNotNull($unrelated->fresh()->deleted_at);
    }

    public function test_allowed_inline_service_date_update_round_trips(): void
    {
        $this->actingAs($this->manager)
            ->patch("/security-devices/devices/{$this->allowedDevice->id}/fields", [
                'next_service_due' => '2027-08-19',
            ])
            ->assertRedirect();

        $this->assertSame('2027-08-19', $this->allowedDevice->fresh()->next_service_due?->toDateString());
    }

    public function test_authorized_device_document_upload_download_and_delete_round_trip(): void
    {
        Storage::fake('private');

        $this->actingAs($this->manager)
            ->post("/security-devices/devices/{$this->allowedDevice->id}/documents", [
                'title' => 'Commissioning manual',
                'category' => 'manual',
                'file' => UploadedFile::fake()->createWithContent('commissioning.pdf', 'safe-pdf-content'),
            ])
            ->assertRedirect();

        $document = DeviceDocument::query()->where('device_id', $this->allowedDevice->id)->firstOrFail();
        Storage::disk('private')->assertExists($document->storage_path);
        $this->assertSame('private', $document->storage_disk);
        $this->assertSame(64, strlen((string) $document->content_sha256));

        $this->actingAs($this->manager)
            ->get("/security-devices/devices/{$this->allowedDevice->id}/documents/{$document->id}")
            ->assertOk()
            ->assertDownload('commissioning.pdf');

        $this->actingAs($this->manager)
            ->delete("/security-devices/devices/{$this->allowedDevice->id}/documents/{$document->id}")
            ->assertSessionHasErrors('reason');
        $this->assertNull($document->fresh()->removed_at);
        Storage::disk('private')->assertExists($document->storage_path);

        $this->actingAs($this->manager)
            ->delete("/security-devices/devices/{$this->allowedDevice->id}/documents/{$document->id}", [
                'reason' => 'Superseded by the verified current commissioning manual.',
            ])
            ->assertRedirect();

        $removed = $document->fresh();
        $this->assertNotNull($removed->removed_at);
        $this->assertNotNull($removed->storage_deleted_at);
        $this->assertSame($this->manager->id, $removed->removed_by_user_id);
        $this->assertSame('Superseded by the verified current commissioning manual.', $removed->removal_reason);
        Storage::disk('private')->assertMissing($document->storage_path);
        $this->actingAs($this->manager)
            ->get("/security-devices/devices/{$this->allowedDevice->id}/documents/{$document->id}")
            ->assertNotFound();
        $this->assertSame(0, $this->allowedDevice->fresh()->documents()->count());
        $this->actingAs($this->manager)
            ->get("/security-devices/devices/{$this->allowedDevice->id}")
            ->assertInertia(fn ($page) => $page
                ->where('documents', [])
                ->where('documentHistory.0.id', $document->id)
                ->where('documentHistory.0.status_label', 'Removed')
                ->where('documentHistory.0.removed_by', $this->manager->name)
                ->where('documentHistory.0.removal_reason', 'Superseded by the verified current commissioning manual.')
                ->where('documentHistory.0.integrity_sha256', $removed->content_sha256));

        $uploadAudit = AuditLog::query()
            ->where('action', 'security_devices.device_document.uploaded')
            ->where('auditable_id', $document->id)
            ->sole();
        $removeAudit = AuditLog::query()
            ->where('action', 'security_devices.device_document.removed')
            ->where('auditable_id', $document->id)
            ->sole();
        $this->assertSame('active', $uploadAudit->meta['after']['status']);
        $this->assertSame('removed', $removeAudit->meta['after']['status']);
        $this->assertSame($this->allowedDevice->id, $removeAudit->meta['scope']['device_id']);
        $this->assertArrayNotHasKey('storage_path', $uploadAudit->meta);
        $this->assertArrayNotHasKey('storage_path', $removeAudit->meta);

        $this->expectException(\UnexpectedValueException::class);
        $removed->delete();
    }

    public function test_device_document_integrity_and_removal_evidence_cannot_be_rewritten(): void
    {
        $document = DeviceDocument::query()->create([
            'device_id' => $this->allowedDevice->id,
            'uploaded_by_user_id' => $this->manager->id,
            'title' => 'Immutable certificate',
            'category' => 'compliance_cert',
            'storage_disk' => 'private',
            'storage_path' => 'device_documents/immutable.pdf',
            'original_name' => 'immutable.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 7,
            'content_sha256' => hash('sha256', 'content'),
            'lifecycle_state' => DeviceDocument::STATE_ACTIVE,
            'storage_verified_at' => now(),
        ]);

        $this->expectException(\UnexpectedValueException::class);
        $document->forceFill(['content_sha256' => str_repeat('0', 64)])->save();
    }

    public function test_download_denies_tampered_private_bytes_and_moves_document_to_recovery_history(): void
    {
        Storage::fake('private');
        $content = 'verified-content';
        $path = 'device_documents/tamper.pdf';
        Storage::disk('private')->put($path, $content);
        $document = DeviceDocument::query()->create([
            'device_id' => $this->allowedDevice->id,
            'uploaded_by_user_id' => $this->manager->id,
            'title' => 'Tamper protected certificate',
            'category' => 'compliance_cert',
            'storage_disk' => 'private',
            'storage_path' => $path,
            'original_name' => 'tamper.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($content),
            'content_sha256' => hash('sha256', $content),
            'lifecycle_state' => DeviceDocument::STATE_ACTIVE,
            'storage_verified_at' => now(),
        ]);
        Storage::disk('private')->put($path, 'tampered-content');

        $this->actingAs($this->manager)
            ->get("/security-devices/devices/{$this->allowedDevice->id}/documents/{$document->id}")
            ->assertStatus(409);

        $this->assertSame('integrity_mismatch', $document->fresh()->lifecycle_error_code);
        $this->assertSame(0, $this->allowedDevice->fresh()->documents()->count());
        $this->assertSame(1, $this->allowedDevice->fresh()->documentHistory()->count());
    }

    public function test_device_document_upload_compensates_private_blob_when_metadata_creation_fails(): void
    {
        Storage::fake('private');
        $failOnce = true;
        DeviceDocument::creating(function () use (&$failOnce): void {
            if ($failOnce) {
                $failOnce = false;
                throw new \RuntimeException('Simulated metadata failure.');
            }
        });

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($this->manager)
                ->post("/security-devices/devices/{$this->allowedDevice->id}/documents", [
                    'title' => 'Uncommitted manual',
                    'category' => 'manual',
                    'file' => UploadedFile::fake()->createWithContent('uncommitted.pdf', 'temporary-content'),
                ]);
            $this->fail('The simulated metadata failure was not raised.');
        } catch (\RuntimeException $failure) {
            $this->assertSame('Simulated metadata failure.', $failure->getMessage());
        }

        $this->assertDatabaseMissing('device_documents', [
            'device_id' => $this->allowedDevice->id,
            'title' => 'Uncommitted manual',
        ]);
        $this->assertSame([], Storage::disk('private')->allFiles("device_documents/{$this->allowedDevice->id}"));
    }

    public function test_staged_upload_recovers_after_activation_audit_transaction_fails(): void
    {
        Storage::fake('private');
        $failOnce = true;
        AuditLog::creating(function (AuditLog $audit) use (&$failOnce): void {
            if ($failOnce && $audit->action === 'security_devices.device_document.uploaded') {
                $failOnce = false;
                throw new \RuntimeException('Simulated activation audit failure.');
            }
        });

        $this->actingAs($this->manager)
            ->post("/security-devices/devices/{$this->allowedDevice->id}/documents", [
                'title' => 'Recoverable upload',
                'category' => 'manual',
                'file' => UploadedFile::fake()->createWithContent('recoverable.pdf', 'recoverable-content'),
            ])
            ->assertRedirect()
            ->assertSessionHas('warning');

        $document = DeviceDocument::query()->where('title', 'Recoverable upload')->sole();
        $this->assertSame(DeviceDocument::STATE_UPLOAD_STAGED, $document->lifecycle_state);
        $this->assertNotNull($document->upload_operation_uuid);
        $this->assertNotNull($document->staged_storage_path);
        $this->assertTrue(
            Storage::disk('private')->exists($document->storage_path)
                || Storage::disk('private')->exists($document->staged_storage_path),
        );

        $this->assertTrue(app(DeviceDocumentLifecycleService::class)->reconcileDocument($document->id));
        $this->assertTrue($document->fresh()->isDownloadable());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'security_devices.device_document.uploaded',
            'auditable_id' => $document->id,
        ]);
    }

    public function test_device_document_pending_removal_survives_interruption_and_reconciles_from_quarantine_intent(): void
    {
        Storage::fake('private');
        $content = 'retained-private-content';
        $path = 'device_documents/retained.pdf';
        Storage::disk('private')->put($path, $content);
        $document = DeviceDocument::query()->create([
            'device_id' => $this->allowedDevice->id,
            'uploaded_by_user_id' => $this->manager->id,
            'title' => 'Retained certificate',
            'category' => 'compliance_cert',
            'storage_disk' => 'private',
            'storage_path' => $path,
            'original_name' => 'retained.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($content),
            'content_sha256' => hash('sha256', $content),
            'lifecycle_state' => DeviceDocument::STATE_ACTIVE,
            'storage_verified_at' => now(),
        ]);
        $document->requestGovernedRemoval(
            operationUuid: (string) Str::orderedUuid(),
            actorId: $this->manager->id,
            reason: 'Superseded by a verified replacement certificate.',
            quarantinePath: 'device_documents/.quarantine/interrupted-removal',
            requestedAt: now(),
        );

        $this->assertSame(DeviceDocument::STATE_REMOVAL_PENDING, $document->fresh()->lifecycle_state);
        Storage::disk('private')->assertExists($path);

        $this->assertTrue(app(DeviceDocumentLifecycleService::class)->reconcileDocument($document->id));
        $this->assertSame(DeviceDocument::STATE_REMOVED, $document->fresh()->lifecycle_state);
        $this->assertNotNull($document->fresh()->storage_deleted_at);
        Storage::disk('private')->assertMissing($path);
    }

    public function test_device_document_removal_recovers_after_final_audit_transaction_fails(): void
    {
        Storage::fake('private');
        $content = 'rollback-private-content';
        $path = 'device_documents/rollback.pdf';
        Storage::disk('private')->put($path, $content);
        $document = DeviceDocument::query()->create([
            'device_id' => $this->allowedDevice->id,
            'uploaded_by_user_id' => $this->manager->id,
            'title' => 'Rollback certificate',
            'category' => 'compliance_cert',
            'storage_disk' => 'private',
            'storage_path' => $path,
            'original_name' => 'rollback.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($content),
            'content_sha256' => hash('sha256', $content),
            'lifecycle_state' => DeviceDocument::STATE_ACTIVE,
            'storage_verified_at' => now(),
        ]);
        $failOnce = true;
        AuditLog::creating(function (AuditLog $audit) use (&$failOnce): void {
            if ($failOnce && $audit->action === 'security_devices.device_document.removed') {
                $failOnce = false;
                throw new \RuntimeException('Simulated audit failure.');
            }
        });

        $this->actingAs($this->manager)
            ->delete("/security-devices/devices/{$this->allowedDevice->id}/documents/{$document->id}", [
                'reason' => 'Superseded by a verified replacement certificate.',
            ])
            ->assertRedirect()
            ->assertSessionHas('warning');

        $pending = $document->fresh();
        $this->assertSame(DeviceDocument::STATE_REMOVAL_PENDING, $pending->lifecycle_state);
        $this->assertNotNull($pending->quarantine_storage_path);
        Storage::disk('private')->assertExists($pending->quarantine_storage_path);

        $this->assertTrue(app(DeviceDocumentLifecycleService::class)->reconcileDocument($document->id));
        $this->assertSame(DeviceDocument::STATE_REMOVED, $document->fresh()->lifecycle_state);
        $this->assertNotNull($document->fresh()->storage_deleted_at);
    }

    public function test_document_reconciler_does_not_let_completed_history_starve_pending_storage_work(): void
    {
        Storage::fake('private');
        DB::table('device_documents')->insert([
            'device_id' => $this->allowedDevice->id,
            'uploaded_by_user_id' => $this->manager->id,
            'title' => 'Completed removal history',
            'category' => 'manual',
            'storage_disk' => DeviceDocument::DISK,
            'storage_path' => 'device_documents/already-removed.pdf',
            'original_name' => 'already-removed.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 7,
            'content_sha256' => hash('sha256', 'removed'),
            'lifecycle_state' => DeviceDocument::STATE_REMOVED,
            'removed_at' => now()->subMinute(),
            'removed_by_user_id' => $this->manager->id,
            'removal_reason' => 'Superseded by verified current evidence.',
            'storage_deleted_at' => now()->subMinute(),
            'created_at' => now()->subHour(),
            'updated_at' => now()->subMinute(),
        ]);

        $content = 'recoverable-stage';
        $operationUuid = (string) Str::orderedUuid();
        $stagedPath = "device_documents/.staging/{$operationUuid}.pdf";
        $finalPath = "device_documents/{$this->allowedDevice->id}/{$operationUuid}.pdf";
        Storage::disk('private')->put($stagedPath, $content);
        $pending = DeviceDocument::query()->create([
            'device_id' => $this->allowedDevice->id,
            'uploaded_by_user_id' => $this->manager->id,
            'title' => 'Pending staged evidence',
            'category' => 'manual',
            'storage_disk' => DeviceDocument::DISK,
            'storage_path' => $finalPath,
            'original_name' => 'pending.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($content),
            'content_sha256' => hash('sha256', $content),
            'lifecycle_state' => DeviceDocument::STATE_UPLOAD_STAGED,
            'upload_operation_uuid' => $operationUuid,
            'upload_requested_by_user_id' => $this->manager->id,
            'staged_storage_path' => $stagedPath,
        ]);

        $result = app(DeviceDocumentLifecycleService::class)->reconcileAll(1);

        $this->assertSame(1, $result['processed']);
        $this->assertSame(1, $result['recovered']);
        $this->assertSame(0, $result['pending']);
        $this->assertTrue($pending->fresh()->isDownloadable());
    }

    private function deviceAt(Site $site, string $name): Device
    {
        $device = Device::factory()->create([
            'name' => $name,
            'domain' => 'it_infrastructure',
            'category' => 'networking',
        ]);
        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assigned_at' => now(),
        ]);

        return $device;
    }
}
