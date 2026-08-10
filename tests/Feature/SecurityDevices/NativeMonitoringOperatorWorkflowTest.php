<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Discovery\Models\DiscoveryRun;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Jobs\RunDiscoveryScope;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorDependency;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Models\MonitoringProfile;
use App\Domain\SecurityDevices\Enums\AssignmentType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class NativeMonitoringOperatorWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);
    }

    public function test_manager_creates_direct_scope_and_monitor_without_projecting_raw_network_or_target(): void
    {
        [$manager, $site, $device] = $this->managerAtSite();

        $scopeResponse = $this->actingAs($manager)->postJson('/security-devices/discovery/scopes', [
            'site_id' => $site->id,
            'name' => 'Primary SD-WAN network',
            'cidrs' => ['10.44.0.0/16'],
            'seed_hosts' => ['10.44.0.1'],
            'protocols' => ['icmp', 'tcp', 'tls'],
            'exclusions' => [],
            'port_bounds' => ['tcp' => [443], 'tls' => [443]],
            'max_targets_per_run' => 1024,
            'packets_per_second' => 20,
        ])->assertCreated()->assertJsonPath('scope.collection_mode', 'central_direct');

        $scope = DiscoveryScope::query()->findOrFail($scopeResponse->json('scope.id'));
        $this->assertNull($scope->collector_id);
        $this->assertSame(['10.44.0.0/16'], $scope->cidrs);

        $profile = MonitoringProfile::factory()->create(['name' => 'Standard direct policy']);
        $monitorResponse = $this->actingAs($manager)->postJson('/security-devices/monitoring/native-monitors', [
            'device_id' => $device->id,
            'profile_id' => $profile->id,
            'kind' => 'icmp',
            'name' => 'Gateway reachability',
            'target' => '10.44.0.10',
            'affects_availability' => true,
        ])->assertCreated()->assertJsonPath('monitor.collection_mode', 'central_direct');

        $monitor = Monitor::query()->findOrFail($monitorResponse->json('monitor.id'));
        $this->assertNull($monitor->collector_id);
        $this->assertSame('10.44.0.10', $monitor->target);
        $this->assertSame(['host' => '10.44.0.10'], $monitor->config);

        $this->actingAs($manager)
            ->patchJson("/security-devices/discovery/scopes/{$scope->id}", [
                'name' => 'Primary central Site network',
                'max_targets_per_run' => 512,
            ])
            ->assertOk()
            ->assertJsonPath('scope.network_range_count', 1);
        $this->assertSame(['10.44.0.0/16'], $scope->fresh()->cidrs);

        $this->actingAs($manager)
            ->patchJson("/security-devices/monitoring/native-monitors/{$monitor->id}", [
                'profile_id' => $profile->id,
                'name' => 'Gateway central reachability',
                'target' => '',
                'affects_availability' => true,
            ])
            ->assertOk()
            ->assertJsonPath('monitor.name', 'Gateway central reachability');
        $this->assertSame('10.44.0.10', $monitor->fresh()->target);

        $auditJson = AuditLog::query()
            ->whereIn('action', [
                'monitoring.discovery.scope.created',
                'monitoring.discovery.scope.updated',
                'monitoring.monitor.created',
                'monitoring.monitor.updated',
            ])
            ->get()
            ->map(fn (AuditLog $audit): array => $audit->meta)
            ->toJson();
        $this->assertStringNotContainsString('10.44.0.0/16', $auditJson);
        $this->assertStringNotContainsString('10.44.0.10', $auditJson);

        $monitoringPage = $this->actingAs($manager)->get('/security-devices/monitoring');
        $monitoringPage->assertOk()->assertDontSee('10.44.0.10', false);
        $discoveryPage = $this->actingAs($manager)->get('/security-devices/discovery?tab=scopes');
        $discoveryPage->assertOk()->assertDontSee('10.44.0.0/16', false);

        $this->actingAs($manager)
            ->postJson("/security-devices/monitoring/native-monitors/{$monitor->id}/deactivate", [
                'reason_code' => 'replaced',
            ])
            ->assertOk()
            ->assertJsonPath('monitor.enabled', false);
        $deactivationAudit = AuditLog::query()
            ->where('action', 'monitoring.monitor.deactivated')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('replaced', $deactivationAudit->meta['reason_code']);
        $this->assertStringNotContainsString(
            '10.44.0.10',
            json_encode($deactivationAudit->meta, JSON_THROW_ON_ERROR),
        );
    }

    public function test_site_and_permission_boundaries_conceal_native_monitoring_objects(): void
    {
        [$manager, $site, $device] = $this->managerAtSite();
        $otherSite = $this->activeSite('Hidden Site');
        $otherDevice = $this->deviceAtSite($otherSite, 'Hidden gateway');
        $profile = MonitoringProfile::factory()->create();
        $hiddenScope = DiscoveryScope::factory()->create([
            'site_id' => $otherSite->id,
            'collector_id' => null,
        ]);
        $hiddenMonitor = Monitor::factory()->create([
            'device_id' => $otherDevice->id,
            'profile_id' => $profile->id,
            'collector_id' => null,
            'kind' => MonitorKind::Icmp,
        ]);

        $this->actingAs($manager)
            ->patchJson("/security-devices/monitoring/native-monitors/{$hiddenMonitor->id}", [
                'name' => 'Attempted hidden update',
            ])
            ->assertNotFound();
        $this->actingAs($manager)
            ->postJson("/security-devices/discovery/scopes/{$hiddenScope->id}/apply")
            ->assertNotFound();

        $viewer = $this->viewerAtSite($site, [
            'securityDevices.events.view',
            'securityDevices.integrations.view',
            'securityDevices.integrations.manage',
        ]);
        $this->actingAs($viewer)
            ->postJson('/security-devices/monitoring/native-monitors', [
                'device_id' => $device->id,
                'profile_id' => $profile->id,
                'kind' => 'icmp',
                'name' => 'Forbidden monitor',
                'target' => '10.44.0.10',
            ])
            ->assertForbidden();

        $this->assertSame($hiddenMonitor->name, $hiddenMonitor->fresh()->name);
        $this->assertDatabaseCount('monitoring_discovery_runs', 0);
    }

    public function test_target_must_be_inside_active_direct_site_scope_and_remote_scopes_are_not_mutable_here(): void
    {
        [$manager, $site, $device] = $this->managerAtSite();
        $profile = MonitoringProfile::factory()->create();
        DiscoveryScope::factory()->create([
            'site_id' => $site->id,
            'collector_id' => null,
            'cidrs' => ['10.44.0.0/16'],
            'protocols' => ['icmp', 'tcp'],
            'port_bounds' => ['tcp' => [443]],
            'exclusions' => [],
        ]);

        $this->actingAs($manager)->postJson('/security-devices/monitoring/native-monitors', [
            'device_id' => $device->id,
            'profile_id' => $profile->id,
            'kind' => 'http',
            'name' => 'Cross protocol attempt',
            'target' => 'https://10.44.0.10/health',
        ])->assertUnprocessable()->assertJsonValidationErrors('target');

        $this->actingAs($manager)->postJson('/security-devices/monitoring/native-monitors', [
            'device_id' => $device->id,
            'profile_id' => $profile->id,
            'kind' => 'icmp',
            'name' => 'Out of scope target',
            'target' => '10.99.0.10',
        ])->assertUnprocessable()->assertJsonValidationErrors('target');

        $collector = MonitoringCollector::factory()->create(['site_id' => $site->id]);
        $remoteScope = DiscoveryScope::factory()->create([
            'site_id' => $site->id,
            'collector_id' => $collector->id,
        ]);
        $this->actingAs($manager)
            ->patchJson("/security-devices/discovery/scopes/{$remoteScope->id}", [
                'name' => 'Attempted remote mutation',
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('monitors', 0);
        $this->assertNotSame('Attempted remote mutation', $remoteScope->fresh()->name);
    }

    public function test_monitor_deactivation_is_dependency_safe_and_scope_apply_is_bounded(): void
    {
        Queue::fake();
        [$manager, $site, $device] = $this->managerAtSite();
        $otherDevice = $this->deviceAtSite($site, 'Downstream switch');
        $profile = MonitoringProfile::factory()->create();
        $upstream = Monitor::factory()->create([
            'device_id' => $device->id,
            'profile_id' => $profile->id,
            'collector_id' => null,
            'kind' => MonitorKind::Icmp,
            'is_enabled' => true,
        ]);
        $downstream = Monitor::factory()->create([
            'device_id' => $otherDevice->id,
            'profile_id' => $profile->id,
            'collector_id' => null,
            'kind' => MonitorKind::Icmp,
            'is_enabled' => true,
        ]);
        MonitorDependency::query()->create([
            'site_id' => $site->id,
            'upstream_monitor_id' => $upstream->id,
            'downstream_monitor_id' => $downstream->id,
            'policy' => MonitorDependency::POLICY_SUPPRESS,
            'source' => 'manual',
            'confidence' => 1,
            'is_active' => true,
        ]);

        $this->actingAs($manager)
            ->postJson("/security-devices/monitoring/native-monitors/{$upstream->id}/deactivate", [
                'reason_code' => 'replaced',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('monitor');
        $this->assertTrue($upstream->fresh()->is_enabled);

        $scope = DiscoveryScope::factory()->create([
            'site_id' => $site->id,
            'collector_id' => null,
            'cidrs' => ['10.44.0.0/30'],
            'protocols' => ['icmp', 'tcp'],
            'port_bounds' => ['tcp' => [443]],
            'max_targets_per_run' => 4,
            'status' => 'active',
        ]);
        $apply = $this->actingAs($manager)
            ->postJson("/security-devices/discovery/scopes/{$scope->id}/apply")
            ->assertAccepted()
            ->assertJsonPath('run.planned_targets', 4);
        Queue::assertPushed(RunDiscoveryScope::class, 1);

        $this->actingAs($manager)
            ->postJson("/security-devices/discovery/scopes/{$scope->id}/deactivate", [
                'reason_code' => 'network_retired',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('scope');
        DiscoveryRun::query()->findOrFail($apply->json('run.id'))->forceFill([
            'status' => 'completed',
            'completed_at' => now(),
        ])->save();
        $this->actingAs($manager)
            ->postJson("/security-devices/discovery/scopes/{$scope->id}/deactivate", [
                'reason_code' => 'network_retired',
            ])
            ->assertOk()
            ->assertJsonPath('scope.status', 'inactive');
    }

    /** @return array{User, Site, Device} */
    private function managerAtSite(): array
    {
        $site = $this->activeSite('Allowed monitoring Site');

        return [
            $this->viewerAtSite($site, [
                'securityDevices.events.view',
                'securityDevices.integrations.view',
                'securityDevices.integrations.manage',
                'securityDevices.monitoring.manage',
            ]),
            $site,
            $this->deviceAtSite($site, 'SD-WAN gateway'),
        ];
    }

    private function viewerAtSite(Site $site, array $permissions): User
    {
        $viewer = User::factory()->create(['approved_at' => now()]);
        $viewer->roles()->attach(Role::query()->where('name', 'support_worker')->firstOrFail());
        $overrides = Permission::query()
            ->whereIn('key', $permissions)
            ->pluck('id')
            ->mapWithKeys(fn (int $id): array => [$id => ['allowed' => true]])
            ->all();
        $viewer->permissionOverrides()->syncWithoutDetaching($overrides);
        HrEmployeeProfile::factory()->create([
            'user_id' => $viewer->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
        ]);

        return $viewer;
    }

    private function activeSite(string $name): Site
    {
        return Site::factory()->create([
            'name' => $name,
            'is_active' => true,
            'archived' => false,
            'archived_at' => null,
        ]);
    }

    private function deviceAtSite(Site $site, string $name): Device
    {
        $device = Device::factory()->create(['name' => $name, 'status' => 'active']);
        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assignment_type' => AssignmentType::Permanent,
            'assigned_at' => now()->subMinute(),
        ]);

        return $device;
    }
}
