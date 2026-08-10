<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Services\MonitoringPolicyRules;
use App\Domain\SecurityDevices\Enums\AssignmentType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitoringPolicySettingsAuthoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);
    }

    public function test_dedicated_permission_controls_settings_navigation_and_write_routes(): void
    {
        $supportWorker = User::factory()->create(['approved_at' => now()]);
        $supportWorker->roles()->attach(Role::query()->where('name', 'support_worker')->firstOrFail());

        $this->actingAs($supportWorker)
            ->postJson('/security-devices/settings/monitoring/profiles', ['name' => 'Denied profile'])
            ->assertForbidden();

        $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $manager = $this->siteManager($site);
        $this->actingAs($manager)
            ->get('/security-devices/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('auth.can.securityDevices.monitoringManage', true)
                ->where('monitoringPolicyWorkspace.can_manage', true)
                ->where('monitoringPolicyWorkspace.can_manage_application', false));

        $this->actingAs($manager)
            ->postJson('/security-devices/settings/monitoring/profiles', ['name' => 'Application profile'])
            ->assertForbidden();
    }

    public function test_site_coverage_is_versioned_and_stale_writes_conflict_without_hard_delete(): void
    {
        $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $manager = $this->siteManager($site);
        $created = $this->actingAs($manager)
            ->postJson('/security-devices/settings/monitoring/coverage', [
                'site_id' => $site->id,
                'device_domain' => 'it_infrastructure',
                'device_category' => 'network',
                'capability' => 'reachability',
                'minimum_count' => 1,
                'support_status' => 'supported',
                'rationale' => 'Every Site router must have central reachability monitoring.',
            ])
            ->assertCreated()
            ->json('expectation');

        $this->actingAs($manager)
            ->patchJson('/security-devices/settings/monitoring/coverage/'.$created['id'], [
                'version' => 1,
                'minimum_count' => 2,
            ])
            ->assertOk()
            ->assertJsonPath('expectation.version', 2);

        $this->actingAs($manager)
            ->patchJson('/security-devices/settings/monitoring/coverage/'.$created['id'], [
                'version' => 1,
                'minimum_count' => 3,
            ])
            ->assertStatus(409);

        $this->actingAs($manager)
            ->postJson('/security-devices/settings/monitoring/coverage/'.$created['id'].'/deactivate', [
                'version' => 2,
                'reason' => 'The Site coverage expectation is temporarily withdrawn.',
            ])
            ->assertOk()
            ->assertJsonPath('expectation.version', 3)
            ->assertJsonPath('expectation.state', 'inactive');

        $this->actingAs($manager)
            ->postJson('/security-devices/settings/monitoring/coverage/'.$created['id'].'/reactivate', [
                'version' => 3,
                'reason' => 'The approved Site coverage expectation is active again.',
            ])
            ->assertOk()
            ->assertJsonPath('expectation.version', 4)
            ->assertJsonPath('expectation.state', 'active');

        $this->actingAs($manager)
            ->deleteJson('/security-devices/settings/monitoring/coverage/'.$created['id'])
            ->assertStatus(405);
    }

    public function test_hidden_sites_devices_and_monitors_are_concealed(): void
    {
        $visibleSite = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $hiddenSite = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $manager = $this->siteManager($visibleSite);
        [, $visibleDevice] = $this->deviceAt($visibleSite);
        [, $hiddenDevice] = $this->deviceAt($hiddenSite);
        $visibleMonitor = Monitor::factory()->create(['device_id' => $visibleDevice->id]);
        $hiddenMonitor = Monitor::factory()->create(['device_id' => $hiddenDevice->id]);

        $this->actingAs($manager)
            ->postJson('/security-devices/settings/monitoring/dependencies', [
                'site_id' => $visibleSite->id,
                'upstream_monitor_id' => $hiddenMonitor->id,
                'downstream_monitor_id' => $visibleMonitor->id,
                'confidence' => 1,
            ])
            ->assertNotFound();

        $this->actingAs($manager)
            ->postJson('/security-devices/settings/monitoring/retention/preview', [
                'name' => 'Hidden device evidence',
                'scope_kind' => 'device',
                'site_id' => null,
                'device_id' => $hiddenDevice->id,
                'data_class' => null,
                'privacy_class' => null,
                'raw_days' => 14,
                'hourly_days' => 180,
                'daily_days' => 1825,
                'legal_hold' => false,
            ])
            ->assertNotFound();
    }

    public function test_retention_requires_impact_preview_confirmation_and_records_no_raw_target_projection(): void
    {
        $admin = User::factory()->create(['approved_at' => now()]);
        $admin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
        $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
        [, $device] = $this->deviceAt($site);
        Monitor::factory()->create([
            'device_id' => $device->id,
            'name' => 'Safe monitor name',
            'target' => 'do-not-project.internal.example',
            'config' => ['password' => 'never-project-this-value'],
        ]);
        $attributes = [
            'name' => 'Site monitoring evidence',
            'scope_kind' => 'site',
            'site_id' => $site->id,
            'device_id' => null,
            'data_class' => null,
            'privacy_class' => null,
            'raw_days' => 14,
            'hourly_days' => 180,
            'daily_days' => 1825,
            'legal_hold' => false,
        ];

        $this->actingAs($admin)
            ->postJson('/security-devices/settings/monitoring/retention/preview', $attributes)
            ->assertOk()
            ->assertJsonStructure(['preview' => [
                'metric_series_candidates', 'snapshot_candidates', 'requires_confirmation',
                'legal_hold_removal', 'scope_changed',
            ]]);

        $this->actingAs($admin)
            ->postJson('/security-devices/settings/monitoring/retention', $attributes)
            ->assertUnprocessable();

        $policy = $this->actingAs($admin)
            ->postJson('/security-devices/settings/monitoring/retention', [
                ...$attributes,
                'confirmation' => MonitoringPolicyRules::RETENTION_CONFIRMATION,
                'reason' => 'Approved Site monitoring evidence retention policy.',
            ])
            ->assertCreated()
            ->json('policy');

        $this->actingAs($admin)
            ->postJson('/security-devices/settings/monitoring/retention/'.$policy['id'].'/deactivate', [
                'version' => 1,
                'reason' => 'The Site monitoring evidence policy is temporarily withdrawn.',
            ])
            ->assertOk()
            ->assertJsonPath('policy.version', 2)
            ->assertJsonPath('policy.state', 'inactive');

        $this->actingAs($admin)
            ->postJson('/security-devices/settings/monitoring/retention/'.$policy['id'].'/reactivate', [
                'version' => 2,
                'reason' => 'The approved Site monitoring evidence policy is active again.',
            ])
            ->assertUnprocessable();

        $this->actingAs($admin)
            ->postJson('/security-devices/settings/monitoring/retention/'.$policy['id'].'/reactivate', [
                'version' => 2,
                'reason' => 'The approved Site monitoring evidence policy is active again.',
                'confirmation' => MonitoringPolicyRules::RETENTION_CONFIRMATION,
            ])
            ->assertOk()
            ->assertJsonPath('policy.version', 3)
            ->assertJsonPath('policy.state', 'active');

        $this->actingAs($admin)
            ->get('/security-devices/settings')
            ->assertOk()
            ->assertInertia(function ($page): void {
                $workspace = $page->toArray()['props']['monitoringPolicyWorkspace'];
                $encoded = json_encode($workspace, JSON_THROW_ON_ERROR);
                $this->assertStringNotContainsString('do-not-project.internal.example', $encoded);
                $this->assertStringNotContainsString('never-project-this-value', $encoded);
                $this->assertArrayNotHasKey('target', $workspace['monitors'][0]);
                $this->assertArrayNotHasKey('config', $workspace['monitors'][0]);
            });
    }

    private function siteManager(Site $site): User
    {
        $manager = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $manager->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
        ]);
        foreach ([
            'securityDevices.viewAny',
            'securityDevices.devices.view',
            'securityDevices.monitoring.manage',
        ] as $key) {
            $permission = Permission::query()->where('key', $key)->firstOrFail();
            $manager->permissionOverrides()->attach($permission->id, ['allowed' => true]);
        }

        return $manager;
    }

    /** @return array{Site, Device} */
    private function deviceAt(Site $site): array
    {
        $device = Device::factory()->itInfrastructure()->create();
        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assignment_type' => AssignmentType::Permanent,
            'assigned_at' => now()->subMinute(),
        ]);

        return [$site, $device];
    }
}
