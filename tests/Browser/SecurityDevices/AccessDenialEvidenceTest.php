<?php

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceGroup;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;

/**
 * @return array{
 *     user: User,
 *     visibleMember: Device,
 *     visibleCandidate: Device,
 *     privateMember: Device,
 *     privateCandidate: Device,
 *     group: DeviceGroup
 * }
 */
function accessDenialEvidenceFixture(string $suffix): array
{
    $run = Str::lower((string) Str::uuid());
    $site = Site::factory()->create([
        'name' => 'E09 '.Str::headline($suffix).' Site',
        'is_active' => true,
        'archived' => false,
    ]);
    $client = Client::factory()->create([
        'site_id' => $site->id,
        'first_name' => 'E09 Private',
        'last_name' => Str::headline($suffix).' Client',
    ]);
    $user = User::factory()->create([
        'name' => 'E09 '.Str::headline($suffix).' Device Manager',
        'email' => "e09.device.manager.{$suffix}.{$run}@example.test",
        'approved_at' => now(),
    ]);
    $role = Role::query()->firstOrCreate(
        ['name' => 'e09_access_denial_browser_evidence'],
        [
            'label' => 'E09 access denial browser evidence',
            'level' => 50,
            'type' => 'custom',
        ],
    );
    $user->roles()->attach($role);

    $permissionKeys = [
        'securityDevices.viewAny',
        'securityDevices.devices.view',
        'securityDevices.devices.viewAllSites',
        'securityDevices.groups.manage',
        'securityDevices.commands.observe',
        'securityDevices.commands.control',
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
    $permissionIds = Permission::query()
        ->whereIn('key', $permissionKeys)
        ->pluck('id');
    $user->permissionOverrides()->sync(
        $permissionIds->mapWithKeys(fn (int $id): array => [$id => ['allowed' => true]])->all(),
    );

    $visibleMember = Device::factory()->security()->create([
        'name' => "E09 {$suffix} Visible Camera",
        'category' => 'cctv',
        'subcategory' => 'dome_camera',
        'provider' => 'contract-test',
        'last_seen_at' => now(),
        'config' => ['management' => ['capabilities' => ['camera.privacy.enable']]],
    ]);
    $visibleCandidate = Device::factory()->tracking()->create([
        'name' => "E09 {$suffix} Visible Candidate",
    ]);
    $privateMember = Device::factory()->security()->create([
        'name' => "E09 {$suffix} Private Client Camera",
    ]);
    $privateCandidate = Device::factory()->tracking()->create([
        'name' => "E09 {$suffix} Private Client Tracker",
    ]);

    foreach ([$visibleMember, $visibleCandidate] as $device) {
        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assigned_at' => now(),
        ]);
    }
    foreach ([$privateMember, $privateCandidate] as $device) {
        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_CLIENT,
            'assignable_id' => $client->id,
            'assigned_at' => now(),
        ]);
    }

    $group = DeviceGroup::query()->create([
        'name' => "E09 {$suffix} Governed Device Group {$run}",
        'type' => 'functional',
        'description' => 'Browser evidence for canonical Device privacy boundaries.',
    ]);
    $group->devices()->attach([$visibleMember->id, $privateMember->id]);

    return compact(
        'user',
        'visibleMember',
        'visibleCandidate',
        'privateMember',
        'privateCandidate',
        'group',
    );
}

