<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\ConfigurationSnapshot;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorObservation;
use App\Domain\Monitoring\Topology\Data\TopologyEvidence;
use App\Domain\Monitoring\Topology\Models\TopologyNode;
use App\Domain\Monitoring\Topology\Models\TopologySnapshot;
use App\Domain\Monitoring\Topology\Services\TopologySnapshotBuilder;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceRelationship;
use App\Models\Asset;
use App\Models\ItTicket;
use App\Models\ItTicketLink;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\Cursor;
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

    public function test_network_map_rejects_malformed_cursor_before_empty_scope_and_ignores_it_on_other_tabs(): void
    {
        $sentinel = 'SENTINEL_INVALID_TOPOLOGY_CURSOR';
        $this->actingAs($this->admin)->getJson('/security-devices/network-it?tab=map&topology_cursor='.$sentinel)
            ->assertStatus(400)->assertJsonPath('message', 'Topology history cursor is invalid.')
            ->assertDontSee($sentinel, false);
        $this->actingAs($this->admin)->getJson('/security-devices/network-it?tab=map&topology_cursor[id]=1')->assertStatus(400);
        $this->actingAs($this->admin)->getJson('/security-devices/network-it?tab=map&topology_cursor=')->assertStatus(400);
        $this->actingAs($this->admin)->get('/security-devices/network-it?tab=devices&topology_cursor[id]=1')->assertOk();
    }

    public function test_network_map_retains_explicit_reset_metadata_when_no_runtime_snapshots_are_visible(): void
    {
        $cursor = new Cursor(['captured_at' => '2026-10-08 00:00:00', 'id' => 999]);
        $this->actingAs($this->admin)->get('/security-devices/network-it?tab=map&topology_cursor='.$cursor->encode())
            ->assertOk()->assertInertia(function ($page): void {
                $history = $page->toArray()['props']['networkItWorkspace']['activeTab']['topology']['history'];
                $this->assertSame('latest', $history['pageKey']);
                $this->assertFalse($history['isOlderPage']);
                $this->assertTrue($history['positionReset']);
                $this->assertNull($history['previousHref']);
                $this->assertNull($history['nextHref']);
                $this->assertSame([], $history['entries']);
                $this->assertSame('/security-devices/network-it?tab=map', $history['latestHref']);
            });
        $this->actingAs($this->admin)->get('/security-devices/network-it?tab=map')->assertOk()->assertInertia(function ($page): void {
            $this->assertFalse($page->toArray()['props']['networkItWorkspace']['activeTab']['topology']['history']['positionReset']);
        });
    }

    public function test_network_map_pages_older_and_newer_snapshots_without_copying_request_queries(): void
    {
        $site = Site::factory()->create(['name' => 'Paged topology Site', 'is_active' => true, 'archived' => false]);
        $device = $this->networkDevice('Paged topology device');
        $this->assignToSite($device, $site);
        $snapshots = collect(range(1, 12))->map(fn () => TopologySnapshot::factory()->create([
            'site_id' => $site->id, 'source' => 'native:snmp', 'status' => 'completed', 'captured_at' => now(),
        ]));
        $firstIds = $snapshots->pluck('id')->reverse()->take(10)->values()->all();
        $olderIds = $snapshots->pluck('id')->reverse()->skip(10)->values()->all();
        $nextHref = null;
        $this->actingAs($this->admin)->get('/security-devices/network-it?tab=map&return_to=https%3A%2F%2Fexternal.example%2F')
            ->assertOk()->assertInertia(function ($page) use ($firstIds, &$nextHref): void {
                $topology = $page->toArray()['props']['networkItWorkspace']['activeTab']['topology'];
                $history = $topology['history'];
                $this->assertSame($firstIds, array_column($history['entries'], 'id'));
                $this->assertSame('latest', $history['pageKey']);
                $this->assertNull($history['previousHref']);
                $nextHref = $history['nextHref'];
                $this->assertStringStartsWith('/security-devices/network-it?tab=map&topology_cursor=', $nextHref);
                $this->assertStringNotContainsString('external', $nextHref);
                $this->assertStringNotContainsString('return_to', $nextHref);
            });
        $previousHref = null;
        $this->actingAs($this->admin)->get($nextHref)->assertOk()->assertInertia(function ($page) use ($olderIds, &$previousHref): void {
            $history = $page->toArray()['props']['networkItWorkspace']['activeTab']['topology']['history'];
            $this->assertSame($olderIds, array_column($history['entries'], 'id'));
            $this->assertTrue($history['isOlderPage']);
            $this->assertFalse($history['positionReset']);
            $this->assertNull($history['nextHref']);
            $previousHref = $history['previousHref'];
        });
        $this->actingAs($this->admin)->get($previousHref)->assertOk()->assertInertia(function ($page) use ($firstIds): void {
            $history = $page->toArray()['props']['networkItWorkspace']['activeTab']['topology']['history'];
            $this->assertSame($firstIds, array_column($history['entries'], 'id'));
            $this->assertSame('latest', $history['pageKey']);
            $this->assertFalse($history['isOlderPage']);
        });
    }

    public function test_map_filter_validation_is_generic_and_isolated_from_other_tabs(): void
    {
        foreach (['map_site_id=0', 'map_site_id[]=1', 'map_device_cursor=', 'map_device_cursor[]=1', 'map_search[]=name', 'map_search='.rawurlencode("name\nsecret"), 'map_search='.rawurlencode(str_repeat('ā', 201))] as $query) {
            $this->actingAs($this->admin)->getJson('/security-devices/network-it?tab=map&'.$query)
                ->assertStatus(400)->assertJsonPath('message', 'Network map filters are invalid.');
        }
        $device = $this->networkDevice('Other-tab inventory preserved');
        $this->actingAs($this->admin)->get('/security-devices/network-it?tab=devices&map_site_id[]=1&map_search[]=bad&map_device_cursor[]=bad')
            ->assertOk()->assertInertia(function ($page) use ($device): void {
                $active = $page->toArray()['props']['networkItWorkspace']['activeTab'];
                $this->assertSame([$device->id], array_column($active['devices'], 'id'));
                $this->assertArrayNotHasKey('inventory', $active['topology']);
            });
        $this->actingAs($this->admin)->get('/security-devices/network-it?tab=map&map_site_id=999999999')->assertNotFound();
    }

    public function test_map_inventory_pages_and_searches_full_current_approved_site_scope(): void
    {
        $site = Site::factory()->create(['name' => 'Inventory approved Site', 'is_active' => true, 'archived' => false]);
        $otherSite = Site::factory()->create(['name' => 'Other approved Site', 'is_active' => true, 'archived' => false]);
        $hiddenSite = Site::factory()->create(['name' => 'Hidden inventory Site', 'is_active' => true, 'archived' => false]);
        $viewer = User::factory()->create(['approved_at' => now()]);
        $viewer->permissionOverrides()->syncWithoutDetaching(Permission::query()->whereIn('key', ['securityDevices.viewAny', 'securityDevices.devices.view'])->get()->mapWithKeys(fn ($permission): array => [$permission->id => ['allowed' => true]]));
        $profile = HrEmployeeProfile::factory()->create(['user_id' => $viewer->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [$otherSite->id], 'is_active' => true]);
        $devices = collect(range(1, 105))->map(function (int $index) use ($site): Device {
            $device = $this->networkDevice($index === 105 ? 'Far %_! switch' : "Scoped inventory {$index}");
            $this->assignToSite($device, $site);

            return $device;
        });
        $other = $this->networkDevice('Other approved %_! switch');
        $this->assignToSite($other, $otherSite);
        $hidden = $this->networkDevice('Hidden matching %_! switch');
        $this->assignToSite($hidden, $hiddenSite);
        $unassigned = $this->networkDevice('Unassigned matching %_! switch');
        $healthcare = Device::factory()->iotHealthcare()->create(['name' => 'Healthcare matching %_! switch']);
        $this->assignToSite($healthcare, $site);
        $builder = app(TopologySnapshotBuilder::class);
        $before = $builder->build($site, [new TopologyEvidence('lldp', $devices[103]->id, $devices[104]->id, 'ethernet', '1', '2', 0.95, [])], source: 'native:snmp', sourceCheckpoint: 'inventory-http');
        // Retained records alone must not make a hidden device visible.
        TopologyNode::query()->create(['topology_snapshot_id' => $before->id, 'canonical_device_id' => $hidden->id, 'node_key_hash' => hash('sha256', 'hidden-inventory-node')]);
        $nextHref = null;
        $base = '/security-devices/network-it?tab=map&map_site_id='.$site->id;
        $this->actingAs($viewer)->get($base.'&return_to=https%3A%2F%2Fexternal.example')->assertOk()->assertInertia(function ($page) use ($devices, $site, $otherSite, &$nextHref): void {
            $active = $page->toArray()['props']['networkItWorkspace']['activeTab'];
            $map = $active['topology'];
            $meta = $map['inventory'];
            $this->assertSame($devices->take(100)->pluck('id')->all(), array_column($map['nodes'], 'id'));
            $this->assertSame(105, $meta['total']);
            $this->assertSame(100, $meta['shown']);
            $this->assertTrue($meta['isPartial']);
            $this->assertSame('partial', $map['state']);
            $this->assertSame(105, $active['inventoryTotal']);
            $this->assertSame(100, $active['inventoryShown']);
            $this->assertEqualsCanonicalizing([$site->id, $otherSite->id], array_column($meta['sites'], 'id'));
            $nextHref = $meta['nextHref'];
            $this->assertStringNotContainsString('external', $nextHref);
            $this->assertStringNotContainsString('return_to', $nextHref);
        });
        $this->actingAs($viewer)->get($nextHref)->assertOk()->assertInertia(function ($page) use ($devices, $site): void {
            $map = $page->toArray()['props']['networkItWorkspace']['activeTab']['topology'];
            $this->assertSame($devices->skip(100)->pluck('id')->all(), array_column($map['nodes'], 'id'));
            $this->assertSame(1, $map['edgeCount']);
            $this->assertSame(105, $map['inventory']['total']);
            $this->assertNull($map['inventory']['nextHref']);
            $this->assertStringContainsString('map_site_id='.$site->id, $map['history']['latestHref']);
            $this->assertStringContainsString('map_device_cursor=', $map['history']['latestHref']);
        });
        $this->actingAs($viewer)->get($base.'&map_search='.rawurlencode('  %_!  '))->assertOk()->assertInertia(function ($page) use ($devices, $hidden, $unassigned, $healthcare, $other): void {
            $map = $page->toArray()['props']['networkItWorkspace']['activeTab']['topology'];
            $this->assertSame([$devices->last()->id], array_column($map['nodes'], 'id'));
            $this->assertSame(1, $map['inventory']['total']);
            $this->assertSame('%_!', $map['inventory']['search']);
            $this->assertSame([$devices->last()->id], array_column($map['history']['entries'][0]['nodes'], 'id'));
            foreach ([$hidden, $unassigned, $healthcare, $other] as $denied) {
                $this->assertStringNotContainsString($denied->name, json_encode($map));
            }
        });
        $this->actingAs($viewer)->get('/security-devices/network-it?tab=map&map_site_id='.$hiddenSite->id)->assertNotFound();
        $this->actingAs($viewer)->get('/security-devices/devices/'.$hidden->id)->assertNotFound();
        // A valid position is only seek state: losing its Site grant rebuilds current visibility.
        $profile->update(['primary_site_id' => $otherSite->id, 'secondary_site_ids' => []]);
        $this->actingAs($viewer->fresh())->get($nextHref)->assertNotFound();
        $this->actingAs($viewer->fresh())->get('/security-devices/network-it?tab=map&map_device_cursor='.(new Cursor(['id' => $other->id]))->encode())->assertOk()->assertInertia(function ($page) use ($other): void {
            $map = $page->toArray()['props']['networkItWorkspace']['activeTab']['topology'];
            $this->assertSame([$other->id], array_column($map['nodes'], 'id'));
            $this->assertTrue($map['inventory']['positionReset']);
            $this->assertStringNotContainsString('map_device_cursor', $map['history']['latestHref']);
        });
    }

    public function test_selected_site_does_not_reuse_other_site_snapshots_for_ambiguous_custody(): void
    {
        $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $otherSite = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $ambiguous = $this->networkDevice('Ambiguous Site inventory');
        $this->assignToSite($ambiguous, $site);
        // The canonical schema allows one active assignment; an approved Asset link can still provide conflicting Site provenance.
        $asset = Asset::factory()->create(['site_id' => $otherSite->id, 'home_site_id' => $otherSite->id, 'client_id' => null, 'category' => 'IT Equipment', 'status' => 'active']);
        DeviceAssetLink::query()->create(['device_id' => $ambiguous->id, 'asset_id' => $asset->id, 'link_type' => 'installed_in', 'linked_at' => now()]);
        TopologySnapshot::factory()->create(['site_id' => $otherSite->id, 'source' => 'native:snmp', 'status' => 'completed', 'captured_at' => now()]);
        $this->actingAs($this->admin)->get('/security-devices/network-it?tab=map&map_site_id='.$site->id)->assertOk()->assertInertia(function ($page) use ($ambiguous): void {
            $map = $page->toArray()['props']['networkItWorkspace']['activeTab']['topology'];
            $this->assertSame([$ambiguous->id], array_column($map['nodes'], 'id'));
            $this->assertTrue($map['inventory']['isPartial']);
            $this->assertSame([], $map['snapshots']);
            $this->assertSame([], $map['history']['entries']);
        });
        $this->actingAs($this->admin)->get('/security-devices/network-it?tab=map&map_anchor_id='.$ambiguous->id)->assertNotFound();
    }

    public function test_current_connection_inputs_are_strict_map_only_and_reject_mixed_modes(): void
    {
        foreach (['map_anchor_id=0', 'map_anchor_id[]=1', 'map_neighbor_cursor=bad', 'map_anchor_id=1&map_neighbor_cursor=', 'map_anchor_id=1&map_search=name', 'map_anchor_id=1&map_device_cursor=', 'map_anchor_id=1&topology_cursor=bad'] as $query) {
            $this->actingAs($this->admin)->getJson('/security-devices/network-it?tab=map&'.$query)
                ->assertStatus(400)->assertJsonPath('message', 'Network map filters are invalid.');
        }
        $this->actingAs($this->admin)->get('/security-devices/network-it?tab=devices&map_anchor_id[]=1&map_neighbor_cursor[]=bad')->assertOk();
        $this->actingAs($this->admin)->get('/security-devices/network-it?tab=map&map_anchor_id=99999999')->assertNotFound();
    }

    public function test_current_connections_use_canonical_site_privacy_and_keep_empty_runtime_separate_from_manual(): void
    {
        $site = Site::factory()->create(['name' => 'Connection Site', 'is_active' => true, 'archived' => false]);
        $otherSite = Site::factory()->create(['name' => 'Inaccessible Connection Site', 'is_active' => true, 'archived' => false]);
        $viewer = User::factory()->create(['approved_at' => now()]);
        $viewer->permissionOverrides()->syncWithoutDetaching(Permission::query()->whereIn('key', ['securityDevices.viewAny', 'securityDevices.devices.view'])->get()->mapWithKeys(fn ($permission): array => [$permission->id => ['allowed' => true]]));
        HrEmployeeProfile::factory()->create(['user_id' => $viewer->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [], 'is_active' => true]);
        $anchor = $this->networkDevice('Current connection anchor');
        $this->assignToSite($anchor, $site);
        $neighbor = $this->networkDevice('Visible current neighbor');
        $this->assignToSite($neighbor, $site);
        $moved = $this->networkDevice('Moved neighbor sentinel');
        $this->assignToSite($moved, $site);
        $manualNeighbor = $this->networkDevice('Manual neighbor sentinel');
        $this->assignToSite($manualNeighbor, $site);
        $builder = app(TopologySnapshotBuilder::class);
        $builder->build($site, [
            new TopologyEvidence('lldp', $anchor->id, $neighbor->id, 'ethernet', 'Gi1', 'eth0', 0.95, []),
            new TopologyEvidence('lldp', $anchor->id, $moved->id, 'ethernet', 'Gi2', 'eth0', 0.95, []),
        ], source: 'native:snmp', sourceCheckpoint: 'connection-http');
        $moved->assignments()->active()->update(['released_at' => now()]);
        $this->assignToSite($moved, $otherSite);
        DeviceRelationship::query()->create(['parent_device_id' => $anchor->id, 'child_device_id' => $manualNeighbor->id, 'relationship_type' => 'uplinks_to', 'created_by_user_id' => $this->admin->id]);
        $href = '/security-devices/network-it?tab=map&map_anchor_id='.$anchor->id;
        $this->actingAs($viewer)->get($href.'&return_to=https%3A%2F%2Fexternal.example')->assertOk()->assertInertia(function ($page) use ($anchor, $neighbor, $site, $moved, $manualNeighbor): void {
            $map = $page->toArray()['props']['networkItWorkspace']['activeTab']['topology'];
            $meta = $map['exploration'];
            $this->assertEqualsCanonicalizing([$anchor->id, $neighbor->id], array_column($map['nodes'], 'id'));
            $this->assertSame(1, $map['edgeCount']);
            $this->assertSame($site->id, $meta['siteId']);
            $this->assertSame(1, $meta['neighborsTotal']);
            $this->assertSame(1, $meta['neighborsShown']);
            $this->assertSame('runtime_topology', $map['source']);
            $this->assertSame('partial', $map['state']);
            $this->assertArrayNotHasKey('inventory', $map);
            $this->assertSame([], $map['history']['entries']);
            $this->assertStringContainsString('map_site_id='.$site->id, $meta['refreshHref']);
            $this->assertStringNotContainsString('return_to', $meta['refreshHref']);
            $this->assertStringNotContainsString('external', $meta['refreshHref']);
            $this->assertStringNotContainsString($moved->name, json_encode($map));
            $this->assertStringNotContainsString($manualNeighbor->name, json_encode($map));
            foreach ($map['nodes'] as $node) {
                $this->assertStringContainsString('map_anchor_id='.$node['id'], $node['exploreHref']);
            }
        });
        $this->actingAs($viewer)->get('/security-devices/network-it?tab=map&map_anchor_id='.$moved->id)->assertNotFound();
        $this->actingAs($this->admin)->get('/security-devices/network-it?tab=map&map_anchor_id='.$anchor->id.'&map_site_id='.$otherSite->id)->assertNotFound();
        $neighbor->assignments()->active()->update(['released_at' => now()]);
        $this->assignToSite($neighbor, $otherSite);
        $this->actingAs($viewer)->get($href)->assertOk()->assertInertia(function ($page) use ($anchor): void {
            $map = $page->toArray()['props']['networkItWorkspace']['activeTab']['topology'];
            $this->assertSame([$anchor->id], array_column($map['nodes'], 'id'));
            $this->assertSame(0, $map['edgeCount']);
            $this->assertSame(0, $map['exploration']['neighborsTotal']);
            $this->assertSame('runtime_topology', $map['source']);
        });
        $fallback = $this->networkDevice('Independent manual anchor');
        $this->assignToSite($fallback, $otherSite);
        DeviceRelationship::query()->create(['parent_device_id' => $fallback->id, 'child_device_id' => $moved->id, 'relationship_type' => 'uplinks_to', 'created_by_user_id' => $this->admin->id]);
        $this->actingAs($this->admin)->get('/security-devices/network-it?tab=map&map_anchor_id='.$fallback->id)->assertOk()->assertInertia(function ($page) use ($fallback, $moved): void {
            $map = $page->toArray()['props']['networkItWorkspace']['activeTab']['topology'];
            $this->assertSame('manual_relationships', $map['source']);
            $this->assertEqualsCanonicalizing([$fallback->id, $moved->id], array_column($map['nodes'], 'id'));
            $this->assertSame(1, $map['edgeCount']);
        });
        HrEmployeeProfile::query()->where('user_id', $viewer->id)->firstOrFail()->update(['primary_site_id' => $otherSite->id, 'secondary_site_ids' => []]);
        $this->actingAs($viewer->fresh())->get($href)->assertNotFound();
    }

    public function test_connection_candidate_budget_returns_to_verified_site_map_with_generic_feedback(): void
    {
        $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $anchor = $this->networkDevice('Budget anchor');
        $this->assignToSite($anchor, $site);
        // Stock devices are currently visible to this admin, but do not supply any Site authority for the anchor.
        $neighbors = Device::factory()->count(1001)->itInfrastructure()->create();
        foreach ($neighbors as $neighbor) {
            DeviceRelationship::query()->create(['parent_device_id' => $anchor->id, 'child_device_id' => $neighbor->id, 'relationship_type' => 'uplinks_to', 'created_by_user_id' => $this->admin->id]);
        }
        $this->actingAs($this->admin)->get('/security-devices/network-it?tab=map&map_anchor_id='.$anchor->id.'&return_to=https%3A%2F%2Fexternal.example')
            ->assertRedirect('/security-devices/network-it?tab=map&map_site_id='.$site->id)
            ->assertSessionHas('error', 'Connection exploration is unavailable for this view. The site map is still available.');
        $this->actingAs($this->admin)->get('/security-devices/network-it?tab=map&map_anchor_id=99999999')->assertNotFound();
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
