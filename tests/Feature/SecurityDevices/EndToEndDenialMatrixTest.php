<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Models\DeviceMaintenanceRecord;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class EndToEndDenialMatrixTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_site_ownership_privacy_sensitive_domain_and_command_denial_is_consistent_across_every_access_channel(): void
    {
        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);
        config()->set('monitoring.signing.active_key_id', 'e09-denial-matrix');
        config()->set('monitoring.signing.keys', [
            'e09-denial-matrix' => base64_encode(str_repeat('E', SODIUM_CRYPTO_AUTH_KEYBYTES)),
        ]);

        $noRole = User::factory()->create(['approved_at' => now()]);
        $this->actingAs($noRole)->get('/security-devices/devices')->assertForbidden();

        $allowedSite = Site::factory()->create(['name' => 'E09 Allowed Site']);
        $hiddenSite = Site::factory()->create(['name' => 'E09 Hidden Site']);
        $actor = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $actor->id,
            'primary_site_id' => $allowedSite->id,
            'secondary_site_ids' => [],
            'is_active' => true,
        ]);
        $this->grant($actor, [
            'securityDevices.viewAny',
            'securityDevices.devices.view',
            'securityDevices.devices.update',
            'securityDevices.devices.assign',
            'securityDevices.events.view',
            'securityDevices.maintenance.view',
            'securityDevices.maintenance.manage',
            'securityDevices.reports.view',
            'securityDevices.commands.observe',
            'securityDevices.commands.control',
        ]);

        $visible = $this->assignedDevice($allowedSite, [
            'name' => 'E09 Visible Camera',
            'domain' => 'security',
            'category' => 'cctv',
            'subcategory' => 'dome_camera',
            'provider' => 'contract-test',
            'last_seen_at' => now(),
            'config' => ['management' => ['capabilities' => ['camera.privacy.enable']]],
        ]);
        $outsideSite = $this->assignedDevice($hiddenSite, ['name' => 'E09 Hidden Site Device']);
        $client = Client::factory()->create(['site_id' => $allowedSite->id]);
        $privateClient = $this->assignedDevice($client, ['name' => 'E09 Private Client Device']);
        $privateStaffUser = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $privateStaffUser->id,
            'primary_site_id' => $allowedSite->id,
            'secondary_site_ids' => [],
            'is_active' => true,
        ]);
        $privateStaff = $this->assignedDevice($privateStaffUser, ['name' => 'E09 Private Staff Device']);
        $privateVehicleAsset = Asset::factory()->vehicle()->create(['site_id' => $allowedSite->id]);
        $privateVehicle = $this->assignedDevice($privateVehicleAsset, ['name' => 'E09 Private Fleet Device']);
        $concealed = [$outsideSite, $privateClient, $privateStaff, $privateVehicle];

        foreach ([$visible, ...$concealed] as $index => $device) {
            DeviceEvent::query()->create([
                'device_id' => $device->id,
                'event_type' => "e09-event-{$index}",
                'severity' => 'warning',
                'source' => 'e09-matrix',
                'occurred_at' => now(),
            ]);
            DeviceMaintenanceRecord::query()->create([
                'device_id' => $device->id,
                'type' => 'preventive',
                'status' => 'scheduled',
                'description' => "E09 maintenance {$device->name}",
                'scheduled_for' => now()->addDay(),
            ]);
        }

        $this->assertTrue(Gate::forUser($actor)->allows('view', $visible));
        foreach ($concealed as $device) {
            $this->assertFalse(Gate::forUser($actor)->allows('view', $device));
            $this->assertFalse(Gate::forUser($actor)->allows('update', $device));
        }

        $this->actingAs($actor)
            ->get('/security-devices/devices')
            ->assertOk()
            ->assertInertia(function ($page) use ($visible, $concealed): void {
                $props = $page->toArray()['props'];
                $payload = json_encode($props, JSON_THROW_ON_ERROR);

                $this->assertSame(1, $props['devices']['meta']['total']);
                $this->assertSame(1, $props['stats']['total']);
                $this->assertSame($visible->id, $props['devices']['data'][0]['id']);
                foreach ($concealed as $device) {
                    $this->assertStringNotContainsString($device->name, $payload);
                }
            });

        foreach ($concealed as $device) {
            $this->actingAs($actor)
                ->get('/security-devices/devices?search='.urlencode($device->name))
                ->assertOk()
                ->assertInertia(fn ($page) => $page->where('devices.meta.total', 0));
            $this->actingAs($actor)
                ->get("/security-devices/devices/{$device->id}")
                ->assertNotFound();
            $this->actingAs($actor)
                ->getJson("/security-devices/devices/{$device->id}/assignments")
                ->assertNotFound();
        }

        $this->actingAs($actor)
            ->get('/security-devices/reports')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('stats.devices', 1)
                ->where('stats.events_90d', 1)
                ->where('stats.maintenance', 1));
        foreach ([
            '/security-devices/reports/devices.csv',
            '/security-devices/reports/events.csv',
            '/security-devices/reports/maintenance.csv',
        ] as $exportUrl) {
            $response = $this->actingAs($actor)->get($exportUrl)->assertOk();
            $csv = $response->streamedContent();
            $this->assertStringContainsString($visible->name, $csv);
            foreach ($concealed as $device) {
                $this->assertStringNotContainsString($device->name, $csv);
            }
        }

        $this->actingAs($actor)
            ->patch("/security-devices/devices/{$outsideSite->id}/fields", ['asset_tag' => 'E09-FORBIDDEN'])
            ->assertNotFound();
        $this->actingAs($actor)
            ->post("/security-devices/devices/{$outsideSite->id}/maintenance", [
                'type' => 'preventive',
                'description' => 'E09 forbidden mutation',
            ])
            ->assertNotFound();
        $this->actingAs($actor)
            ->post("/security-devices/devices/{$visible->id}/relationships", [
                'other_device_id' => $outsideSite->id,
                'relationship_type' => 'connected_to',
                'direction' => 'downstream',
            ])
            ->assertNotFound();
        $this->actingAs($actor)
            ->post("/security-devices/devices/{$visible->id}/asset-links", [
                'asset_id' => $privateVehicleAsset->id,
                'link_type' => 'installed_in',
            ])
            ->assertNotFound();

        $this->assertNotSame('E09-FORBIDDEN', $outsideSite->fresh()->asset_tag);
        $this->assertDatabaseMissing('device_maintenance_records', [
            'device_id' => $outsideSite->id,
            'description' => 'E09 forbidden mutation',
        ]);
        $this->assertDatabaseMissing('device_relationships', [
            'parent_device_id' => $visible->id,
            'child_device_id' => $outsideSite->id,
        ]);
        $this->assertDatabaseMissing('device_asset_links', [
            'device_id' => $visible->id,
            'asset_id' => $privateVehicleAsset->id,
        ]);

        $this->actingAs($actor)
            ->get("/security-devices/devices/{$visible->id}?section=management")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('profile.management.actions', [])
                ->where('profile.management.history', []));
        $this->actingAs($actor)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post("/security-devices/devices/{$visible->id}/commands", [
                'capability' => 'camera.privacy.enable',
                'parameters' => [],
                'reason' => 'Attempt a private camera action without the sensitive-domain permission.',
                'idempotency_key' => 'e09-sensitive-command-'.$visible->id,
                'impact_acknowledged' => true,
            ])
            ->assertNotFound();
        $this->assertSame(0, DeviceCommandRequest::query()->count());
    }

    /** @param list<string> $permissions */
    private function grant(User $user, array $permissions): void
    {
        $ids = Permission::query()->whereIn('key', $permissions)->pluck('id');
        $this->assertCount(count($permissions), $ids);
        $user->permissionOverrides()->sync(
            $ids->mapWithKeys(fn (int $id): array => [$id => ['allowed' => true]])->all(),
        );
    }

    private function assignedDevice(Site|Client|User|Asset $target, array $attributes = []): Device
    {
        $device = Device::factory()->create($attributes);
        $targetType = match (true) {
            $target instanceof Site => DeviceAssignment::TARGET_SITE,
            $target instanceof Client => DeviceAssignment::TARGET_CLIENT,
            $target instanceof User => DeviceAssignment::TARGET_STAFF,
            $target instanceof Asset => DeviceAssignment::TARGET_VEHICLE,
        };
        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => $targetType,
            'assignable_id' => $target->id,
            'assigned_at' => now(),
        ]);

        return $device;
    }
}
