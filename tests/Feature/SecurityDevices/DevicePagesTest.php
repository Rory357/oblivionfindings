<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Models\MonitoringProfile;
use App\Domain\Monitoring\Models\MonitorObservation;
use App\Domain\SecurityDevices\Enums\RelationshipType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceRelationship;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** S&D rebuild C2: one device page per kind for network gear, computers and other devices. */
class DevicePagesTest extends TestCase
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
        $this->profile = MonitoringProfile::factory()->create(['interval_seconds' => 60, 'failure_confirmations' => 3]);
    }

    /** @param list<string> $keys */
    private function viewerWith(array $keys): User
    {
        $user = User::factory()->create(['approved_at' => now()]);
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
    private function device(Site $site, array $attributes = [], string $factory = 'itInfrastructure'): Device
    {
        $device = Device::factory()->{$factory}()->create([
            'manufacturer' => 'Synthetic',
            'model' => 'Model 1',
            'provider' => 'manual',
            ...$attributes,
        ]);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assigned_at' => now()->subDays(3),
        ]);

        return $device;
    }

    private function ping(Device $device, MonitorState $state = MonitorState::Healthy, ?int $collectorId = null): Monitor
    {
        return Monitor::factory()->create([
            'device_id' => $device->id,
            'profile_id' => $this->profile->id,
            'collector_id' => $collectorId,
            'kind' => MonitorKind::Icmp,
            'affects_availability' => true,
            'is_enabled' => true,
            'current_state' => $state,
            'last_observation_at' => now()->subMinute(),
            'last_state_changed_at' => now()->subHours(2),
        ]);
    }

    public function test_another_sites_device_returns_404(): void
    {
        $theirs = $this->device($this->other, ['name' => 'Synthetic Lodge router', 'subcategory' => 'router']);

        $this->actingAs($this->viewer)->get('/security-devices/devices/'.$theirs->id)->assertNotFound();
        $this->actingAs($this->viewer)->get('/security-devices/devices/'.$theirs->id.'?tab=notes')->assertNotFound();
    }

    public function test_each_kind_gets_its_own_page(): void
    {
        $kinds = [
            'switch' => ['subcategory' => 'switch'],
            'router' => ['subcategory' => 'router'],
            'wifi_point' => ['subcategory' => 'wireless_ap'],
            'network_device' => ['category' => 'network', 'subcategory' => null],
            'computer' => ['category' => 'endpoint', 'subcategory' => 'workstation'],
            'printer' => ['category' => 'printer', 'subcategory' => null],
            'ups' => ['category' => 'power', 'subcategory' => 'ups'],
            'recorder' => ['subcategory' => 'nvr'],
            'sensor_gateway' => ['subcategory' => 'lorawan_gateway'],
            'other' => ['category' => 'other', 'subcategory' => null],
        ];

        foreach ($kinds as $kind => $attributes) {
            $device = $this->device($this->home, ['name' => 'Synthetic '.$kind, ...$attributes]);

            $this->actingAs($this->viewer)
                ->get('/security-devices/devices/'.$device->id)
                ->assertOk()
                ->assertInertia(function ($page) use ($kind, $device): void {
                    $page->component('security-devices/devices/device')
                        ->where('device.kind', $kind)
                        ->where('device.name', 'Synthetic '.$kind)
                        ->where('tab', 'overview')
                        ->where('device.back.href', fn (string $href): bool => str_starts_with($href, '/security-devices/devices'));
                    $props = $page->toArray()['props'];
                    $tabs = collect($props['tabs'])->pluck('key')->all();
                    $this->assertContains('checks', $tabs, $kind);
                    $this->assertContains('notes', $tabs, $kind);
                    // The terminal, firmware and port control are never offered here.
                    $this->assertNotContains('terminal', $tabs, $kind);
                    $this->assertIsArray($props['overview'], $kind);
                    $this->assertNull($props['checks'], $kind);
                    $this->assertSame((int) $device->id, $props['device']['id']);
                });
        }
    }

    public function test_safety_kit_kinds_and_management_links_keep_todays_page(): void
    {
        $camera = $this->device($this->home, ['name' => 'Synthetic camera'], 'security');
        $this->actingAs($this->viewer)
            ->get('/security-devices/devices/'.$camera->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('security-devices/devices/show'));

        // Today's governed actions still open on today's page until they have a new home.
        $switch = $this->device($this->home, ['name' => 'Synthetic switch', 'subcategory' => 'switch']);
        $this->actingAs($this->viewer)
            ->get('/security-devices/devices/'.$switch->id.'?section=management')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('security-devices/devices/show'));
    }

    public function test_numbers_come_from_its_checks_and_say_not_available_without_them(): void
    {
        $quiet = $this->device($this->home, ['name' => 'Synthetic quiet switch', 'subcategory' => 'switch', 'ip_address' => '10.9.0.2']);
        $this->actingAs($this->viewer)
            ->get('/security-devices/devices/'.$quiet->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('device.state', 'not_watched')
                ->where('meters.0.key', 'reply')
                ->where('meters.0.value', null)
                ->where('overview.trend', null)
                ->where('overview.availability', null)
                ->where('actions.ping', true));

        $watched = $this->device($this->home, ['name' => 'Synthetic busy switch', 'subcategory' => 'switch', 'ip_address' => '10.9.0.3']);
        $monitor = $this->ping($watched);
        foreach ([2, 3, 2] as $index => $latency) {
            MonitorObservation::factory()->create([
                'monitor_id' => $monitor->id,
                'state' => MonitorState::Healthy,
                'latency_ms' => $latency,
                'metrics' => $index === 2 ? ['uptime_seconds' => 3600] : [],
                'observed_at' => now()->subMinutes(30 - $index * 10),
            ]);
        }
        // Recorded before it came to this Site: never counted.
        MonitorObservation::factory()->create([
            'monitor_id' => $monitor->id,
            'state' => MonitorState::Failed,
            'latency_ms' => null,
            'observed_at' => now()->subDays(5),
        ]);

        $this->actingAs($this->viewer)
            ->get('/security-devices/devices/'.$watched->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('device.state', 'online')
                ->where('meters.0.value', '2 ms')
                ->where('meters.1.key', 'uptime')
                ->where('meters.1.from', fn (?string $from): bool => $from !== null)
                ->where('overview.availability.answered', 3)
                ->where('overview.availability.missed', 0)
                ->where('overview.trend.unit', 'ms'));

        $this->actingAs($this->viewer)
            ->get('/security-devices/devices/'.$watched->id.'?tab=checks')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('tab', 'checks')
                ->where('overview', null)
                ->where('checks.rows.0.title', 'Online')
                ->where('checks.rows.0.how', 'Ping from the Oblivion server')
                ->where('checks.rows.0.every', 'Every minute')
                ->where('checks.rows.0.alert_when', '3 missed in a row')
                ->where('checks.rows.0.result.label', 'Answered in 2 ms'));
    }

    public function test_checks_tab_needs_permission_to_see_checks(): void
    {
        $limited = $this->viewerWith(['securityDevices.viewAny', 'securityDevices.devices.view']);
        $printer = $this->device($this->home, ['name' => 'Synthetic printer', 'category' => 'printer']);

        $this->actingAs($limited)
            ->get('/security-devices/devices/'.$printer->id.'?tab=checks')
            ->assertOk()
            ->assertInertia(function ($page): void {
                $page->where('tab', 'overview')->where('checks', null)->where('can.see_events', false);
                $this->assertNotContains('checks', collect($page->toArray()['props']['tabs'])->pluck('key')->all());
            });
    }

    public function test_a_router_that_is_down_shows_what_sits_behind_it(): void
    {
        $router = $this->device($this->home, ['name' => 'Synthetic router', 'subcategory' => 'router', 'ip_address' => '10.9.0.1']);
        $this->ping($router, MonitorState::Failed);
        $switch = $this->device($this->home, ['name' => 'Synthetic switch', 'subcategory' => 'switch']);
        DeviceRelationship::query()->create([
            'parent_device_id' => $router->id,
            'child_device_id' => $switch->id,
            'relationship_type' => RelationshipType::UplinksTo,
        ]);
        $hidden = $this->device($this->other, ['name' => 'Synthetic Lodge switch', 'subcategory' => 'switch']);
        DeviceRelationship::query()->create([
            'parent_device_id' => $router->id,
            'child_device_id' => $hidden->id,
            'relationship_type' => RelationshipType::UplinksTo,
        ]);

        $response = $this->actingAs($this->viewer)->get('/security-devices/devices/'.$router->id);
        $response->assertOk()->assertInertia(fn ($page) => $page
            ->where('device.state', 'offline')
            ->where('trouble.behind_count', 1)
            ->where('trouble.behind.0.name', 'Synthetic switch')
            ->where('trouble.checked_by', 'The Oblivion server')
            ->where('meters.0.key', 'offline')
            ->where('meters.1.value', '1'));
        $this->assertStringNotContainsString('Synthetic Lodge switch', $response->getContent());
    }

    public function test_the_backup_action_reports_the_hub_routes_reason_when_unavailable(): void
    {
        $manager = User::factory()->create(['approved_at' => now(), 'two_factor_confirmed_at' => now()]);
        $manager->roles()->attach(Role::query()->where('name', 'facilities_manager')->firstOrFail());
        HrEmployeeProfile::factory()->create(['user_id' => $manager->id, 'primary_site_id' => $this->home->id, 'secondary_site_ids' => [], 'is_active' => true]);

        $router = $this->device($this->home, [
            'name' => 'Synthetic router', 'subcategory' => 'router', 'manufacturer' => 'MikroTik', 'model' => 'hAP ax3',
            'provider' => 'mikrotik', 'ip_address' => '10.9.0.1',
        ]);
        MonitoringCollector::factory()->create([
            'site_id' => $this->home->id, 'name' => 'Synthetic House', 'collector_uuid' => (string) Str::uuid(),
            'status' => 'offline', 'last_seen_at' => now()->subHour(), 'approved_at' => now()->subDay(), 'revoked_at' => null,
        ]);

        $this->actingAs($manager)
            ->get('/security-devices/devices/'.$router->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('security-devices/devices/device')
                ->where('actions.backup.available', false)
                ->where('actions.backup.reason', fn (string $reason): bool => str_contains($reason, 'Synthetic House hub isn’t connected right now'))
                ->has('actions.backup.ssh_key'));
    }
}
