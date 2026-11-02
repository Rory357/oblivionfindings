<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Screens\Setup\SetupServerReadiness;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** S&D round 3 (pg-sdr-15q): "Sites the server checks itself", the old Central Site readiness in plain words. */
class SetupServerReadinessTest extends TestCase
{
    use RefreshDatabase;

    private Site $hubSite;

    private Site $noHub;

    private Site $hidden;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);
        $this->hubSite = Site::factory()->create(['name' => 'Aurora House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->noHub = Site::factory()->create(['name' => 'Tōtara House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->hidden = Site::factory()->create(['name' => 'Kōwhai Lodge', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->admin = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create(['user_id' => $this->admin->id, 'primary_site_id' => $this->hubSite->id, 'secondary_site_ids' => [$this->noHub->id], 'is_active' => true]);
        foreach (['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.integrations.view', 'settings.access.manage'] as $key) {
            $this->admin->permissionOverrides()->attach(Permission::query()->where('key', $key)->firstOrFail()->id, ['allowed' => true]);
        }
        MonitoringCollector::factory()->create([
            'site_id' => $this->hubSite->id, 'name' => 'Aurora House hub', 'collector_uuid' => (string) Str::uuid(),
            'status' => 'online', 'approved_at' => now()->subDays(3), 'revoked_at' => null, 'enrolled_at' => now()->subDays(4),
        ]);
        foreach ([$this->hubSite, $this->hidden] as $site) {
            $device = Device::factory()->itInfrastructure()->create(['name' => $site->name.' switch']);
            DeviceAssignment::create(['device_id' => $device->id, 'assignable_type' => DeviceAssignment::TARGET_SITE, 'assignable_id' => $site->id, 'assigned_at' => now()->subDays(3)]);
        }
    }

    public function test_each_of_the_viewers_sites_says_who_checks_it_in_plain_words(): void
    {
        $data = app(SetupServerReadiness::class)->present($this->admin->fresh());
        $rows = collect($data['rows'])->keyBy(fn (array $row): string => $row['site']['name']);

        $this->assertSame(['Tōtara House', 'Aurora House'], array_keys($rows->all()), 'Problems first, hub Sites last; another Site never shows.');
        $this->assertSame('Not set up', $rows['Tōtara House']['state']['label']);
        $this->assertSame('Nobody yet', $rows['Tōtara House']['checked_by']);
        $this->assertSame('Add a hub, or turn on server scans if the server can reach its network', $rows['Tōtara House']['next']);
        $this->assertSame('Checked by its hub', $rows['Aurora House']['state']['label']);
        $this->assertSame('Aurora House hub', $rows['Aurora House']['checked_by']);
        $this->assertSame(1, $rows['Aurora House']['devices']);
        $this->assertStringNotContainsString('Kōwhai Lodge', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('evidence', mb_strtolower(json_encode($data, JSON_THROW_ON_ERROR)));
    }

    public function test_setup_alerts_checks_tab_opens(): void
    {
        // Regression: the Checks table named its classes without their namespace and failed to load.
        $this->admin->permissionOverrides()->attach(Permission::query()->where('key', 'securityDevices.monitoring.manage')->firstOrFail()->id, ['allowed' => true]);

        $this->actingAs($this->admin->fresh())->get('/security-devices/setup/alerts?tab=checks')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('tab', 'checks')->has('checks.rows', 1));
    }

    public function test_only_admins_get_it_on_networks_and_scans(): void
    {
        $this->actingAs($this->admin)->get('/security-devices/setup/hubs?tab=networks')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('can.server_check', true));

        $viewer = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create(['user_id' => $viewer->id, 'primary_site_id' => $this->hubSite->id, 'secondary_site_ids' => [], 'is_active' => true]);
        foreach (['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.integrations.view'] as $key) {
            $viewer->permissionOverrides()->attach(Permission::query()->where('key', $key)->firstOrFail()->id, ['allowed' => true]);
        }
        $this->actingAs($viewer)->get('/security-devices/setup/hubs?tab=networks')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('can.server_check', false)->where('server_readiness', null));
    }
}
