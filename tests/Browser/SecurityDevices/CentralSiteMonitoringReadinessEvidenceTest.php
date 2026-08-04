<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Discovery\Models\DiscoveryRun;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringProfile;
use App\Domain\Monitoring\Models\MonitoringRuntimeHeartbeat;
use App\Domain\Monitoring\Models\MonitorObservation;
use App\Domain\Monitoring\Topology\Models\TopologySnapshot;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;

/** @return array{user: User, site: Site, hiddenSite: Site} */
function centralSiteMonitoringReadinessEvidenceFixture(string $suffix): array
{
    $run = Str::lower((string) Str::uuid());
    $site = Site::factory()->create([
        'name' => 'A02 '.Str::headline($suffix).' Direct Monitoring Site '.$run,
        'is_active' => true,
        'archived' => false,
    ]);
    $hiddenSite = Site::factory()->create([
        'name' => 'A02 Hidden Monitoring Site '.$run,
        'is_active' => true,
        'archived' => false,
    ]);
    $user = User::factory()->create([
        'name' => 'A02 '.Str::headline($suffix).' Monitoring Operator',
        'email' => "a02.operator.{$suffix}.{$run}@example.test",
        'approved_at' => now(),
    ]);
    $role = Role::query()->firstOrCreate(
        ['name' => 'a02_central_site_readiness_browser_evidence'],
        [
            'label' => 'A02 central Site readiness browser evidence',
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
        'securityDevices.events.view',
        'securityDevices.integrations.manage',
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
    $permissions = Permission::query()->whereIn('key', $permissionKeys)->pluck('id');
    $user->permissionOverrides()->syncWithoutDetaching(
        $permissions->mapWithKeys(fn (int $id): array => [$id => ['allowed' => true]])->all(),
    );

    $device = Device::factory()->itInfrastructure()->create([
        'name' => 'A02 '.$suffix.' central SD-WAN gateway '.$run,
    ]);
    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id,
        'assignment_type' => 'permanent',
        'assigned_at' => now()->subHour(),
    ]);
    $profile = MonitoringProfile::factory()->create([
        'name' => 'A02 '.$suffix.' direct path profile '.$run,
        'stale_after_seconds' => 300,
        'is_active' => true,
    ]);
    $monitor = Monitor::factory()->create([
        'device_id' => $device->id,
        'profile_id' => $profile->id,
        'collector_id' => null,
        'name' => 'A02 direct HTTPS availability',
        'target' => 'https://10.250.0.1/private-health',
        'config' => ['credential_reference' => 'A02-BROWSER-PRIVATE-CREDENTIAL'],
        'current_state' => MonitorState::Healthy,
        'effective_state' => MonitorState::Healthy,
        'last_observation_at' => now()->subSeconds(20),
    ]);
    MonitorObservation::factory()->create([
        'monitor_id' => $monitor->id,
        'source_key' => "runtime:{$monitor->id}:browser-proof",
        'state' => MonitorState::Healthy,
        'observed_at' => now()->subSeconds(20),
    ]);

    foreach ([
        'orchestration' => 'monitoring',
        'checks' => 'monitoring-checks',
        'events' => 'monitoring-events',
        'topology' => 'monitoring-topology',
    ] as $component => $queue) {
        MonitoringRuntimeHeartbeat::query()->updateOrCreate(
            ['component' => $component],
            [
                'queue' => $queue,
                'last_dispatched_token' => (string) Str::uuid(),
                'last_dispatched_at' => now()->subSeconds(15),
                'last_consumed_token' => (string) Str::uuid(),
                'last_consumed_dispatch_at' => now()->subSeconds(15),
                'last_consumed_at' => now()->subSeconds(10),
            ],
        );
    }

    TopologySnapshot::factory()->create([
        'site_id' => $site->id,
        'source' => 'native:snmp',
        'captured_at' => now()->subMinute(),
        'node_count' => 2,
        'edge_count' => 1,
    ]);
    $scope = DiscoveryScope::factory()->create([
        'site_id' => $site->id,
        'collector_id' => null,
        'name' => 'A02 '.$suffix.' central discovery '.$run,
        'status' => 'active',
    ]);
    DiscoveryRun::factory()->create([
        'discovery_scope_id' => $scope->id,
        'status' => 'completed',
        'completed_at' => now()->subMinutes(2),
    ]);

    return compact('user', 'site', 'hiddenSite');
}

test('central Site monitoring readiness is clear private and usable on desktop', function () {
    $severeLogs = collect();

    foreach ([
        ['width' => 1440, 'height' => 900, 'suffix' => 'wide'],
        ['width' => 1280, 'height' => 800, 'suffix' => 'compact'],
    ] as $viewport) {
        $fixture = centralSiteMonitoringReadinessEvidenceFixture($viewport['suffix']);

        $this->browse(function (Browser $browser) use ($fixture, $viewport, $severeLogs): void {
            $browser->resize($viewport['width'], $viewport['height'])
                ->loginAs($fixture['user'])
                ->visit('/security-devices/monitoring?tab=collection')
                ->waitForText('Central Site readiness', 30)
                ->assertSee($fixture['site']->name)
                ->assertSee('Direct monitoring proven')
                ->assertSee('Central path verified')
                ->assertSee('Runtime Available')
                ->assertSee('Topology Current')
                ->assertSee('Discovery Current')
                ->assertSee('no collector is required')
                ->assertDontSee($fixture['hiddenSite']->name)
                ->assertDontSee('A02-BROWSER-PRIVATE-CREDENTIAL')
                ->assertDontSee('10.250.0.1')
                ->assertScript(
                    'document.documentElement.scrollWidth <= document.documentElement.clientWidth',
                    true,
                );

            $severeLogs->push(...collect($browser->driver->manage()->getLog('browser'))
                ->filter(fn (array $entry): bool => strtoupper((string) ($entry['level'] ?? '')) === 'SEVERE')
                ->all());
        });
    }

    expect($severeLogs)->toBeEmpty();
});
