<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Models\MonitoringProfile;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** S&D rebuild C4: the Security & devices home (scope, first-time steps, tabs). */
class OverviewScreenTest extends TestCase
{
    use RefreshDatabase;

    private Site $home;

    private Site $other;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);

        $this->home = Site::factory()->create(['name' => 'Aurora House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->other = Site::factory()->create(['name' => 'Kōwhai Lodge', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->viewer = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->viewer->id,
            'primary_site_id' => $this->home->id,
            'secondary_site_ids' => [],
            'is_active' => true,
        ]);
        foreach (['securityDevices.viewAny', 'securityDevices.devices.view'] as $key) {
            $this->viewer->permissionOverrides()->attach(Permission::query()->where('key', $key)->firstOrFail()->id, ['allowed' => true]);
        }
    }

    private function device(Site $site, string $name, MonitorState $check = MonitorState::Healthy): Device
    {
        $device = Device::factory()->itInfrastructure()->create(['name' => $name, 'subcategory' => 'switch']);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assigned_at' => now()->subDays(3),
        ]);
        Monitor::factory()->create([
            'device_id' => $device->id,
            'profile_id' => MonitoringProfile::factory()->create()->id,
            'current_state' => $check,
            'last_observation_at' => now()->subMinute(),
            'last_state_changed_at' => now()->subHours(2),
        ]);

        return $device;
    }

    public function test_a_new_organisation_sees_the_four_setup_steps(): void
    {
        $this->actingAs($this->viewer)
            ->get('/security-devices')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('security-devices/overview/index')
                ->where('first_time.steps.0.label', 'Add a hub')
                ->where('first_time.steps.0.state', 'now')
                ->where('overview', null)
                ->where('meters.online.of', 0));
    }

    public function test_the_home_counts_only_the_viewers_devices_and_lists_what_needs_attention(): void
    {
        $this->device($this->home, 'Aurora House switch', MonitorState::Failed);
        $this->device($this->other, 'Kōwhai Lodge router');
        MonitoringCollector::factory()->create([
            'site_id' => $this->home->id, 'name' => 'Aurora House', 'collector_uuid' => (string) Str::uuid(),
            'status' => 'online', 'approved_at' => now()->subDay(), 'revoked_at' => null,
        ]);

        $response = $this->actingAs($this->viewer)->get('/security-devices');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->where('first_time', null)
            ->where('meters.online.of', 1)
            ->where('meters.offline.count', 1)
            ->where('overview.attention.0.title', 'Aurora House switch is offline')
            ->where('overview.hubs.online', 1)
            ->where('overview.sites.0.name', 'Aurora House'));
        $this->assertStringNotContainsString('Kōwhai Lodge', $response->getContent());
    }

    public function test_another_sites_filter_is_refused_and_the_trends_tab_reads_only_its_own_data(): void
    {
        $this->actingAs($this->viewer)->get('/security-devices?site='.$this->other->id)->assertNotFound();

        $this->device($this->home, 'Aurora House switch');
        $this->actingAs($this->viewer)
            ->get('/security-devices?tab=trends')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('tab', 'trends')
                ->where('overview', null)
                ->where('trends.device_count', 1)
                ->where('trends.kinds.0.count', 1));
    }
}