test('device privacy denials stay complete and non-revealing on desktop', function () {
    foreach ([
        ['width' => 1440, 'height' => 900, 'suffix' => 'wide'],
        ['width' => 1280, 'height' => 800, 'suffix' => 'compact'],
    ] as $viewport) {
        $fixture = accessDenialEvidenceFixture($viewport['suffix']);
        $user = $fixture['user'];
        $visibleMember = $fixture['visibleMember'];
        $visibleCandidate = $fixture['visibleCandidate'];
        $privateMember = $fixture['privateMember'];
        $privateCandidate = $fixture['privateCandidate'];
        $group = $fixture['group'];

        $this->browse(function (Browser $browser) use (
            $viewport,
            $user,
            $visibleMember,
            $visibleCandidate,
            $privateMember,
            $privateCandidate,
            $group,
        ): void {
            $browser->resize($viewport['width'], $viewport['height'])
                ->loginAs($user)
                ->visit('/security-devices/devices')
                ->waitForText($visibleMember->name, 40)
                ->assertSee($visibleCandidate->name)
                ->assertSourceMissing($privateMember->name)
                ->assertSourceMissing($privateCandidate->name)
                ->assertScript(
                    'document.documentElement.scrollWidth <= document.documentElement.clientWidth',
                    true,
                )
                ->visit('/security-devices/device-groups')
                ->waitForText($group->name, 40)
                ->assertSee('1 device')
                ->assertSourceMissing($privateMember->name)
                ->assertSourceMissing($privateCandidate->name)
                ->assertScript(
                    'document.documentElement.scrollWidth <= document.documentElement.clientWidth',
                    true,
                )
                ->visit("/security-devices/device-groups/{$group->id}")
                ->waitForText('Members (1)', 40)
                ->assertSee($visibleMember->name)
                ->assertSourceMissing($privateMember->name)
                ->assertSourceMissing($privateCandidate->name)
                ->press('Add Device')
                ->waitForText('Add Device to Group', 20)
                ->click('[role="dialog"] button[role="combobox"]')
                ->waitForText($visibleCandidate->name, 20)
                ->assertDontSee($privateCandidate->name)
                ->assertScript(
                    'document.documentElement.scrollWidth <= document.documentElement.clientWidth',
                    true,
                );

            $browser->script('document.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape", bubbles: true }));');
            $browser->press('Cancel')
                ->visit('/security-devices/devices?search='.rawurlencode('Private Client'))
                ->waitForText('No results found', 40)
                ->assertSourceMissing($privateMember->name)
                ->assertSourceMissing($privateCandidate->name)
                ->assertScript(
                    'document.documentElement.scrollWidth <= document.documentElement.clientWidth',
                    true,
                )
                ->visit("/security-devices/devices/{$visibleMember->id}?section=management")
                ->waitForText($visibleMember->name, 40)
                ->waitForText('No management actions available', 20)
                ->assertDontSee('Enable camera privacy mode')
                ->assertScript(
                    'document.documentElement.scrollWidth <= document.documentElement.clientWidth',
                    true,
                );

            $severeLogs = collect($browser->driver->manage()->getLog('browser'))
                ->filter(fn (array $entry): bool => strtoupper((string) ($entry['level'] ?? '')) === 'SEVERE')
                ->values()
                ->all();

            $this->assertSame([], $severeLogs, json_encode($severeLogs));

            $privateName = json_encode($privateMember->name, JSON_THROW_ON_ERROR);
            $browser->script(<<<JS
                window.__e09DirectStatus = null;
                window.__e09DirectBody = null;
                fetch('/security-devices/devices/{$privateMember->id}', {
                    credentials: 'same-origin',
                    headers: { Accept: 'text/html' },
                }).then(async (response) => {
                    window.__e09DirectStatus = response.status;
                    window.__e09DirectBody = await response.text();
                });
            JS);
            $browser->waitUsing(
                10,
                100,
                fn (): bool => ($browser->script('return window.__e09DirectStatus;')[0] ?? null) === 404,
                'The private Device direct route did not resolve as a concealed 404.',
            )
                ->assertScript('window.__e09DirectStatus', 404)
                ->assertScript("window.__e09DirectBody.includes({$privateName})", false);

            $unexpectedDirectProbeLogs = collect($browser->driver->manage()->getLog('browser'))
                ->filter(fn (array $entry): bool => strtoupper((string) ($entry['level'] ?? '')) === 'SEVERE')
                ->reject(fn (array $entry): bool => str_contains(
                    (string) ($entry['message'] ?? ''),
                    "/security-devices/devices/{$privateMember->id} - Failed to load resource: the server responded with a status of 404",
                ))
                ->values()
                ->all();

            $this->assertSame([], $unexpectedDirectProbeLogs, json_encode($unexpectedDirectProbeLogs));
        });
    }
});
