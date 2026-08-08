<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\ConfigurationSnapshot;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorObservation;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceRelationship;
use App\Models\ItTicket;
use App\Models\ItTicketLink;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class NetworkItWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);

        $this->admin = User::factory()->create([

            'approved_at' => now(),
        ]);
        $this->admin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
    }

    public function test_overview_reconciles_sites_wan_monitoring_evidence_and_open_it_work(): void
    {
        $site = Site::factory()->create([
            'name' => 'Kauri House',
            'is_active' => true,
        ]);
        $gateway = $this->networkDevice('Kauri SD-WAN gateway', [
            'category' => 'network',
            'subcategory' => 'edge_router',
        ]);
        $switch = $this->networkDevice('Kauri core switch', [
            'category' => 'network',
            'subcategory' => 'managed_switch',
        ]);
        $unrelated = $this->networkDevice('Unrelated gateway', []);
        $this->assignToSite($gateway, $site);
        $this->assignToSite($switch, $site);

        $this->monitor($gateway, 'WAN availability', MonitorKind::Icmp, MonitorState::Healthy);
        $interface = $this->monitor($switch, 'Uplink Gi1/0/48', MonitorKind::SnmpInterface, MonitorState::Degraded);
        $this->observe($interface, [
            'interface_name' => 'Gi1/0/48',
            'in_utilization_pct' => 86,
            'out_utilization_pct' => 44,
        ]);

        DeviceRelationship::create([
            'parent_device_id' => $gateway->id,
            'child_device_id' => $switch->id,
            'relationship_type' => 'uplinks_to',
            'created_by_user_id' => $this->admin->id,
        ]);
        $ticket = ItTicket::factory()->create([
            'requester_user_id' => $this->admin->id,
            'title' => 'Investigate Kauri uplink capacity',
            'status' => 'open',
        ]);
        ItTicketLink::create([
            'ticket_id' => $ticket->id,
            'relationship' => 'affected_device',
            'linkable_type' => Device::class,
            'linkable_id' => $switch->id,
            'created_by_user_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->get('/security-devices/network-it')
            ->assertOk()
            ->assertInertia(function ($page) use ($unrelated, $site): void {
                $network = $page->toArray()['props']['networkItWorkspace'];

                $this->assertSame('Native monitoring, honest evidence', $network['boundary']['title']);
                $this->assertSame([
                    'devices' => 3,
                    'sites' => 1,
                    'wan_paths' => 2,
                    'monitored_devices' => 2,
                    'unmonitored_devices' => 1,
                ], $network['overview']['inventory']);
                $this->assertSame(1, $network['overview']['attention']['monitoring']);
                $this->assertSame(1, $network['overview']['attention']['capacity']);
                $this->assertSame(1, $network['overview']['attention']['open_work']);
                $this->assertSame($site->id, $network['overview']['sites'][0]['id']);
                $this->assertContains(
                    'Kauri SD-WAN gateway',
                    collect($network['overview']['wanPaths'])->pluck('name')->all(),
                );
                $this->assertSame('Investigate Kauri uplink capacity', $network['overview']['itWork'][0]['title']);
                $this->assertContains(
                    $unrelated->id,
                    collect($network['activeTab']['devices'])->pluck('id')->all(),
                );
            });
    }

    public function test_network_map_uses_only_known_visible_relationships_and_labels_partial_topology(): void
    {
        $site = Site::factory()->create(['name' => 'Miro House']);
        $gateway = $this->networkDevice('Miro edge gateway');
        $switch = $this->networkDevice('Miro switch');
        $accessPoint = $this->networkDevice('Miro access point');
        $unrelated = $this->networkDevice('Unrelated switch', []);
        foreach ([$gateway, $switch, $accessPoint] as $device) {
            $this->assignToSite($device, $site);
        }

        $known = DeviceRelationship::create([
            'parent_device_id' => $gateway->id,
            'child_device_id' => $switch->id,
            'relationship_type' => 'uplinks_to',
            'port' => 'WAN1',
            'notes' => 'RAW-TOPOLOGY-NOTES-SENTINEL',
            'created_by_user_id' => $this->admin->id,
        ]);
        DeviceRelationship::create([
            'parent_device_id' => $gateway->id,
            'child_device_id' => $unrelated->id,
            'relationship_type' => 'connected_to',
            'created_by_user_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->get('/security-devices/network-it?tab=map')
            ->assertOk()
            ->assertInertia(function ($page) use ($known): void {
                $network = $page->toArray()['props']['networkItWorkspace'];
                $topology = $network['activeTab']['topology'];

                $this->assertSame('partial', $topology['state']);
                $this->assertSame(4, $topology['nodeCount']);
                $this->assertSame(2, $topology['edgeCount']);
                $this->assertSame($known->id, $topology['edges'][0]['id']);
                $this->assertSame(1, $topology['unlinkedCount']);
                $this->assertStringContainsString('incomplete', $topology['label']);
                $this->assertStringNotContainsString(
                    'RAW-TOPOLOGY-NOTES-SENTINEL',
                    json_encode($network, JSON_THROW_ON_ERROR),
                );
            });
    }

    public function test_interfaces_and_capacity_use_an_allowlist_of_retained_observation_metrics(): void
    {
        $site = Site::factory()->create();
        $device = $this->networkDevice('Core switch');
        $this->assignToSite($device, $site);
        $interface = $this->monitor($device, 'WAN 1', MonitorKind::SnmpInterface, MonitorState::Healthy);
        $this->observe($interface, [
            'interface_name' => 'WAN 1',
            'if_index' => 7,
            'admin_status' => 'up',
            'operational_status' => 'up',
            'speed_bps' => 1_000_000_000,
            'in_bps' => 850_000_000,
            'out_bps' => 620_000_000,
            'in_utilization_pct' => 85,
            'out_utilization_pct' => 62,
            'errors' => 12,
            'discards' => 3,
            'snmp_community' => 'RAW-SNMP-SECRET-SENTINEL',
            'provider_payload' => ['private' => 'RAW-INTERFACE-PAYLOAD-SENTINEL'],
        ]);
        $this->networkDevice('Unmonitored printer');

        $this->actingAs($this->admin)
            ->get('/security-devices/network-it?tab=interfaces')
            ->assertOk()
            ->assertInertia(function ($page): void {
                $network = $page->toArray()['props']['networkItWorkspace'];
                $row = $network['activeTab']['interfaces'][0];

                $this->assertSame('WAN 1', $row['name']);
                $this->assertSame(7, $row['index']);
                $this->assertSame(85, $row['inUtilisation']);
                $this->assertSame(62, $row['outUtilisation']);
                $this->assertSame('warning', $row['capacityState']);
                $this->assertSame(1, $network['activeTab']['gaps']['devicesWithoutInterfaceEvidence']);
                $payload = json_encode($network, JSON_THROW_ON_ERROR);
                $this->assertStringNotContainsString('RAW-SNMP-SECRET-SENTINEL', $payload);
                $this->assertStringNotContainsString('RAW-INTERFACE-PAYLOAD-SENTINEL', $payload);
            });

        $this->actingAs($this->admin)
            ->get('/security-devices/network-it?tab=traffic-capacity')
            ->assertOk()
            ->assertInertia(function ($page): void {
                $network = $page->toArray()['props']['networkItWorkspace'];
                $row = $network['activeTab']['traffic'][0];

                $this->assertSame('WAN 1', $row['interface']);
                $this->assertSame(850000000, $row['inBps']);
                $this->assertSame(620000000, $row['outBps']);
                $this->assertSame('warning', $row['state']);
                $this->assertSame('retained_native_observation', $row['source']);
            });
    }

    public function test_services_show_monitor_state_coverage_and_dependency_context_without_raw_config(): void
    {
        $gateway = $this->networkDevice('Service gateway');
        $dependent = $this->networkDevice('Dependent switch');
        $this->monitor($gateway, 'Gateway availability', MonitorKind::Icmp, MonitorState::Healthy);
        $this->monitor($gateway, 'Client portal HTTPS', MonitorKind::Http, MonitorState::Failed, [
            'config' => ['authorization' => 'RAW-MONITOR-SECRET-SENTINEL'],
        ]);
        $this->monitor($gateway, 'Legacy DNS check', MonitorKind::Dns, MonitorState::Unknown, [
            'is_enabled' => false,
        ]);
        DeviceRelationship::create([
            'parent_device_id' => $gateway->id,
            'child_device_id' => $dependent->id,
            'relationship_type' => 'connected_to',
            'created_by_user_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->get('/security-devices/network-it?tab=services')
            ->assertOk()
            ->assertInertia(function ($page): void {
                $network = $page->toArray()['props']['networkItWorkspace'];
                $services = collect($network['activeTab']['services']);

                $this->assertCount(3, $services);
                $this->assertSame(2, $services->where('enabled', true)->count());
                $this->assertSame('failed', $services->firstWhere('name', 'Client portal HTTPS')['state']);
                $this->assertSame(1, $services->firstWhere('name', 'Gateway availability')['dependentCount']);
                $this->assertSame(1, $network['activeTab']['gaps']['devicesWithoutServiceChecks']);
                $this->assertStringNotContainsString(
                    'RAW-MONITOR-SECRET-SENTINEL',
                    json_encode($network, JSON_THROW_ON_ERROR),
                );
            });
    }

    public function test_configuration_and_firmware_are_read_only_and_only_show_supported_allowlisted_evidence(): void
    {
        $drifted = $this->networkDevice('Drifted firewall', [
            'firmware_version' => '1.4.0',
            'meta' => [
                'observed' => [
                    'configuration_hash' => 'observed-hash',
                    'configuration_at' => now()->subHour()->toISOString(),
                    'firmware_at' => now()->subHours(2)->toISOString(),
                ],
                'desired' => [
                    'configuration_hash' => 'desired-hash',
                    'firmware_version' => '1.5.0',
                ],
                'credentials' => 'RAW-DEVICE-SECRET-SENTINEL',
            ],
            'config' => ['private_blob' => 'RAW-CONFIG-PAYLOAD-SENTINEL'],
        ]);
        $unsupported = $this->networkDevice('Basic printer', [
            'firmware_version' => null,
            'meta' => null,
        ]);
        $snapshotSite = Site::factory()->create(['is_active' => true]);
        $this->assignToSite($drifted, $snapshotSite);
        $baselinePath = 'monitoring/configuration-snapshots/baseline.json.enc';
        $baseline = ConfigurationSnapshot::query()->create([
            'snapshot_uuid' => (string) Str::uuid(),
            'site_id' => $snapshotSite->id,
            'device_id' => $drifted->id,
            'source_kind' => 'provider',
            'source' => 'unifi',
            'storage_disk' => 'monitoring-snapshots',
            'storage_path' => $baselinePath,
            'storage_path_hash' => hash('sha256', $baselinePath),
            'storage_state' => 'available',
            'content_hash' => hash('sha256', 'baseline-content'),
            'configuration_hash' => hash('sha256', 'baseline-configuration'),
            'content_size' => 16,
            'mime_type' => 'application/json',
            'firmware_version' => '1.3.0',
            'captured_at' => now()->subDays(2),
            'diff_summary' => ['added' => [], 'removed' => [], 'changed' => [], 'truncated' => false],
        ]);
        $inventoryPath = 'monitoring/configuration-snapshots/inventory.json.enc';
        $inventory = ConfigurationSnapshot::query()->create([
            'snapshot_uuid' => (string) Str::uuid(),
            'site_id' => $snapshotSite->id,
            'device_id' => $drifted->id,
            'source_kind' => 'ssh',
            'source' => 'native_read_only_inventory',
            'storage_disk' => 'monitoring-snapshots',
            'storage_path' => $inventoryPath,
            'storage_path_hash' => hash('sha256', $inventoryPath),
            'storage_state' => 'available',
            'content_hash' => hash('sha256', 'inventory-content'),
            'configuration_hash' => hash('sha256', 'inventory-configuration'),
            'content_size' => 17,
            'mime_type' => 'application/json',
            'firmware_version' => '1.3.5',
            'captured_at' => now()->subDay(),
            'diff_summary' => ['added' => [], 'removed' => [], 'changed' => [], 'truncated' => false],
        ]);
        $otherSite = Site::factory()->create(['is_active' => true]);
        $otherSitePath = 'monitoring/configuration-snapshots/other-site.json.enc';
        $otherSiteSnapshot = ConfigurationSnapshot::query()->create([
            'snapshot_uuid' => (string) Str::uuid(),
            'site_id' => $otherSite->id,
            'device_id' => $drifted->id,
            'source_kind' => 'provider',
            'source' => 'unifi',
            'storage_disk' => 'monitoring-snapshots',
            'storage_path' => $otherSitePath,
            'storage_path_hash' => hash('sha256', $otherSitePath),
            'storage_state' => 'available',
            'content_hash' => hash('sha256', 'other-site-content'),
            'configuration_hash' => hash('sha256', 'other-site-configuration'),
            'content_size' => 18,
            'mime_type' => 'application/json',
            'firmware_version' => '1.3.8',
            'captured_at' => now(),
            'diff_summary' => ['added' => [], 'removed' => [], 'changed' => [], 'truncated' => false],
        ]);
        $latestPath = 'monitoring/configuration-snapshots/latest.json.enc';
        $latest = ConfigurationSnapshot::query()->create([
            'snapshot_uuid' => (string) Str::uuid(),
            'site_id' => $snapshotSite->id,
            'device_id' => $drifted->id,
            'source_kind' => 'provider',
            'source' => 'unifi',
            'storage_disk' => 'monitoring-snapshots',
            'storage_path' => $latestPath,
            'storage_path_hash' => hash('sha256', $latestPath),
            'storage_state' => 'available',
            'content_hash' => hash('sha256', 'latest-content'),
            'configuration_hash' => hash('sha256', 'latest-configuration'),
            'content_size' => 14,
            'mime_type' => 'application/json',
            'firmware_version' => '1.4.0',
            'captured_at' => now()->subHour(),
            'previous_snapshot_id' => $baseline->id,
            'diff_summary' => [
                'added' => ['configuration.services.https'],
                'removed' => [],
                'changed' => ['configuration.interfaces.wan.mtu'],
                'truncated' => false,
            ],
        ]);

        $this->actingAs($this->admin)
            ->get('/security-devices/network-it?tab=configuration-firmware')
            ->assertOk()
            ->assertInertia(function ($page) use ($baseline, $drifted, $inventory, $latest, $latestPath, $otherSiteSnapshot, $unsupported): void {
                $network = $page->toArray()['props']['networkItWorkspace'];
                $rows = collect($network['activeTab']['configuration']);
                $drift = $rows->firstWhere('deviceId', $drifted->id);
                $unknown = $rows->firstWhere('deviceId', $unsupported->id);

                $this->assertSame('drifted', $drift['configuration']['state']);
                $this->assertSame('update_available', $drift['firmware']['state']);
                $this->assertSame('observed-hash', $drift['configuration']['observedHash']);
                $this->assertSame('desired-hash', $drift['configuration']['desiredHash']);
                $this->assertSame('not_observed', $unknown['configuration']['state']);
                $this->assertSame('not_observed', $unknown['firmware']['state']);
                $this->assertSame($latest->id, $drift['latestSnapshot']['id']);
                $this->assertSame([$latest->id, $inventory->id, $baseline->id], collect($drift['snapshotHistory'])->pluck('id')->all());
                $this->assertNotContains($otherSiteSnapshot->id, collect($drift['snapshotHistory'])->pluck('id')->all());
                $this->assertSame(2, $drift['latestSnapshot']['diff']['count']);
                $this->assertSame('1.3.5', $drift['snapshotHistory'][1]['firmwareVersion']);
                $this->assertSame('1.3.0', $drift['snapshotHistory'][2]['firmwareVersion']);
                $this->assertFalse($drift['snapshotHistoryTruncated']);
                $this->assertStringContainsString('read-only', $network['boundary']['managementNote']);
                $payload = json_encode($network, JSON_THROW_ON_ERROR);
                $this->assertStringNotContainsString('RAW-DEVICE-SECRET-SENTINEL', $payload);
                $this->assertStringNotContainsString('RAW-CONFIG-PAYLOAD-SENTINEL', $payload);
                $this->assertStringNotContainsString($latestPath, $payload);
            });
    }

    public function test_it_work_is_permission_gated_without_falsely_reporting_zero(): void
    {
        $device = $this->networkDevice('Restricted work device');
        $ticket = ItTicket::factory()->create([
            'requester_user_id' => $this->admin->id,
            'title' => 'Private technical work',
            'status' => 'open',
        ]);
        ItTicketLink::create([
            'ticket_id' => $ticket->id,
            'relationship' => 'affected_device',
            'linkable_type' => Device::class,
            'linkable_id' => $device->id,
            'created_by_user_id' => $this->admin->id,
        ]);
        $denied = Permission::query()
            ->whereIn('key', ['it.view', 'it.tickets.view'])
            ->pluck('id')
            ->mapWithKeys(fn (int $id) => [$id => ['allowed' => false]])
            ->all();
        $this->admin->permissionOverrides()->syncWithoutDetaching($denied);

        $this->actingAs($this->admin->fresh())
            ->get('/security-devices/network-it')
            ->assertOk()
            ->assertInertia(function ($page): void {
                $network = $page->toArray()['props']['networkItWorkspace'];

                $this->assertFalse($network['permissions']['viewItWork']);
                $this->assertNull($network['overview']['attention']['open_work']);
                $this->assertSame([], $network['overview']['itWork']);
                $this->assertStringNotContainsString(
                    'Private technical work',
                    json_encode($network, JSON_THROW_ON_ERROR),
                );
            });
    }

    private function networkDevice(string $name, array $attributes = []): Device
    {
        return Device::factory()->itInfrastructure()->create([
            'name' => $name,
            ...$attributes,
        ]);
    }

    private function assignToSite(Device $device, Site $site): void
    {
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assignment_type' => 'permanent',
            'assigned_at' => now(),
        ]);
    }

    private function monitor(
        Device $device,
        string $name,
        MonitorKind $kind,
        MonitorState $state,
        array $attributes = [],
    ): Monitor {
        return Monitor::factory()->create([
            'device_id' => $device->id,
            'name' => $name,
            'kind' => $kind,
            'current_state' => $state,
            'last_observation_at' => now(),
            ...$attributes,
        ]);
    }

    private function observe(Monitor $monitor, array $metrics): MonitorObservation
    {
        return MonitorObservation::factory()->create([
            'monitor_id' => $monitor->id,
            'state' => $monitor->current_state,
            'metrics' => $metrics,
            'observed_at' => now(),
            'ingested_at' => now(),
        ]);
    }
}
