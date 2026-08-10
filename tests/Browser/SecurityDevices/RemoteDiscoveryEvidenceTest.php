<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Discovery\Models\DiscoveryCandidate;
use App\Domain\Monitoring\Discovery\Models\DiscoveryResult;
use App\Domain\Monitoring\Discovery\Models\DiscoveryRun;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;

/** @return array{user: User, scope: DiscoveryScope, hiddenScope: DiscoveryScope, privateEvidence: string} */
function remoteDiscoveryEvidenceFixture(string $suffix): array
{
    $runKey = Str::lower((string) Str::uuid());
    $site = Site::factory()->create([
        'name' => 'A06 '.Str::headline($suffix).' Remote Site '.$runKey,
        'is_active' => true,
        'archived' => false,
    ]);
    $hiddenSite = Site::factory()->create([
        'name' => 'A06 Hidden Remote Site '.$runKey,
        'is_active' => true,
        'archived' => false,
    ]);
    $user = User::factory()->create([
        'name' => 'A06 '.Str::headline($suffix).' Discovery Operator',
        'email' => "a06.discovery.{$suffix}.{$runKey}@example.test",
        'approved_at' => now(),
    ]);
    $role = Role::query()->firstOrCreate(
        ['name' => 'a06_remote_discovery_browser_evidence'],
        ['label' => 'A06 remote discovery browser evidence', 'level' => 50, 'type' => 'custom'],
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
        'securityDevices.integrations.view',
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

    $collector = MonitoringCollector::factory()->create([
        'site_id' => $site->id,
        'name' => 'A06 Remote collector '.$suffix,
        'status' => 'online',
        'last_seen_at' => now()->subMinute(),
        'last_heartbeat_at' => now()->subMinute(),
    ]);
    $scope = DiscoveryScope::factory()->create([
        'site_id' => $site->id,
        'collector_id' => $collector->id,
        'name' => 'A06 Remote clinical network '.$suffix,
        'cidrs' => ['10.66.0.0/24'],
        'seed_hosts' => [],
        'protocols' => ['icmp', 'tcp', 'tls'],
        'exclusions' => ['10.66.0.1'],
        'port_bounds' => ['tcp' => [22], 'tls' => [443]],
        'max_targets_per_run' => 2,
        'packets_per_second' => 20,
        'status' => 'active',
    ]);
    $run = DiscoveryRun::factory()->create([
        'discovery_scope_id' => $scope->id,
        'status' => 'running',
        'scope_snapshot' => $scope->snapshot(),
        'planned_targets' => 2,
        'started_at' => now()->subMinutes(2),
    ]);
    DiscoveryResult::query()->create([
        'discovery_run_id' => $run->id,
        'target_reference_hash' => hash('sha256', $runKey.'-returned'),
        'target_source' => 'cidr',
        'outcome' => 'found',
        'evidence_hash' => hash('sha256', $runKey.'-evidence'),
        'observed_at' => now()->subMinute(),
    ]);
    DiscoveryResult::query()->create([
        'discovery_run_id' => $run->id,
        'target_reference_hash' => hash('sha256', $runKey.'-pending'),
        'target_source' => 'cidr',
        'outcome' => 'pending',
    ]);
    $privateEvidence = 'A06-PRIVATE-TARGET-'.$runKey;
    DiscoveryCandidate::factory()->create([
        'discovery_run_id' => $run->id,
        'canonical_device_id' => null,
        'decision' => 'review',
        'confidence' => 25,
        'reasons' => ['hostname_is_mutable'],
        'evidence_snapshot' => ['target' => $privateEvidence],
    ]);

    $hiddenCollector = MonitoringCollector::factory()->create([
        'site_id' => $hiddenSite->id,
        'status' => 'online',
        'last_seen_at' => now()->subMinute(),
        'last_heartbeat_at' => now()->subMinute(),
    ]);
    $hiddenScope = DiscoveryScope::factory()->create([
        'site_id' => $hiddenSite->id,
        'collector_id' => $hiddenCollector->id,
        'name' => 'A06 Hidden discovery scope '.$runKey,
        'cidrs' => ['10.77.0.0/24'],
        'protocols' => ['icmp'],
        'status' => 'active',
    ]);
    DiscoveryRun::factory()->create([
        'discovery_scope_id' => $hiddenScope->id,
        'status' => 'running',
        'scope_snapshot' => $hiddenScope->snapshot(),
        'planned_targets' => 1,
    ]);

    return compact('user', 'scope', 'hiddenScope', 'privateEvidence');
}

test('remote discovery handoff and candidate review stay clear private and usable on desktop', function () {
    $severeLogs = collect();

    foreach ([
        ['width' => 1440, 'height' => 900, 'suffix' => 'wide'],
        ['width' => 1280, 'height' => 800, 'suffix' => 'compact'],
    ] as $viewport) {
        $fixture = remoteDiscoveryEvidenceFixture($viewport['suffix']);

        $this->browse(function (Browser $browser) use ($fixture, $viewport, $severeLogs): void {
            $browser->resize($viewport['width'], $viewport['height'])
                ->loginAs($fixture['user'])
                ->visit('/security-devices/discovery?tab=runs')
                ->waitForText('Immutable discovery runs', 30)
                ->assertSee($fixture['scope']->name)
                ->assertSee('Remote path')
                ->assertSee('Collector Available')
                ->assertSee('Running')
                ->assertSee('1 of 2 results returned')
                ->assertSee('1 target remains')
                ->assertSee('ordered encrypted buffer')
                ->assertDontSee($fixture['hiddenScope']->name)
                ->assertDontSee($fixture['privateEvidence'])
                ->assertScript(
                    'document.documentElement.scrollWidth <= document.documentElement.clientWidth',
                    true,
                )
                ->visit('/security-devices/discovery?tab=candidates')
                ->waitForText('Discovery candidates', 30)
                ->assertSee('Proposed Device')
                ->assertSee('Hostname Is Mutable')
                ->assertSee('Review')
                ->assertSee('25%')
                ->assertDontSee($fixture['hiddenScope']->name)
                ->assertDontSee($fixture['privateEvidence'])
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
