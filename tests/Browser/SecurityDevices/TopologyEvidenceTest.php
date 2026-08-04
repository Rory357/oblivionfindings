<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Topology\Data\TopologyEvidence;
use App\Domain\Monitoring\Topology\Services\TopologySnapshotBuilder;
use App\Domain\SecurityDevices\Enums\AssignmentType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;

/** @return array{viewer: User, gateway: Device, switch: Device, accessPoint: Device} */
function topologyEvidenceBrowserFixture(): array
{
    $run = Str::lower((string) Str::uuid());
    $viewer = User::factory()->create([
        'name' => 'A09 topology viewer',
        'email' => "a09.topology.{$run}@example.test",
        'approved_at' => now(),
    ]);
    $permissions = collect([
        'securityDevices.viewAny',
        'securityDevices.devices.view',
    ])->map(function (string $key): int {
        return Permission::query()->firstOrCreate(
            ['key' => $key],
            [
                'description' => $key,
                'group' => 'Security & Devices',
                'module' => 'Security & Devices',
            ],
        )->id;
    });
    $viewer->permissionOverrides()->syncWithoutDetaching(
        $permissions->mapWithKeys(fn (int $id): array => [$id => ['allowed' => true]])->all(),
    );

    $site = Site::factory()->create([
        'name' => 'A09 Topology Site '.Str::upper(Str::substr($run, 0, 8)),
        'is_active' => true,
        'archived' => false,
        'archived_at' => null,
    ]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $viewer->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'is_active' => true,
    ]);
    $gateway = Device::factory()->itInfrastructure()->create([
        'name' => 'A09 SD-WAN gateway',
    ]);
    $switch = Device::factory()->itInfrastructure()->create([
        'name' => 'A09 core switch',
    ]);
    $accessPoint = Device::factory()->itInfrastructure()->create([
        'name' => 'A09 reception access point',
    ]);

    foreach ([$gateway, $switch, $accessPoint] as $device) {
        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assignment_type' => AssignmentType::Permanent,
            'assigned_at' => now()->subMinute(),
            'released_at' => null,
        ]);
    }

    $builder = app(TopologySnapshotBuilder::class);
    $builder->build($site, [
        new TopologyEvidence(
            source: 'lldp',
            fromDeviceId: $gateway->id,
            toDeviceId: $switch->id,
            kind: 'ethernet',
            localPort: 'lan1',
            remotePort: 'uplink',
            confidence: 0.98,
            evidence: ['protocol' => 'lldp', 'relationship' => 'direct_neighbor'],
        ),
    ], source: 'native:snmp', sourceCheckpoint: "a09-native-{$run}");
    $builder->build($site, [
        new TopologyEvidence(
            source: 'provider',
            fromDeviceId: $switch->id,
            toDeviceId: $accessPoint->id,
            kind: 'uplink',
            localPort: null,
            remotePort: null,
            confidence: 0.99,
            evidence: ['provider' => 'unifi', 'protocol' => 'unifi_network_api'],
        ),
    ], source: 'provider:unifi', sourceCheckpoint: "a09-unifi-{$run}");

    return compact('viewer', 'gateway', 'switch', 'accessPoint');
}

test('native and provider topology evidence stays understandable and usable on desktop', function () {
    $fixture = topologyEvidenceBrowserFixture();
    $severeLogs = collect();

    $this->browse(function (Browser $browser) use ($fixture, $severeLogs): void {
        $browser->loginAs($fixture['viewer']);

        foreach ([
            ['width' => 1440, 'height' => 900],
            ['width' => 1280, 'height' => 800],
        ] as $viewport) {
            $browser->resize($viewport['width'], $viewport['height'])
                ->visit('/security-devices/network-it?tab=map')
                ->waitForText('Known topology evidence', 40)
                ->assertSee('Native SNMP')
                ->assertSee('UniFi provider')
                ->assertSee('Since the previous snapshots: 2 added · 0 removed · 0 changed')
                ->assertSee($fixture['gateway']->name)
                ->assertSee($fixture['switch']->name)
                ->assertSee($fixture['accessPoint']->name)
                ->assertSee('Lldp Ethernet evidence · 98% confidence')
                ->assertSee('UniFi provider Uplink evidence · 99% confidence')
                ->assertSee('Evidence and relationships')
                ->assertScript(
                    'document.querySelectorAll(\'section[aria-label="Keyboard-readable topology relationships"] article[tabindex="0"]\').length',
                    2,
                )
                ->assertScript(
                    'document.documentElement.scrollWidth <= document.documentElement.clientWidth',
                    true,
                );

            $browser->script(
                'document.querySelector(\'section[aria-label="Keyboard-readable topology relationships"] article\')?.focus();',
            );
            $browser->assertScript('document.activeElement?.tagName', 'ARTICLE');

            $severeLogs->push(...collect($browser->driver->manage()->getLog('browser'))
                ->filter(fn (array $entry): bool => strtoupper((string) ($entry['level'] ?? '')) === 'SEVERE')
                ->all());
        }
    });

    expect($severeLogs)->toBeEmpty();
});
