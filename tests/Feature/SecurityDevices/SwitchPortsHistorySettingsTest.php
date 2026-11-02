<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringProfile;
use App\Domain\Monitoring\Models\MonitorObservation;
use App\Domain\SecurityDevices\Alerts\Models\DeviceAlert;
use App\Domain\SecurityDevices\Enums\RelationshipType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceRelationship;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S&D v13: a switch's Ports tab (pg-sdr-3d) and network gear's History (3e) and Settings (3f).
 * Only what's stored shows, another Site's devices and history never do, and each kind of
 * history keeps the permission of the tab it comes from.
 */
class SwitchPortsHistorySettingsTest extends TestCase
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

        $this->home = Site::factory()->create(['name' => 'Synthetic House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->other = Site::factory()->create(['name' => 'Synthetic Lodge', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->viewer = $this->viewerWith(['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.events.view']);
        $this->profile = MonitoringProfile::factory()->create(['interval_seconds' => 300]);
    }

    /** @param list<string> $keys */
    private function viewerWith(array $keys, ?string $name = null): User
    {
        $user = User::factory()->create(['approved_at' => now(), ...($name ? ['name' => $name] : [])]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $this->home->id,
            'secondary_site_ids' => [],
            'is_active' => true,
        ]);
        foreach ($keys as $key) {
            $user->permissionOverrides()->attach(Permission::query()->where('key', $key)->firstOrFail()->id, ['allowed' => true]);
        }

        return $user;
    }

    /** @param array<string, mixed> $attributes */
    private function device(Site $site, array $attributes = [], int $daysAgo = 3): Device
    {
        $device = Device::factory()->itInfrastructure()->create([
            'manufacturer' => 'Synthetic',
            'model' => 'Model 1',
            'provider' => 'manual',
            ...$attributes,
        ]);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assigned_at' => now()->subDays($daysAgo),
        ]);

        return $device;
    }

    /** One SNMP port check and its readings: errors climb from $errorsBefore to $errorsNow. */
    private function port(Device $switch, int $index, string $oper, int $errorsBefore, int $errorsNow, int $speedBps = 1_000_000_000): Monitor
    {
        $monitor = Monitor::factory()->create([
            'device_id' => $switch->id,
            'profile_id' => $this->profile->id,
            'kind' => MonitorKind::SnmpInterface,
            'name' => 'Port '.$index,
            'config' => ['if_index' => $index],
            'is_enabled' => true,
            'affects_availability' => false,
            'current_state' => $oper === 'up' ? MonitorState::Healthy : MonitorState::Failed,
            'last_state_changed_at' => now()->subHours(5),
        ]);
        foreach ([[$errorsBefore, 20], [$errorsNow, 1]] as [$errors, $hoursAgo]) {
            MonitorObservation::factory()->create([
                'monitor_id' => $monitor->id,
                'state' => $oper === 'up' ? MonitorState::Healthy : MonitorState::Failed,
                'latency_ms' => null,
                'metrics' => ['if_index' => $index, 'interface_name' => 'Port '.$index, 'admin_status' => 'up', 'operational_status' => $oper, 'speed_bps' => $speedBps, 'errors' => $errors],
                'observed_at' => now()->subHours($hoursAgo),
            ]);
        }

        return $monitor;
    }

    private function linkTo(Device $switch, Device $child, string $port): void
    {
        DeviceRelationship::query()->create([
            'parent_device_id' => $switch->id,
            'child_device_id' => $child->id,
            'relationship_type' => RelationshipType::ConnectedTo,
            'port' => $port,
            'created_by_user_id' => $this->viewer->id,
        ]);
    }

    public function test_only_switches_get_ports_and_only_network_gear_gets_history_and_settings(): void
    {
        $cases = [
            'switch' => [['subcategory' => 'switch'], ['ports', 'history', 'settings']],
            'router' => [['subcategory' => 'router'], ['history', 'settings']],
            'printer' => [['category' => 'printer', 'subcategory' => null], []],
        ];
        foreach ($cases as $kind => [$attributes, $expected]) {
            $device = $this->device($this->home, ['name' => 'Synthetic '.$kind, ...$attributes]);
            $this->actingAs($this->viewer)->get('/security-devices/devices/'.$device->id)
                ->assertOk()
                ->assertInertia(function ($page) use ($kind, $expected): void {
                    $tabs = collect($page->toArray()['props']['tabs'])->pluck('key')->all();
                    foreach (['ports', 'history', 'settings'] as $key) {
                        in_array($key, $expected, true) ? $this->assertContains($key, $tabs, $kind) : $this->assertNotContains($key, $tabs, $kind);
                    }
                    $page->where('ports', null)->where('history', null)->where('settings', null);
                });
        }
    }

    public function test_ports_show_whats_plugged_in_with_problems_first_and_never_another_sites_device(): void
    {
        $switch = $this->device($this->home, ['name' => 'Synthetic switch', 'subcategory' => 'switch']);
        $this->port($switch, 1, 'up', 0, 0);
        $this->port($switch, 4, 'up', 10, 224, 100_000_000);
        $this->port($switch, 6, 'down', 0, 0);
        $camera = $this->device($this->home, ['name' => 'Synthetic back yard camera'], 3);
        $this->linkTo($switch, $camera, 'Port 4');
        $theirs = $this->device($this->other, ['name' => 'Synthetic Lodge laptop']);
        $this->linkTo($switch, $theirs, '6');

        $response = $this->actingAs($this->viewer)->get('/security-devices/devices/'.$switch->id.'?tab=ports');
        $response->assertOk()->assertInertia(function ($page) use ($camera): void {
            $page->where('tab', 'ports')
                ->where('ports.total', 3)
                // Port 4's errors since the oldest reading in the last 24 hours, so it comes first.
                ->where('ports.rows.0.port', 4)
                ->where('ports.rows.0.errors', 214)
                ->where('ports.rows.0.problem', true)
                ->where('ports.rows.0.speed', '100 Mbps')
                ->where('ports.rows.0.device.id', $camera->id)
                ->where('ports.rows.0.device.name', 'Synthetic back yard camera')
                // Power per port is never read from SNMP: Not available, never 0.
                ->where('ports.rows.0.poe_w', null)
                ->where('ports.tally.poe_w', null)
                ->where('ports.tally.errors', 1)
                ->where('ports.tally.free', 1)
                ->where('ports.source.checks', 3)
                ->where('meters.2.key', 'ports')
                ->where('meters.2.tab', 'ports');
            $port6 = collect($page->toArray()['props']['ports']['rows'])->firstWhere('port', 6);
            $this->assertNull($port6['device']);
            $this->assertTrue($port6['free']);
        });
        $this->assertStringNotContainsString('Synthetic Lodge laptop', $response->getContent());
    }

    public function test_history_is_one_logbook_with_the_right_lines_for_each_permission(): void
    {
        $switch = Device::factory()->itInfrastructure()->create(['name' => 'Synthetic switch', 'subcategory' => 'switch', 'provider' => 'manual']);
        DeviceAssignment::create(['device_id' => $switch->id, 'assignable_type' => DeviceAssignment::TARGET_SITE, 'assignable_id' => $this->other->id, 'assigned_at' => now()->subDays(20), 'released_at' => now()->subDays(10)]);
        DeviceAssignment::create(['device_id' => $switch->id, 'assignable_type' => DeviceAssignment::TARGET_SITE, 'assignable_id' => $this->home->id, 'assigned_at' => now()->subDays(10)]);
        DeviceAlert::query()->create(['device_id' => $switch->id, 'site_id' => $this->home->id, 'kind' => 'offline', 'severity' => 'critical', 'title' => 'Synthetic switch is offline', 'state' => 'done', 'opened_at' => now()->subDays(2), 'closed_at' => now()->subDays(2)->addMinutes(38), 'close_reason' => 'recovered']);
        DeviceAlert::query()->create(['device_id' => $switch->id, 'site_id' => $this->other->id, 'kind' => 'offline', 'severity' => 'critical', 'title' => 'Lodge-era outage', 'state' => 'done', 'opened_at' => now()->subDays(15), 'closed_at' => now()->subDays(15), 'close_reason' => 'recovered']);

        // A rename through the device route the Settings tab saves with.
        $editor = $this->viewerWith(['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.events.view', 'securityDevices.devices.update'], 'Synthetic Editor');
        $this->actingAs($editor)->put('/security-devices/devices/'.$switch->id, [
            '_modal' => true,
            'name' => 'Synthetic house switch',
            'domain' => $switch->getRawOriginal('domain'),
            'category' => $switch->getRawOriginal('category'),
            'subcategory' => 'switch',
            'location_description' => 'Comms cupboard',
        ])->assertRedirect();

        $this->actingAs($this->viewer)->get('/security-devices/devices/'.$switch->id.'?tab=history')
            ->assertOk()
            ->assertInertia(function ($page): void {
                $page->where('tab', 'history')->where('history.noun', 'switch')->where('history.what', 'all');
                $lines = collect($page->toArray()['props']['history']['lines']);
                $titles = $lines->pluck('title')->all();
                $this->assertContains('Synthetic switch is offline', $titles);
                $this->assertContains('Moved to Synthetic House', $titles);
                $this->assertContains('Details changed', $titles);
                // From before it came here: never shown.
                $this->assertNotContains('Lodge-era outage', $titles);
                $this->assertNotContains('Added at Synthetic Lodge', $titles);
                $alert = $lines->firstWhere('title', 'Synthetic switch is offline');
                $this->assertSame('alerts', $alert['kind']);
                $this->assertStringStartsWith('Back by itself after 38 min', (string) $alert['sub']);
                $this->assertSame('Synthetic Editor', $lines->firstWhere('title', 'Details changed')['who']);
                $kinds = collect($page->toArray()['props']['history']['kinds'])->pluck('value')->all();
                $this->assertSame(['all', 'alerts', 'settings', 'quiet', 'moves'], $kinds);
            });

        // "What" narrows it to one kind.
        $this->actingAs($this->viewer)->get('/security-devices/devices/'.$switch->id.'?tab=history&what=moves')
            ->assertOk()
            ->assertInertia(function ($page): void {
                $page->where('history.what', 'moves');
                $this->assertSame(['moves'], collect($page->toArray()['props']['history']['lines'])->pluck('kind')->unique()->values()->all());
            });

        // Without events.view: no alert lines and no Alerts choice; moves stay.
        $limited = $this->viewerWith(['securityDevices.viewAny', 'securityDevices.devices.view']);
        $this->actingAs($limited)->get('/security-devices/devices/'.$switch->id.'?tab=history&what=alerts')
            ->assertOk()
            ->assertInertia(function ($page): void {
                $page->where('history.what', 'all');
                $this->assertSame(['all', 'moves'], collect($page->toArray()['props']['history']['kinds'])->pluck('value')->all());
                $this->assertNotContains('Synthetic switch is offline', collect($page->toArray()['props']['history']['lines'])->pluck('title')->all());
            });

        // Terminal sessions are for IT admins only: the choice appears only for them.
        $itAdmin = $this->viewerWith(['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.terminal.use']);
        $this->actingAs($itAdmin)->get('/security-devices/devices/'.$switch->id.'?tab=history')
            ->assertOk()
            ->assertInertia(fn ($page) => $this->assertContains('terminal', collect($page->toArray()['props']['history']['kinds'])->pluck('value')->all()));
    }

    public function test_settings_offer_only_what_saves_through_todays_routes(): void
    {
        $switch = $this->device($this->home, ['name' => 'Synthetic switch', 'subcategory' => 'switch', 'ip_address' => '10.9.0.2', 'location_description' => 'Comms cupboard', 'mac_address' => '74:ac:b9:00:00:01']);
        $ping = Monitor::factory()->create([
            'device_id' => $switch->id,
            'profile_id' => $this->profile->id,
            'kind' => MonitorKind::Icmp,
            'affects_availability' => true,
            'is_enabled' => true,
            'current_state' => MonitorState::Healthy,
        ]);
        $behind = $this->device($this->home, ['name' => 'Synthetic Wi-Fi']);
        $this->linkTo($switch, $behind, '7');

        $this->actingAs($this->viewer)->get('/security-devices/devices/'.$switch->id.'?tab=settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('tab', 'settings')
                ->where('settings.noun', 'switch')
                ->where('settings.name', 'Synthetic switch')
                ->where('settings.site', 'Synthetic House')
                ->where('settings.room', 'Comms cupboard')
                ->where('settings.address', '10.9.0.2')
                ->where('settings.watching.on', true)
                ->where('settings.watching.offline_alert', true)
                ->where('settings.watching.offline_ids', [$ping->id])
                ->where('settings.watching.stop_ids', [$ping->id])
                ->where('settings.watching.relied_on', true)
                ->where('settings.watching.dependents', 1)
                ->where('settings.about.model', 'Synthetic Model 1')
                // Only people who may edit devices get the values the rename sends back.
                ->where('settings.edit', null)
                ->where('can.edit', false)
                ->where('can.watch', false));

        $manager = $this->viewerWith(['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.devices.update', 'securityDevices.monitoring.manage']);
        $this->actingAs($manager)->get('/security-devices/devices/'.$switch->id.'?tab=settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('settings.edit.category', $switch->getRawOriginal('category'))
                ->where('can.edit', true)
                ->where('can.watch', true));

        // Stopping watching goes through each check's own route with a stored reason code.
        $this->actingAs($manager)
            ->postJson('/security-devices/monitoring/native-monitors/'.$ping->id.'/deactivate', ['reason_code' => 'obsolete'])
            ->assertOk();
        $this->assertFalse((bool) $ping->fresh()->is_enabled);
        $this->actingAs($manager)->get('/security-devices/devices/'.$switch->id.'?tab=settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('settings.watching.on', false)->where('device.state', 'not_watched'));
        $this->actingAs($manager)->get('/security-devices/devices?tab=upkeep&upkeep=not_watched')
            ->assertOk()
            ->assertInertia(function ($page): void {
                $row = collect($page->toArray()['props']['not_watched']['rows'])->firstWhere('name', 'Synthetic switch');
                $this->assertNotNull($row);
                $this->assertStringEndsWith('· no longer needed', $row['why']);
            });
        // Another Site's switch is still a 404 on every tab.
        $theirs = $this->device($this->other, ['name' => 'Synthetic Lodge switch', 'subcategory' => 'switch']);
        foreach (['ports', 'history', 'settings'] as $tab) {
            $this->actingAs($this->viewer)->get('/security-devices/devices/'.$theirs->id.'?tab='.$tab)->assertNotFound();
        }
    }
}
