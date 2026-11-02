<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceGroup;
use App\Domain\SecurityDevices\Models\DeviceGroupMember;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;

/*
 * S&D data move "Device groups → rooms and filters" (D8, security-devices:moves:device-groups).
 * Each group is already a filter on Devices; the move only places members with no room into the
 * room the group's name names at their Site. The group tables are never changed or dropped.
 */

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    $this->seed(SecurityDevicesPermissionsSeeder::class);
    $this->site = Site::factory()->create(['name' => 'Synthetic House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);

    $this->actor = User::factory()->create(['name' => 'Synthetic Mover', 'approved_at' => now()]);
    HrEmployeeProfile::factory()->create(['user_id' => $this->actor->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [], 'is_active' => true]);
    foreach (['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.devices.assign'] as $key) {
        $this->actor->permissionOverrides()->attach(Permission::query()->where('key', $key)->firstOrFail()->id, ['allowed' => true]);
    }

    $this->kitchen = SiteRoom::query()->create(['site_id' => $this->site->id, 'name' => 'Kitchen', 'sort_order' => 1]);
    $this->lounge = SiteRoom::query()->create(['site_id' => $this->site->id, 'name' => 'Lounge', 'sort_order' => 2]);

    $this->atSite = function (string $name): Device {
        $device = Device::factory()->security()->create(['name' => $name, 'provider' => 'manual']);
        DeviceAssignment::query()->create([
            'device_id' => $device->id, 'assignable_type' => DeviceAssignment::TARGET_SITE, 'assignable_id' => $this->site->id,
            'assignment_type' => 'permanent', 'assigned_at' => now()->subDays(3),
        ]);

        return $device;
    };
    $this->group = function (string $name, array $devices): DeviceGroup {
        $group = DeviceGroup::query()->create(['name' => $name, 'type' => 'location']);
        $group->devices()->attach(array_map(fn (Device $device): int => $device->id, $devices));

        return $group;
    };

    $this->first = ($this->atSite)('Synthetic camera A');
    $this->second = ($this->atSite)('Synthetic camera B');
    $this->both = ($this->atSite)('Synthetic camera C');
    $this->alreadyInLounge = ($this->atSite)('Synthetic camera D');
    DeviceAssignment::query()->where('device_id', $this->alreadyInLounge->id)->update(['assignable_type' => DeviceAssignment::TARGET_ROOM, 'assignable_id' => $this->lounge->id]);

    $this->kitchenGroup = ($this->group)('Kitchen cameras', [$this->first, $this->second, $this->both, $this->alreadyInLounge]);
    ($this->group)('Lounge', [$this->both]);
    ($this->group)('Night checks', [$this->first]);

    $this->roomOf = fn (Device $device): ?int => ($active = DeviceAssignment::query()->where('device_id', $device->id)->whereNull('released_at')->first())
        && $active->assignable_type === DeviceAssignment::TARGET_ROOM ? (int) $active->assignable_id : null;
    $this->groupTables = fn (): array => [DeviceGroup::withTrashed()->count(), DeviceGroupMember::query()->count()];
});

it('shows each group’s filter and the members it would place, and changes nothing', function (): void {
    $tables = ($this->groupTables)();

    // The filter and the room hint share one table row, and expectsOutputToContain lets one written
    // line satisfy only one expectation, so read the whole output instead.
    $exit = Artisan::call('security-devices:moves:device-groups');
    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain(
            '/security-devices/devices?group='.$this->kitchenGroup->id,
            'Kitchen: 3 without a room',
            'A future drop, after Stephan’s yes, would remove 3 groups',
            'Dry run: nothing changed',
        );

    expect(($this->roomOf)($this->first))->toBeNull()
        ->and(($this->groupTables)())->toBe($tables)
        ->and(AuditLog::query()->where('action', 'like', 'security_devices.data_move.device_groups.%')->exists())->toBeFalse();
});

it('places only members the group name points to, never touches the group tables, and is idempotent', function (): void {
    $tables = ($this->groupTables)();

    $this->artisan('security-devices:moves:device-groups', ['--apply' => true, '--actor' => $this->actor->id])
        ->expectsOutputToContain('Done: placed 2 members in rooms')
        ->assertSuccessful();

    expect(($this->roomOf)($this->first))->toBe($this->kitchen->id)
        ->and(($this->roomOf)($this->second))->toBe($this->kitchen->id)
        // Two groups point it to different rooms: left alone.
        ->and(($this->roomOf)($this->both))->toBeNull()
        // Already in a room: never moved.
        ->and(($this->roomOf)($this->alreadyInLounge))->toBe($this->lounge->id)
        ->and(($this->groupTables)())->toBe($tables)
        ->and(AuditLog::query()->where('action', 'security_devices.data_move.device_groups.room_placed')->count())->toBe(2);

    $assignments = DeviceAssignment::query()->count();
    $this->artisan('security-devices:moves:device-groups', ['--apply' => true, '--actor' => $this->actor->id])
        ->expectsOutputToContain('Done: placed 0 members in rooms')
        ->assertSuccessful();
    expect(DeviceAssignment::query()->count())->toBe($assignments)
        ->and(AuditLog::query()->where('action', 'security_devices.data_move.device_groups.room_placed')->count())->toBe(2);
});
