<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Discovery\Models\DiscoveryCandidate;
use App\Domain\Monitoring\Discovery\Models\DiscoveryRun;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringProfile;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Enums\HealthStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** S&D rebuild C1: the Devices menu presenters (scope, tab counts, problems first, honest gaps). */
class DevicesMenuPresenterTest extends TestCase
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

        $this->home = Site::factory()->create(['name' => 'Aurora House']);
        $this->other = Site::factory()->create(['name' => 'Kōwhai Lodge']);
        $this->viewer = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->viewer->id,
            'primary_site_id' => $this->home->id,
            'secondary_site_ids' => [],
            'is_active' => true,
        ]);
        foreach (['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.integrations.view'] as $key) {
            $this->viewer->permissionOverrides()->attach(Permission::query()->where('key', $key)->firstOrFail()->id, ['allowed' => true]);
        }
        $this->profile = MonitoringProfile::factory()->create();
    }

    private function device(Site $site, array $attributes = [], ?MonitorState $check = MonitorState::Healthy, string $factory = 'itInfrastructure'): Device
    {
        $device = Device::factory()->{$factory}()->create($attributes);
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

    public function test_another_sites_devices_never_show_in_counts_cards_or_rows(): void
    {
        $this->device($this->home, ['name' => 'Aurora House switch']);
        $this->device($this->other, ['name' => 'Kōwhai Lodge router']);

        $this->actingAs($this->viewer)
            ->get('/security-devices/devices?layout=cards')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('security-devices/devices/index')
                ->where('tabs.0.key', 'all')
                ->where('tabs.0.count', 1)
                ->where('meters.online.count', 1)
                ->where('cards.groups.1.items.0.name', 'Aurora House switch')
                ->where('cards.groups.1.count', 1));

        $list = $this->actingAs($this->viewer)->get('/security-devices/devices?layout=list');
        $list->assertOk()->assertInertia(fn ($page) => $page->where('list.meta.total', 1)->where('list.rows.0.site', 'Aurora House'));
        $this->assertStringNotContainsString('Kōwhai Lodge router', $list->getContent());

        // Asking for the other Site by hand is refused, not quietly emptied.
        $this->actingAs($this->viewer)->get('/security-devices/devices?site='.$this->other->id)->assertNotFound();
    }

    public function test_search_matches_serial_numbers_but_never_another_sites_device(): void
    {
        $this->device($this->home, ['name' => 'Aurora House switch', 'serial_number' => 'SW-7741-AUR']);
        $this->device($this->home, ['name' => 'Aurora House access point', 'serial_number' => null, 'provider_observed_state' => ['serial_number' => ['value' => 'UNIFI-0099', 'source' => 'unifi']]]);
        $this->device($this->home, ['name' => 'Aurora House printer', 'serial_number' => 'PR-1000']);
        $this->device($this->other, ['name' => 'Kōwhai Lodge switch', 'serial_number' => 'SW-7741-KOW']);

        $rows = fn (string $q) => $this->actingAs($this->viewer)->get('/security-devices/devices?layout=list&q='.urlencode($q));

        // The recorded serial, in any case; the other Site's switch with the same prefix never shows.
        $response = $rows('sw-7741');
        $response->assertOk()->assertInertia(fn ($page) => $page->where('list.meta.total', 1)->where('list.rows.0.name', 'Aurora House switch'));
        $this->assertStringNotContainsString('Kōwhai Lodge switch', $response->getContent());
        // The serial a UniFi device reports.
        $rows('unifi-0099')->assertOk()->assertInertia(fn ($page) => $page->where('list.meta.total', 1)->where('list.rows.0.name', 'Aurora House access point'));
        // Names still match, and a % in the search is a plain character.
        $rows('printer')->assertOk()->assertInertia(fn ($page) => $page->where('list.meta.total', 1));
        $rows('sw-%')->assertOk()->assertInertia(fn ($page) => $page->where('list.meta.total', 0));
    }

    public function test_each_tab_carries_its_count_and_upkeep_has_none(): void
    {
        $this->device($this->home, ['name' => 'Hallway camera'], MonitorState::Healthy, 'security');
        $this->device($this->home, ['name' => 'Office switch', 'subcategory' => 'switch']);
        $this->device($this->home, ['name' => 'Lounge Wi-Fi']);

        $this->actingAs($this->viewer)
            ->get('/security-devices/devices')
            ->assertOk()
            ->assertInertia(function ($page): void {
                $tabs = collect($page->toArray()['props']['tabs'])->pluck('count', 'key');
                $this->assertSame(3, $tabs['all']);
                $this->assertSame(2, $tabs['network']);
                $this->assertSame(1, $tabs['cameras']);
                $this->assertSame(0, $tabs['trackers']);
                $this->assertNull($tabs['upkeep']);
            });
    }

    public function test_problems_come_first_and_cant_check_is_grey(): void
    {
        $this->device($this->home, ['name' => 'Aardvark printer']);
        $offline = $this->device($this->home, ['name' => 'Zebra router', 'status' => DeviceStatus::Offline], MonitorState::Failed);
        $behind = $this->device($this->home, ['name' => 'Staff office door']);
        Monitor::query()->where('device_id', $behind->id)->update([
            'current_state' => MonitorState::Failed->value,
            'effective_state' => MonitorState::Suppressed->value,
            'suppression_reason' => 'dependency',
            'root_cause_monitor_id' => Monitor::query()->where('device_id', $offline->id)->value('id'),
        ]);

        $this->actingAs($this->viewer)
            ->get('/security-devices/devices?layout=cards')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('cards.groups.0.key', 'attention')
                ->where('cards.groups.0.items.0.name', 'Zebra router')
                ->where('cards.groups.0.items.0.badge.tone', 'critical')
                ->where('cards.groups.0.items.1.name', 'Staff office door')
                ->where('cards.groups.0.items.1.badge.label', 'Can’t check')
                ->where('cards.groups.0.items.1.badge.tone', 'neutral')
                ->where('cards.groups.0.items.1.note.text', 'Zebra router is offline')
                ->where('cards.groups.1.items.0.name', 'Aardvark printer')
                ->where('meters.offline.count', 1)
                ->where('meters.cant_check.count', 1));

        $this->actingAs($this->viewer)
            ->get('/security-devices/devices?layout=list')
            ->assertInertia(fn ($page) => $page
                ->where('list.rows.0.name', 'Zebra router')
                ->where('list.rows.1.name', 'Staff office door')
                ->where('list.rows.2.name', 'Aardvark printer'));
    }

    public function test_missing_readings_and_history_are_not_available_never_zero(): void
    {
        $this->device($this->home, ['name' => 'Quiet sensor', 'battery_level' => null], null, 'facilities');
        $this->device($this->home, ['name' => 'Office printer']);

        $this->actingAs($this->viewer)
            ->get('/security-devices/devices?layout=cards')
            ->assertOk()
            ->assertInertia(function ($page): void {
                $props = $page->toArray()['props'];
                $printer = collect($props['cards']['groups'][1]['items'])->firstWhere('name', 'Office printer');
                $this->assertNull($printer['reading']);
                $this->assertSame(1, $props['cards']['not_watched']['count']);
                $this->assertStringContainsString('Quiet sensor', $props['cards']['not_watched']['names'][0]);
            });

        // Upkeep: no battery forecast or update source exists yet, and the page says so.
        $this->device($this->home, ['name' => 'Room 3 sensor', 'battery_level' => 12], MonitorState::Healthy, 'facilities');
        $this->actingAs($this->viewer)
            ->get('/security-devices/devices?tab=upkeep')
            ->assertInertia(fn ($page) => $page
                ->where('batteries.forecast', false)
                ->where('batteries.rows.0.name', 'Room 3 sensor')
                ->where('batteries.rows.0.battery', 12)
                ->where('batteries.rows.0.tone', 'critical'));
        $this->actingAs($this->viewer)
            ->get('/security-devices/devices?tab=upkeep&upkeep=updates')
            ->assertInertia(fn ($page) => $page->where('updates.source', false));
    }

    public function test_old_workspace_and_category_links_open_the_matching_devices_tab(): void
    {
        $this->actingAs($this->viewer)->get('/security-devices/cctv')->assertRedirect('/security-devices/devices?tab=cameras');
        $this->actingAs($this->viewer)->get('/security-devices/network-it?site_id='.$this->home->id)
            ->assertRedirect('/security-devices/devices?tab=network&site='.$this->home->id);
        $this->actingAs($this->viewer)->get('/security-devices/tracking')->assertRedirect('/security-devices/devices?tab=trackers');
        $this->actingAs($this->viewer)
            ->get('/security-devices/devices?domain=security&view=unmonitored')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('tab', 'alarms_doors')->where('filters.status', 'not_watched'));
    }

    public function test_found_devices_shows_one_scan_of_the_viewers_site_only(): void
    {
        $scope = DiscoveryScope::factory()->create(['site_id' => $this->home->id, 'cidrs' => ['10.20.0.0/24']]);
        $run = DiscoveryRun::factory()->create(['discovery_scope_id' => $scope->id, 'status' => 'completed', 'completed_at' => now()]);
        DiscoveryCandidate::factory()->create(['discovery_run_id' => $run->id]);
        $known = $this->device($this->home, ['name' => 'Office printer']);
        DiscoveryCandidate::factory()->create(['discovery_run_id' => $run->id, 'decision' => 'matched', 'canonical_device_id' => $known->id]);

        $this->actingAs($this->viewer)
            ->get('/security-devices/scans/'.$run->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('security-devices/devices/found')
                ->where('scan.site', 'Aurora House')
                ->where('scan.range', '10.20.0.0/24')
                ->where('counts.new', 1)
                ->where('counts.added', 1)
                ->has('rows', 1));

        $elsewhere = DiscoveryRun::factory()->create([
            'discovery_scope_id' => DiscoveryScope::factory()->create(['site_id' => $this->other->id])->id,
        ]);
        $this->actingAs($this->viewer)->get('/security-devices/scans/'.$elsewhere->id)->assertNotFound();
        $this->actingAs($this->viewer)->getJson('/security-devices/scans/'.$elsewhere->id.'/progress')->assertNotFound();
    }

    public function test_move_takes_the_device_and_its_room_to_the_new_site(): void
    {
        $admin = User::factory()->create(['approved_at' => now()]);
        $admin->roles()->attach(\App\Models\Role::query()->where('name', 'admin')->firstOrFail());
        $device = $this->device($this->home, ['name' => 'Moving switch', 'health_status' => HealthStatus::Healthy]);

        $this->actingAs($admin)
            ->postJson('/security-devices/devices/move', ['device_ids' => [$device->id], 'site_id' => $this->other->id, 'room' => 'Office'])
            ->assertOk()
            ->assertJsonPath('moved.0', $device->id);
        $this->assertSame(
            $this->other->id,
            (int) DeviceAssignment::query()->where('device_id', $device->id)->whereNull('released_at')->value('assignable_id'),
        );
        $this->assertSame('Office', $device->fresh()->location_description);
    }
}
