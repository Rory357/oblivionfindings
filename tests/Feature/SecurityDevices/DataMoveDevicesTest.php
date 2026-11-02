<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Discovery\Models\DiscoveryCandidate;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;

/*
 * S&D data move "Devices: kind, room and Site" (security-devices:moves:devices). A dry run changes
 * nothing; --apply sets only an empty subcategory from clear evidence and places a device in the one
 * room its name names, through the existing placement code, audited, and a second run changes nothing.
 */

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    $this->seed(SecurityDevicesPermissionsSeeder::class);
    $this->site = Site::factory()->create(['name' => 'Synthetic House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);

    $this->actor = User::factory()->create(['name' => 'Synthetic Mover', 'approved_at' => now()]);
    HrEmployeeProfile::factory()->create(['user_id' => $this->actor->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [], 'is_active' => true]);
    foreach (['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.devices.update', 'securityDevices.devices.assign'] as $key) {
        $this->actor->permissionOverrides()->attach(Permission::query()->where('key', $key)->firstOrFail()->id, ['allowed' => true]);
    }

    foreach (['Kitchen', 'Lounge', 'Bedroom 1', 'Bedroom 10'] as $order => $name) {
        SiteRoom::query()->create(['site_id' => $this->site->id, 'name' => $name, 'sort_order' => $order]);
    }

    $this->atSite = function (array $attributes): Device {
        $device = Device::factory()->create(['provider' => 'manual', ...$attributes]);
        DeviceAssignment::query()->create([
            'device_id' => $device->id, 'assignable_type' => DeviceAssignment::TARGET_SITE, 'assignable_id' => $this->site->id,
            'assignment_type' => 'permanent', 'assigned_at' => now()->subDays(3),
        ]);

        return $device;
    };

    $this->kitchenCamera = ($this->atSite)(['name' => 'Kitchen camera', 'domain' => 'security', 'category' => 'cctv', 'subcategory' => 'dome_camera']);
    $this->bedroomSensor = ($this->atSite)(['name' => 'Bedroom 10 sensor', 'domain' => 'iot_healthcare', 'category' => 'environmental', 'subcategory' => 'temperature']);
    $this->loungeAp = ($this->atSite)(['name' => 'Lounge access point', 'domain' => 'it_infrastructure', 'category' => 'network', 'subcategory' => 'wireless_ap', 'provider' => 'unifi', 'manufacturer' => 'Ubiquiti', 'model' => 'U6-Lite']);
    $this->twoRooms = ($this->atSite)(['name' => 'Lounge to Kitchen camera', 'domain' => 'security', 'category' => 'cctv', 'subcategory' => 'dome_camera']);
    // Kind "Other" with an empty type and a confident discovery fingerprint: a leak sensor.
    $this->leak = ($this->atSite)(['name' => 'Comms cupboard detector', 'domain' => 'it_infrastructure', 'category' => 'it_environmental', 'subcategory' => null]);
    $snapshot = ['addresses' => ['10.44.0.21'], 'identification' => [
        'domain' => 'it_infrastructure', 'category' => 'it_environmental', 'subcategory' => 'water_leak_sensor',
        'type_label' => 'Water leak sensor', 'reason' => 'Synthetic fingerprint', 'confidence' => 92,
    ]];
    DiscoveryCandidate::factory()->create([
        'canonical_device_id' => $this->leak->id, 'decision' => 'matched', 'confidence' => 95,
        'evidence_snapshot' => $snapshot, 'evidence_hash' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
    ]);
    // Kind "Other" that already has a type: never overwritten.
    $this->nas = ($this->atSite)(['name' => 'Office storage', 'domain' => 'it_infrastructure', 'category' => 'storage', 'subcategory' => 'nas']);
    // Filed as a sensor by the old UniFi mapping, but UniFi's own model code says gateway: listed, not changed.
    $this->gateway = ($this->atSite)(['name' => 'Synthetic gateway', 'domain' => 'facilities', 'category' => 'building_safety', 'subcategory' => null, 'provider' => 'unifi', 'manufacturer' => 'Ubiquiti', 'model' => 'UX7', 'meta' => ['provider_type' => 'UX7']]);
    // No Site at all: listed, never guessed.
    $this->spare = Device::factory()->create(['name' => 'Spare switch', 'domain' => 'it_infrastructure', 'category' => 'network', 'subcategory' => 'switch', 'provider' => 'manual']);

    $this->roomOf = fn (Device $device): ?int => ($active = DeviceAssignment::query()->where('device_id', $device->id)->whereNull('released_at')->first())
        && $active->assignable_type === DeviceAssignment::TARGET_ROOM ? (int) $active->assignable_id : null;
    $this->room = fn (string $name): int => (int) SiteRoom::query()->where('site_id', $this->site->id)->where('name', $name)->value('id');
    $this->moveAudits = fn (): int => AuditLog::query()->where('action', 'like', 'security_devices.data_move.devices.%')->count();
});

