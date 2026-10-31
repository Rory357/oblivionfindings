<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Monitoring\Contracts\IcmpTransport;
use App\Domain\Monitoring\Data\AuthorizedProbeTarget;
use App\Domain\Monitoring\Data\IcmpTransportResult;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringProfile;
use App\Domain\SecurityDevices\Enums\AssignmentType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Client;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase1DeviceBasicsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);

        $this->admin = User::factory()->create(['approved_at' => now()]);
        $this->admin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
    }

    public function test_default_availability_profile_is_seeded_by_migration(): void
    {
        $profile = MonitoringProfile::query()
            ->where('name', MonitoringProfile::DEFAULT_AVAILABILITY_NAME)
            ->first();

        $this->assertNotNull($profile);
        $this->assertTrue((bool) $profile->is_active);
        $this->assertSame(60, (int) $profile->interval_seconds);
        $this->assertSame(3, (int) $profile->failure_confirmations);
        $this->assertSame(2, (int) $profile->recovery_confirmations);
    }

    public function test_register_and_edit_device_supports_site_assignment_hostname_and_non_site_guard(): void
    {
        $siteA = Site::factory()->create(['name' => 'Harbour House', 'is_active' => true]);
        $siteB = Site::factory()->create(['name' => 'Kauri Cottage', 'is_active' => true]);

        $createOptions = $this->actingAs($this->admin)
            ->getJson('/security-devices/devices/create')
            ->assertOk()
            ->json();

        $this->assertTrue($createOptions['can_assign']);
        $this->assertContains('Harbour House', array_column($createOptions['sites'], 'name'));

        $storeResponse = $this->actingAs($this->admin)
            ->post('/security-devices/devices', [
                'name' => 'Harbour Gateway',
                'domain' => 'it_infrastructure',
                'category' => 'network',
                'subcategory' => 'router',
                'manufacturer' => 'Ubiquiti',
                'model' => 'UX7',
                'site_id' => $siteA->id,
                'ip_address' => 'ux7-harbour.local',
                'notes' => 'Installed in comms cupboard',
            ]);

        $storeResponse->assertRedirect();

        $device = Device::query()->where('name', 'Harbour Gateway')->firstOrFail();
        $this->assertSame('ux7-harbour.local', $device->ip_address);
        $this->assertSame('Ubiquiti', $device->manufacturer);
        $this->assertSame('UX7', $device->model);

        $activeAssignment = $device->assignments()->active()->first();
        $this->assertNotNull($activeAssignment);
        $this->assertSame(DeviceAssignment::TARGET_SITE, $activeAssignment->assignable_type);
        $this->assertSame((int) $siteA->id, (int) $activeAssignment->assignable_id);

        // Transfer Site + update IP via Edit device
        $this->actingAs($this->admin)
            ->put("/security-devices/devices/{$device->id}", [
                'name' => 'Harbour Core Gateway',
                'domain' => 'it_infrastructure',
                'category' => 'network',
                'subcategory' => 'router',
                'manufacturer' => 'Ubiquiti',
                'model' => 'UniFi Express 7',
                'site_id' => $siteB->id,
                'ip_address' => '172.16.0.1',
                'notes' => 'Moved to Kauri Cottage rack',
            ])
            ->assertRedirect();

        $device->refresh();
        $this->assertSame('Harbour Core Gateway', $device->name);
        $this->assertSame('172.16.0.1', $device->ip_address);
        $transferred = $device->assignments()->active()->first();
        $this->assertSame((int) $siteB->id, (int) $transferred->assignable_id);

        // Release Site assignment by setting site_id to null
        $this->actingAs($this->admin)
            ->put("/security-devices/devices/{$device->id}", [
                'name' => 'Harbour Core Gateway',
                'domain' => 'it_infrastructure',
                'category' => 'network',
                'site_id' => null,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertNull($device->assignments()->active()->first());

        // Guard against overwriting an active Client assignment via Edit device site_id
        $client = Client::factory()->create(['site_id' => $siteA->id]);
        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_CLIENT,
            'assignable_id' => $client->id,
            'assignment_type' => AssignmentType::Permanent,
            'assigned_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->from("/security-devices/devices/{$device->id}")
            ->put("/security-devices/devices/{$device->id}", [
                'name' => 'Harbour Core Gateway',
                'domain' => 'it_infrastructure',
                'category' => 'network',
                'site_id' => $siteA->id,
            ])
            ->assertSessionHasErrors('site_id');
    }

    public function test_ping_now_returns_reply_latency_or_plain_failure_and_enforces_egress_policy(): void
    {
        $site = Site::factory()->create(['name' => 'Harbour House', 'is_active' => true]);
        $device = Device::factory()->itInfrastructure()->create([
            'name' => 'Harbour Switch',
            'ip_address' => '172.16.0.1',
        ]);
        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assignment_type' => AssignmentType::Permanent,
            'assigned_at' => now(),
        ]);

        $this->app->instance(IcmpTransport::class, new class implements IcmpTransport
        {
            public function probe(AuthorizedProbeTarget $target): IcmpTransportResult
            {
                if ($target->host === '172.16.0.1') {
                    return new IcmpTransportResult(true, 4, 0.0, 'reply');
                }

                return new IcmpTransportResult(false, null, 100.0, 'timeout');
            }
        });

        $this->actingAs($this->admin)
            ->postJson("/security-devices/devices/{$device->id}/ping")
            ->assertOk()
            ->assertJson([
                'reachable' => true,
                'target' => '172.16.0.1',
                'latency_ms' => 4,
                'reason_code' => 'reply',
            ]);

        $device->update(['ip_address' => '172.16.0.253']);

        $this->actingAs($this->admin)
            ->postJson("/security-devices/devices/{$device->id}/ping")
            ->assertOk()
            ->assertJson([
                'reachable' => false,
                'target' => '172.16.0.253',
                'reason_code' => 'timeout',
            ])
            ->assertJsonPath(
                'message',
                'No reply before the ping timed out. Check that the device is powered on, connected to the network, and reachable from this server.',
            );

        // Loopback / SSRF deny_cidrs must be blocked cleanly without probing
        $device->update(['ip_address' => '127.0.0.1']);

        $this->actingAs($this->admin)
            ->postJson("/security-devices/devices/{$device->id}/ping")
            ->assertOk()
            ->assertJson([
                'reachable' => false,
                'reason_code' => 'address_blocked',
            ]);
    }

    public function test_one_switch_offline_alert_provisions_scope_and_availability_monitor_and_toggles_cleanly(): void
    {
        $site = Site::factory()->create(['name' => 'Harbour House', 'is_active' => true]);
        $device = Device::factory()->itInfrastructure()->create([
            'name' => 'Harbour AP',
            'ip_address' => '172.16.0.45',
        ]);
        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assignment_type' => AssignmentType::Permanent,
            'assigned_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->postJson("/security-devices/devices/{$device->id}/offline-alert", [
                'enabled' => true,
            ])
            ->assertOk()
            ->assertJson([
                'enabled' => true,
            ]);

        $scope = DiscoveryScope::query()
            ->where('site_id', $site->id)
            ->where('name', 'Registered device hosts')
            ->first();
        $this->assertNotNull($scope);
        $this->assertContains('172.16.0.45/32', $scope->cidrs);

        $monitor = Monitor::query()
            ->where('device_id', $device->id)
            ->where('kind', MonitorKind::Icmp->value)
            ->where('affects_availability', true)
            ->first();
        $this->assertNotNull($monitor);
        $this->assertTrue((bool) $monitor->is_enabled);

        $this->actingAs($this->admin)
            ->postJson("/security-devices/devices/{$device->id}/offline-alert", [
                'enabled' => false,
            ])
            ->assertOk()
            ->assertJson([
                'enabled' => false,
            ]);

        $this->assertFalse((bool) $monitor->fresh()->is_enabled);
    }

    public function test_monitoring_workspace_exposes_always_visible_add_check_missing_reason_when_no_site_devices_exist(): void
    {
        $response = $this->actingAs($this->admin)
            ->get('/security-devices/monitoring')
            ->assertOk();

        $props = $response->viewData('page')['props'];
        $management = $props['workspace']['monitor_management'];

        $this->assertTrue($management['can_manage']);
        $this->assertSame([], $management['devices']);
        $this->assertNotEmpty($management['missing_reason']);
        $this->assertSame('/security-devices/devices', $management['fix_href']);
    }
}
