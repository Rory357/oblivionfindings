<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceGroup;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * S&D rebuild C6, the menu switch: every old Security & devices page view sends people to its new
 * home, keeps its old permission (nobody new gets a hint of the new page), and changes no data.
 * Health kit and today's device groups become a Devices filter, read-only.
 */
class RetiredPagesRedirectTest extends TestCase
{
    use RefreshDatabase;

    private Site $home;

    private Site $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);
        $this->home = Site::factory()->create(['name' => 'Aurora House']);
        $this->other = Site::factory()->create(['name' => 'Kōwhai Lodge']);
    }

    /** @param  list<string>  $keys */
    private function userWith(array $keys): User
    {
        $user = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $this->home->id,
            'secondary_site_ids' => [],
            'is_active' => true,
        ]);
        foreach (['securityDevices.viewAny', ...$keys] as $key) {
            $user->permissionOverrides()->attach(Permission::query()->where('key', $key)->firstOrFail()->id, ['allowed' => true]);
        }

        return $user;
    }

    private function deviceAt(Site $site, string $state, array $attributes = []): Device
    {
        $device = Device::factory()->{$state}()->create($attributes);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assigned_at' => now()->subDay(),
        ]);

        return $device;
    }

    public function test_the_five_workspaces_and_old_category_links_open_the_matching_devices_tab(): void
    {
        $viewer = $this->userWith(['securityDevices.devices.view']);
        $site = $this->home->id;

        $expected = [
            '/security-devices/network-it' => '/security-devices/devices?tab=network',
            '/security-devices/network-it?tab=management' => '/security-devices/devices?tab=network',
            '/security-devices/security' => '/security-devices/devices?tab=alarms_doors',
            '/security-devices/security?tab=cctv' => '/security-devices/devices?tab=cameras',
            '/security-devices/security?tab=alarms' => '/security-devices/devices?tab=alarms_doors',
            '/security-devices/healthcare' => '/security-devices/devices?tab=sensors&group=health',
            '/security-devices/healthcare?tab=client-devices' => '/security-devices/devices?tab=sensors&group=health',
            '/security-devices/healthcare?tab=calibration-maintenance' => '/security-devices/devices?tab=upkeep&group=health',
            '/security-devices/tracking' => '/security-devices/devices?tab=trackers',
            '/security-devices/tracking?tab=personal-safety' => '/security-devices/devices?tab=trackers',
            '/security-devices/facilities-iot' => '/security-devices/devices?tab=sensors',
            '/security-devices/alarms' => '/security-devices/devices?tab=alarms_doors',
            '/security-devices/cctv' => '/security-devices/devices?tab=cameras',
            '/security-devices/tracking-devices' => '/security-devices/devices?tab=trackers',
            '/security-devices/smart-iot-healthcare' => '/security-devices/devices?tab=sensors&group=health',
            '/security-devices/it-infrastructure' => '/security-devices/devices?tab=network',
            '/security-devices/facilities' => '/security-devices/devices?tab=sensors',
            // Site and search come along; the old names for them still work.
            "/security-devices/network-it?site_id={$site}&search=core" => "/security-devices/devices?tab=network&site={$site}&q=core",
            "/security-devices/cctv?site={$site}" => "/security-devices/devices?tab=cameras&site={$site}",
        ];

        foreach ($expected as $old => $new) {
            $this->actingAs($viewer)->get($old)->assertRedirect($new);
        }
    }

    public function test_workspace_tabs_with_a_closer_home_go_there(): void
    {
        $viewer = $this->userWith(['securityDevices.devices.view']);
        $device = $this->deviceAt($this->home, 'itInfrastructure');

        // "Global map → each Site's Network map".
        $this->actingAs($viewer)->get('/security-devices/network-it?tab=map')->assertRedirect('/security-devices/sites');
        $this->actingAs($viewer)->get('/security-devices/network-it?tab=map&site_id='.$this->home->id)
            ->assertRedirect('/security-devices/sites/'.$this->home->id.'?tab=network');
        // Phase H: doors, people and the door log live on Access; security events on Alerts.
        $this->actingAs($viewer)->get('/security-devices/security?tab=access-control')->assertRedirect('/security-devices/access');
        $this->actingAs($viewer)->get('/security-devices/access-control')->assertRedirect('/security-devices/access');
        $this->actingAs($viewer)->get('/security-devices/security?tab=events')->assertRedirect('/security-devices/alerts');
        $this->actingAs($viewer)->get('/security-devices/facilities-iot?tab=automations')->assertRedirect('/security-devices/alerts?tab=rules');
        // A device in context opens its own page, which checks its own permission and Site.
        $this->actingAs($viewer)->get('/security-devices/network-it?device_id='.$device->id)
            ->assertRedirect('/security-devices/devices/'.$device->id);
    }

    public function test_old_pages_keep_their_old_permission(): void
    {
        $this->get('/security-devices/network-it')->assertRedirect('/login');

        $noDevices = $this->userWith([]);
        foreach (['network-it', 'security', 'healthcare', 'tracking', 'facilities-iot', 'alarms', 'cctv', 'access-control', 'tracking-devices', 'smart-iot-healthcare', 'it-infrastructure', 'facilities'] as $path) {
            $this->actingAs($noDevices)->get('/security-devices/'.$path)->assertForbidden();
        }

        $devicesOnly = $this->userWith(['securityDevices.devices.view']);
        $this->actingAs($devicesOnly)->get('/security-devices/device-groups')->assertForbidden();
        $this->actingAs($devicesOnly)->get('/security-devices/command-batches/1')->assertForbidden();
        $this->actingAs($devicesOnly)->get('/security-devices/alerts-events')->assertForbidden();
        $this->actingAs($devicesOnly)->get('/security-devices/monitoring')->assertForbidden();
        $this->actingAs($devicesOnly)->get('/security-devices/maintenance')->assertForbidden();
        $this->actingAs($devicesOnly)->get('/security-devices/maintenance-health')->assertForbidden();
        $this->actingAs($devicesOnly)->get('/security-devices/integrations')->assertForbidden();
        $this->actingAs($devicesOnly)->get('/security-devices/discovery')->assertForbidden();
        $this->actingAs($devicesOnly)->get('/security-devices/settings')->assertForbidden();
    }

    public function test_alerts_upkeep_and_setup_pages_go_to_their_new_homes(): void
    {
        $events = $this->userWith(['securityDevices.events.view']);
        $this->actingAs($events)->get('/security-devices/alerts-events')->assertRedirect('/security-devices/alerts');
        $this->actingAs($events)->get('/security-devices/monitoring?site_id='.$this->home->id)->assertRedirect('/security-devices/alerts?site='.$this->home->id);
        // The monitor history and runtime-health reads stay (JSON).
        $this->actingAs($events)->getJson('/security-devices/runtime-health')->assertOk();

        $upkeep = $this->userWith(['securityDevices.maintenance.view']);
        $this->actingAs($upkeep)->get('/security-devices/maintenance')->assertRedirect('/security-devices/devices?tab=upkeep');
        $this->actingAs($upkeep)->get('/security-devices/maintenance-health?site_id='.$this->home->id)
            ->assertRedirect('/security-devices/devices?tab=upkeep&site='.$this->home->id);

        $services = $this->userWith(['securityDevices.integrations.view']);
        $this->actingAs($services)->get('/security-devices/integrations')->assertRedirect('/security-devices/setup/services?tab=connected');
        $this->actingAs($services)->get('/security-devices/discovery')->assertRedirect('/security-devices/setup/hubs?tab=networks');
        $this->actingAs($services)->get('/security-devices/supported-devices')->assertRedirect('/security-devices/setup/services?tab=supported');

        $reports = $this->userWith(['securityDevices.reports.view']);
        $this->actingAs($reports)->get('/security-devices/settings')->assertRedirect('/security-devices/setup');
        $this->actingAs($reports)->get('/security-devices/settings?tab=audit')->assertRedirect('/security-devices/setup/history');
        $this->actingAs($reports)->get('/security-devices/settings?tab=monitoring')->assertRedirect('/security-devices/setup/alerts');
        $this->actingAs($reports)->get('/security-devices/settings?tab=sources')->assertRedirect('/security-devices/setup/services?tab=logins');
    }

    public function test_old_settings_readers_still_reach_setup_change_history(): void
    {
        $reports = $this->userWith(['securityDevices.reports.view']);

        $this->actingAs($reports)->get('/security-devices/setup')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('security-devices/setup/index')
                ->where('auth.can.securityDevices.setupOpen', true));
        $this->actingAs($reports)->get('/security-devices/setup/history')->assertOk();
        // Nothing else opens for them.
        $this->actingAs($reports)->get('/security-devices/setup/services')->assertNotFound();

        $devicesOnly = $this->userWith(['securityDevices.devices.view']);
        $this->actingAs($devicesOnly)->get('/security-devices/devices')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('auth.can.securityDevices.setupOpen', false));
        $this->actingAs($devicesOnly)->get('/security-devices/setup')->assertForbidden();
    }

    public function test_device_group_pages_become_a_devices_filter_and_the_tables_are_untouched(): void
    {
        $manager = $this->userWith(['securityDevices.devices.view', 'securityDevices.groups.manage']);
        $group = DeviceGroup::create(['name' => 'Ground floor', 'type' => 'location']);
        $mine = $this->deviceAt($this->home, 'itInfrastructure', ['name' => 'Ground floor switch']);
        $group->devices()->attach($mine->id);
        $before = [DB::table('device_groups')->count(), DB::table('device_group_members')->count()];

        $this->actingAs($manager)->get('/security-devices/device-groups')->assertRedirect('/security-devices/devices');
        $this->actingAs($manager)->get('/security-devices/device-groups/create')->assertRedirect('/security-devices/devices');
        $this->actingAs($manager)->get('/security-devices/device-groups/'.$group->id)->assertRedirect('/security-devices/devices?group='.$group->id);
        $this->actingAs($manager)->get('/security-devices/device-groups/'.$group->id.'/edit')->assertRedirect('/security-devices/devices?group='.$group->id);

        $this->assertSame($before, [DB::table('device_groups')->count(), DB::table('device_group_members')->count()]);
    }

    public function test_the_group_filter_shows_health_kit_and_groups_only_from_devices_the_person_can_see(): void
    {
        $viewer = $this->userWith(['securityDevices.devices.view']);
        $pendant = $this->deviceAt($this->home, 'iotHealthcare', ['name' => 'Fall pendant']);
        $bedSensor = $this->deviceAt($this->home, 'iotHealthcare', ['name' => 'Bed sensor', 'category' => 'sensor', 'subcategory' => 'bed_sensor']);
        $this->deviceAt($this->home, 'facilities', ['name' => 'Kitchen fridge']);
        $switch = $this->deviceAt($this->home, 'itInfrastructure', ['name' => 'Office switch']);
        $hidden = $this->deviceAt($this->other, 'itInfrastructure', ['name' => 'Kōwhai switch']);

        $visibleGroup = DeviceGroup::create(['name' => 'Office kit', 'type' => 'functional']);
        $visibleGroup->devices()->attach([$switch->id, $hidden->id]);
        $hiddenGroup = DeviceGroup::create(['name' => 'Kōwhai only', 'type' => 'location']);
        $hiddenGroup->devices()->attach($hidden->id);

        $this->actingAs($viewer)->get('/security-devices/devices?layout=list&tab=sensors&group=health')
            ->assertOk()
            ->assertInertia(function ($page) use ($visibleGroup, $bedSensor): void {
                $props = $page->toArray()['props'];
                $this->assertSame('health', $props['filters']['group']);
                $this->assertSame(
                    [['value' => 'all', 'label' => 'Any group'], ['value' => 'health', 'label' => 'Health kit'], ['value' => (string) $visibleGroup->id, 'label' => 'Office kit']],
                    $props['groups'],
                );
                // The Sensors tab with Health kit: the bed sensor only (the fridge isn't health kit).
                $this->assertSame([$bedSensor->id], array_column($props['list']['rows'], 'id'));
                // The pendant is a care tracker, so the Trackers tab counts it under the same filter.
                $tabs = collect($props['tabs'])->pluck('count', 'key');
                $this->assertSame(2, $tabs['all']);
                $this->assertSame(1, $tabs['trackers']);
            });

        $this->actingAs($viewer)->get('/security-devices/devices?layout=list&group='.$visibleGroup->id)
            ->assertOk()
            ->assertInertia(function ($page) use ($switch, $pendant): void {
                $ids = array_column($page->toArray()['props']['list']['rows'], 'id');
                $this->assertSame([$switch->id], $ids);
                $this->assertNotContains($pendant->id, $ids);
            });

        // Another Site's group is never offered and, asked for by hand, is ignored.
        $response = $this->actingAs($viewer)->get('/security-devices/devices?layout=list&group='.$hiddenGroup->id);
        $response->assertOk()->assertInertia(fn ($page) => $page->where('filters.group', 'all'));
        $this->assertStringNotContainsString('Kōwhai only', $response->getContent());
        $this->assertStringNotContainsString('Kōwhai switch', $response->getContent());

        // The old domain link still lands on Health kit.
        $this->actingAs($viewer)->get('/security-devices/devices?domain=iot_healthcare')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('tab', 'sensors')->where('filters.group', 'health'));
    }

    public function test_connected_service_cards_open_their_settings_page_only_for_managers(): void
    {
        $manager = $this->userWith(['securityDevices.integrations.manage']);
        $this->actingAs($manager)->get('/security-devices/setup/services?tab=connected')
            ->assertOk()
            ->assertInertia(function ($page): void {
                $cards = collect($page->toArray()['props']['connected']['cards'])->keyBy('key');
                $this->assertSame('/security-devices/integrations/unifi', $cards['unifi']['href']);
                $this->assertSame('/security-devices/integrations/queclink', $cards['queclink']['href']);
            });

        $viewer = $this->userWith(['securityDevices.integrations.view']);
        $this->actingAs($viewer)->get('/security-devices/setup/services?tab=connected')
            ->assertOk()
            ->assertInertia(function ($page): void {
                foreach ($page->toArray()['props']['connected']['cards'] as $card) {
                    $this->assertNull($card['href'] ?? null);
                }
            });
    }

    public function test_server_check_card_is_offered_on_hubs_and_networks_to_admins_only(): void
    {
        $admin = $this->userWith(['securityDevices.integrations.view', 'settings.access.manage']);
        $this->actingAs($admin)->get('/security-devices/setup/hubs?tab=networks')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('can.server_check', true));
        // Not on the other Hubs & networks tab.
        $this->actingAs($admin)->get('/security-devices/setup/hubs?tab=broker')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('can.server_check', false)->where('server_check', null));

        $it = $this->userWith(['securityDevices.integrations.view']);
        $this->actingAs($it)->get('/security-devices/setup/hubs?tab=networks')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('can.server_check', false)->where('server_check', null));
    }
}
