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

/** S&D rebuild C3: Sites and the Site page (scope, hub lines, honest flags, tabs). */
class SitesScreensTest extends TestCase
{
    use RefreshDatabase;

    private Site $home;

    private Site $other;

    private User $viewer;

    private MonitoringProfile $profile;

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
        $this->profile = MonitoringProfile::factory()->create();
    }

    private function device(Site $site, array $attributes = [], ?MonitorState $check = MonitorState::Healthy): Device
    {
        $device = Device::factory()->itInfrastructure()->create($attributes);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assigned_at' => now()->subDays(3),
        ]);
        if ($check !== null) {
            Monitor::factory()->create([
                'device_id' => $device->id,
                'profile_id' => $this->profile->id,
                'current_state' => $check,
                'last_observation_at' => now()->subMinute(),
                'last_state_changed_at' => now()->subHours(2),
            ]);
        }

        return $device;
    }

    private function hub(Site $site, string $status = 'online'): MonitoringCollector
    {
        return MonitoringCollector::factory()->create([
            'site_id' => $site->id, 'name' => $site->name, 'collector_uuid' => (string) Str::uuid(),
            'status' => $status, 'approved_at' => now()->subDay(), 'revoked_at' => null, 'last_seen_at' => now()->subHour(),
        ]);
    }

    public function test_sites_shows_only_the_viewers_sites_with_their_hub_in_plain_words(): void
    {
        $this->device($this->home, ['name' => 'Aurora House switch', 'subcategory' => 'switch']);
        $this->device($this->other, ['name' => 'Kōwhai Lodge router']);
        $this->hub($this->home);

        $response = $this->actingAs($this->viewer)->get('/security-devices/sites');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->component('security-devices/sites/index')
            ->has('cards', 1)
            ->where('cards.0.name', 'Aurora House')
            ->where('cards.0.flag.text', 'All online')
            ->where('cards.0.facts.0.text', '**1 of 1** online · checked {ago}')
            ->where('cards.0.facts.1.text', 'Own hub: **Aurora House hub**')
            ->where('meters.set_up.count', 1)
            ->where('meters.set_up.of', 1)
            ->where('meters.hubs.online', 1));
        $this->assertStringNotContainsString('Kōwhai Lodge', $response->getContent());
    }

    public function test_a_site_without_a_hub_is_not_set_up_and_a_downed_router_flags_it(): void
    {
        $this->device($this->home, ['name' => 'Aurora House router', 'subcategory' => 'router'], MonitorState::Failed);

        $this->actingAs($this->viewer)
            ->get('/security-devices/sites?tab=not_set_up')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('tabs.not_set_up', 1)
                ->where('tabs.attention', 1)
                ->where('cards.0.flag.text', 'Router offline')
                ->where('cards.0.facts.1.text', 'No hub watches this Site yet')
                ->where('meters.set_up.caption', 'Aurora House has no hub yet'));
    }

    public function test_another_sites_page_is_refused_and_the_viewers_own_page_opens(): void
    {
        // Refused as "not found", so another Site's existence isn't confirmed.
        $this->actingAs($this->viewer)->get('/security-devices/sites/'.$this->other->id)->assertNotFound();

        $this->device($this->home, ['name' => 'Aurora House switch', 'subcategory' => 'switch']);
        $this->hub($this->home);
        $this->actingAs($this->viewer)
            ->get('/security-devices/sites/'.$this->home->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('security-devices/sites/show')
                ->where('site.name', 'Aurora House')
                ->where('tab', 'overview')
                ->where('hub.label', 'Aurora House hub')
                ->where('hub.own', true)
                ->where('meters.online.count', 1)
                ->where('tabs.devices', 1)
                ->where('overview.states.0.state', 'online')
                ->where('devices', null)
                ->where('network', null));
    }

    public function test_when_the_hub_stops_answering_the_page_says_what_can_still_be_seen(): void
    {
        $this->device($this->home, ['name' => 'Aurora House switch', 'subcategory' => 'switch']);
        $this->hub($this->home, 'offline');

        $this->actingAs($this->viewer)
            ->get('/security-devices/sites/'.$this->home->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('flag.text', 'Hub not answering')
                ->where('hub.answering', false)
                ->where('overview.hub_down.hub', 'Aurora House hub'));
    }

    public function test_the_devices_tab_lists_only_this_sites_devices(): void
    {
        $this->device($this->home, ['name' => 'Aurora House switch', 'subcategory' => 'switch']);
        $this->device($this->other, ['name' => 'Kōwhai Lodge router']);

        $response = $this->actingAs($this->viewer)->get('/security-devices/sites/'.$this->home->id.'?tab=devices');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->where('tab', 'devices')
            ->has('devices', 1)
            ->where('devices.0.name', 'Aurora House switch')
            ->where('overview', null));
        $this->assertStringNotContainsString('Kōwhai Lodge router', $response->getContent());
    }
}
