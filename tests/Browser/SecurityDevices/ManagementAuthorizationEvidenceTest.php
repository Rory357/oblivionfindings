<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;

/** @return array{user: User, device: Device} */
function managementAuthorizationEvidenceFixture(string $suffix): array
{
    $run = Str::lower((string) Str::uuid());
    $site = Site::factory()->create([
        'name' => 'M01 '.Str::headline($suffix).' Authorised Site',
        'is_active' => true,
        'archived' => false,
    ]);
    $user = User::factory()->create([
        'name' => 'M01 '.Str::headline($suffix).' Security Operator',
        'email' => "m01.operator.{$suffix}.{$run}@example.test",
        'approved_at' => now(),
    ]);
    $role = Role::query()->firstOrCreate(
        ['name' => 'm01_management_browser_evidence'],
        [
            'label' => 'M01 management browser evidence',
            'level' => 50,
            'type' => 'custom',
        ],
    );
    $user->roles()->attach($role);
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'is_active' => true,
    ]);
    $permissionKeys = [
        'securityDevices.viewAny',
        'securityDevices.devices.view',
        'securityDevices.commands.observe',
        'securityDevices.commands.control',
        'securityDevices.cctv.media.view',
    ];
    foreach ($permissionKeys as $permissionKey) {
        Permission::query()->firstOrCreate(
            ['key' => $permissionKey],
            [
                'description' => $permissionKey,
                'group' => 'Security & Devices',
                'module' => 'Security & Devices',
            ],
        );
    }
    $permissions = Permission::query()
        ->whereIn('key', [
            'securityDevices.viewAny',
            'securityDevices.devices.view',
            'securityDevices.commands.observe',
            'securityDevices.commands.control',
        ])
        ->pluck('id');
    $user->permissionOverrides()->syncWithoutDetaching(
        $permissions->mapWithKeys(fn (int $id): array => [$id => ['allowed' => true]])->all(),
    );

    $device = Device::factory()->security()->create([
        'name' => 'M01 '.Str::headline($suffix).' Protected Camera',
        'category' => 'cctv',
        'subcategory' => 'dome_camera',
        'provider' => 'contract-test',
        'last_seen_at' => now(),
        'config' => ['management' => ['capabilities' => ['camera.privacy.enable']]],
    ]);
    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id,
        'assigned_at' => now(),
    ]);

    return compact('user', 'device');
}

test('management sensitivity boundaries stay simple and non-revealing on desktop', function () {
    foreach ([
        ['width' => 1440, 'height' => 900, 'suffix' => 'wide'],
        ['width' => 1280, 'height' => 800, 'suffix' => 'compact'],
    ] as $viewport) {
        $fixture = managementAuthorizationEvidenceFixture($viewport['suffix']);
        $user = $fixture['user'];
        $device = $fixture['device'];
        $url = "/security-devices/devices/{$device->id}?section=management";

        $this->browse(function (Browser $browser) use ($viewport, $user, $device, $url): void {
            $browser->resize($viewport['width'], $viewport['height'])
                ->loginAs($user)
                ->visit($url)
                ->waitForText($device->name)
                ->waitForText('No management actions available')
                ->assertSee('No management action is available for your current access and Device context.')
                ->assertDontSee('Enable camera privacy mode')
                ->assertScript(
                    'document.documentElement.scrollWidth <= document.documentElement.clientWidth',
                    true,
                );

            $permission = Permission::query()
                ->where('key', 'securityDevices.cctv.media.view')
                ->firstOrFail();
            $user->permissionOverrides()->syncWithoutDetaching([
                $permission->id => ['allowed' => true],
            ]);

            $browser->refresh()
                ->waitForText('Enable camera privacy mode')
                ->assertDontSee('No management actions available')
                ->assertSee('High-risk control')
                ->assertSee('The provider has not registered an approved execution and reconciliation adapter for this action.')
                ->assertScript(
                    'document.documentElement.scrollWidth <= document.documentElement.clientWidth',
                    true,
                );
        });
    }
});