it('lists what it would do in a dry run and changes nothing', function (): void {
    $assignments = DeviceAssignment::query()->count();

    $this->artisan('security-devices:moves:devices')
        ->expectsOutputToContain('Place in Kitchen')
        ->expectsOutputToContain('Place in Bedroom 10')
        ->expectsOutputToContain('Place in Lounge')
        ->expectsOutputToContain('Its name names more than one room')
        ->expectsOutputToContain('Set type to water_leak_sensor')
        ->expectsOutputToContain('Already has a type (nas)')
        ->expectsOutputToContain('UniFi model says Router')
        ->expectsOutputToContain('Spare switch')
        ->expectsOutputToContain('Dry run: nothing changed')
        ->assertSuccessful();

    expect(DeviceAssignment::query()->count())->toBe($assignments)
        ->and(($this->roomOf)($this->kitchenCamera))->toBeNull()
        ->and($this->leak->fresh()->subcategory)->toBeNull()
        ->and(($this->moveAudits)())->toBe(0);
});

it('sets only the suggested types and rooms, audited, and a second run changes nothing', function (): void {
    $this->artisan('security-devices:moves:devices', ['--apply' => true, '--actor' => $this->actor->id])
        ->expectsOutputToContain('Done: set 1 types and placed 3 devices in rooms')
        ->assertSuccessful();

    expect(($this->roomOf)($this->kitchenCamera))->toBe(($this->room)('Kitchen'))
        ->and(($this->roomOf)($this->bedroomSensor))->toBe(($this->room)('Bedroom 10'))
        ->and(($this->roomOf)($this->loungeAp))->toBe(($this->room)('Lounge'))
        ->and(($this->roomOf)($this->twoRooms))->toBeNull()
        ->and($this->leak->fresh()->subcategory)->toBe('water_leak_sensor')
        ->and($this->nas->fresh()->subcategory)->toBe('nas')
        ->and($this->gateway->fresh()->subcategory)->toBeNull()
        ->and($this->gateway->fresh()->category)->toBe('building_safety')
        ->and(DeviceAssignment::query()->where('device_id', $this->spare->id)->exists())->toBeFalse();

    // The Site placement it replaced is kept as history.
    expect(DeviceAssignment::query()->where('device_id', $this->kitchenCamera->id)
        ->where('assignable_type', DeviceAssignment::TARGET_SITE)->whereNotNull('released_at')->exists())->toBeTrue();
    expect(AuditLog::query()->where('action', 'security_devices.data_move.devices.room_placed')->count())->toBe(3)
        ->and(AuditLog::query()->where('action', 'security_devices.data_move.devices.type_set')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'security_devices.data_move.devices.type_set')->value('user_id'))->toBe($this->actor->id);

    $assignments = DeviceAssignment::query()->count();
    $audits = ($this->moveAudits)();
    $this->artisan('security-devices:moves:devices', ['--apply' => true, '--actor' => $this->actor->id])
        ->expectsOutputToContain('Done: set 0 types and placed 0 devices in rooms')
        ->assertSuccessful();
    expect(DeviceAssignment::query()->count())->toBe($assignments)
        ->and(($this->moveAudits)())->toBe($audits);
});

it('refuses --apply without an actor, and in production without --force', function (): void {
    $this->artisan('security-devices:moves:devices', ['--apply' => true])->assertFailed();

    $this->app['env'] = 'production';
    $this->artisan('security-devices:moves:devices', ['--apply' => true, '--actor' => $this->actor->id])->assertFailed();

    expect(($this->roomOf)($this->kitchenCamera))->toBeNull()
        ->and($this->leak->fresh()->subcategory)->toBeNull()
        ->and(($this->moveAudits)())->toBe(0);
});
