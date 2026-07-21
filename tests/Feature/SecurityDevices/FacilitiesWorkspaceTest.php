<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorObservation;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Models\DeviceMaintenanceRecord;
use App\Models\Integration\Integration;
use App\Models\Integration\IntegrationSyncLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FacilitiesWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);

        $this->admin = User::factory()->create([
            'organization_id' => 42,
            'approved_at' => now(),
        ]);
        $this->admin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
    }

    public function test_overview_reconciles_distinct_facility_groups_sites_freshness_events_and_maintenance(): void
    {
        $site = Site::factory()->create(['tenant_id' => 42, 'name' => 'Kauri House']);
        $environment = $this->facilityDevice('Kauri fridge sensor', 'cold_chain', 'fridge_sensor');
        $building = $this->facilityDevice('Kauri fire panel', 'building_safety', 'fire_panel');
        $utility = $this->facilityDevice('Kauri generator', 'mechanical', 'generator_monitor');
        $automation = $this->facilityDevice('Kauri plant relay', 'facility_access', 'smart_relay', [
            'meta' => ['automation' => ['name' => 'Plant room ventilation', 'status' => 'success']],
        ]);
        $foreign = $this->facilityDevice('Foreign gas sensor', 'gas_detection', 'co_detector', ['tenant_id' => 77]);
        foreach ([$environment, $building, $utility, $automation] as $device) {
            $this->assignToSite($device, $site);
        }

        $sensor = $this->monitor($environment, 'Fridge temperature', MonitorState::Healthy);
        $this->observe($sensor, value: 4.5, unit: 'C');
        $this->monitor($utility, 'Generator availability', MonitorState::Degraded);
        DeviceEvent::create([
            'device_id' => $environment->id,
            'event_type' => 'temperature_threshold_exceeded',
            'severity' => 'critical',
            'source' => 'native',
            'occurred_at' => now()->subMinutes(10),
        ]);
        DeviceMaintenanceRecord::create([
            'device_id' => $building->id,
            'type' => 'inspection',
            'status' => 'scheduled',
            'description' => 'Canonical fire panel inspection',
            'scheduled_for' => now()->subDay()->toDateString(),
        ]);

        $this->actingAs($this->admin)
            ->get('/security-devices/facilities-iot')
            ->assertOk()
            ->assertInertia(function ($page) use ($foreign, $site): void {
                $facilities = $page->toArray()['props']['facilitiesWorkspace'];

                $this->assertSame([
                    'devices' => 5,
                    'environment' => 2,
                    'building_systems' => 1,
                    'utilities' => 1,
                    'automations' => 1,
                    'sites' => 1,
                ], $facilities['overview']['inventory']);
                $this->assertSame(1, $facilities['overview']['attention']['active_events']);
                $this->assertSame(1, $facilities['overview']['attention']['monitoring']);
                $this->assertSame(3, $facilities['overview']['attention']['unmonitored']);
                $this->assertSame(1, $facilities['overview']['attention']['overdue_maintenance']);
                $this->assertSame(1, $facilities['overview']['freshness']['fresh']);
                $this->assertSame(4, $facilities['overview']['freshness']['not_collected']);
                $this->assertSame($site->id, $facilities['overview']['sites'][0]['id']);
                $this->assertContains(
                    $foreign->id,
                    collect($facilities['activeTab']['devices'])->pluck('id')->all(),
                );
            });
    }

    public function test_environment_uses_allowlisted_observations_and_threshold_events_without_raw_payloads(): void
    {
        $device = $this->facilityDevice('Cool room sensor', 'cold_chain', 'cool_room_sensor', [
            'config' => ['provider_secret' => 'RAW-FACILITY-DEVICE-SECRET'],
            'meta' => ['clinical_payload' => 'RAW-FACILITY-META-SENTINEL'],
        ]);
        $monitor = $this->monitor($device, 'Cool room temperature', MonitorState::Healthy, [
            'config' => ['authorization' => 'RAW-FACILITY-MONITOR-SECRET'],
        ]);
        $this->observe($monitor, value: 3.2, unit: 'C', metrics: [
            'sensor_name' => 'Cool room probe',
            'provider_payload' => 'RAW-FACILITY-OBSERVATION-SENTINEL',
        ]);
        DeviceEvent::create([
            'device_id' => $device->id,
            'event_type' => 'temperature_threshold_exceeded',
            'severity' => 'warning',
            'payload' => ['private' => 'RAW-FACILITY-EVENT-SENTINEL'],
            'source' => 'native',
            'occurred_at' => now()->subMinutes(4),
        ]);

        $this->actingAs($this->admin)
            ->get('/security-devices/facilities-iot?tab=environment')
            ->assertOk()
            ->assertInertia(function ($page): void {
                $facilities = $page->toArray()['props']['facilitiesWorkspace'];
                $row = $facilities['activeTab']['environment'][0];

                $this->assertSame('3.2', $row['observation']['value']);
                $this->assertSame('C', $row['observation']['unit']);
                $this->assertSame('fresh', $row['freshness']['state']);
                $this->assertSame('temperature_threshold_exceeded', $row['thresholdEvent']['type']);
                $this->assertFalse($row['unmonitored']);

                $payload = json_encode($facilities, JSON_THROW_ON_ERROR);
                foreach ([
                    'RAW-FACILITY-DEVICE-SECRET',
                    'RAW-FACILITY-META-SENTINEL',
                    'RAW-FACILITY-MONITOR-SECRET',
                    'RAW-FACILITY-OBSERVATION-SENTINEL',
                    'RAW-FACILITY-EVENT-SENTINEL',
                ] as $sentinel) {
                    $this->assertStringNotContainsString($sentinel, $payload);
                }
            });
    }

    public function test_building_systems_summarise_canonical_maintenance_without_copying_private_work_notes(): void
    {
        $device = $this->facilityDevice('Main fire panel', 'building_safety', 'fire_panel');
        DeviceMaintenanceRecord::create([
            'device_id' => $device->id,
            'type' => 'inspection',
            'status' => 'scheduled',
            'description' => 'RAW-MAINTENANCE-DESCRIPTION-SENTINEL',
            'notes' => 'RAW-MAINTENANCE-NOTES-SENTINEL',
            'scheduled_for' => now()->addDays(3)->toDateString(),
        ]);

        $this->actingAs($this->admin)
            ->get('/security-devices/facilities-iot?tab=building-systems')
            ->assertOk()
            ->assertInertia(function ($page) use ($device): void {
                $facilities = $page->toArray()['props']['facilitiesWorkspace'];
                $row = $facilities['activeTab']['buildingSystems'][0];

                $this->assertSame(1, $row['maintenance']['openCount']);
                $this->assertSame(now()->addDays(3)->toDateString(), $row['maintenance']['nextDue']);
                $this->assertSame("/security-devices/maintenance?device_id={$device->id}", $row['maintenance']['href']);
                $payload = json_encode($facilities, JSON_THROW_ON_ERROR);
                $this->assertStringNotContainsString('RAW-MAINTENANCE-DESCRIPTION-SENTINEL', $payload);
                $this->assertStringNotContainsString('RAW-MAINTENANCE-NOTES-SENTINEL', $payload);
            });
    }

    public function test_utilities_and_automations_only_show_explicit_safe_integration_and_execution_evidence(): void
    {
        $utility = $this->facilityDevice('Backup generator', 'mechanical', 'generator_monitor', [
            'provider' => 'milesight',
        ]);
        $automation = $this->facilityDevice('Ventilation relay', 'facility_access', 'smart_relay', [
            'provider' => 'milesight',
            'config' => ['automation' => ['raw_command' => 'RAW-AUTOMATION-COMMAND-SENTINEL']],
            'meta' => [
                'automation' => [
                    'name' => 'Ventilation schedule',
                    'enabled' => true,
                    'status' => 'success',
                    'last_executed_at' => now()->subMinutes(20)->toIso8601String(),
                    'private_result' => 'RAW-AUTOMATION-RESULT-SENTINEL',
                ],
            ],
        ]);
        Integration::create([
            'tenant_id' => 42,
            'provider' => 'milesight',
            'display_name' => 'Milesight IoT',
            'status' => Integration::STATUS_ACTIVE,
            'capabilities' => ['environmental', 'event_stream', 'private_admin'],
            'config' => ['token' => 'RAW-INTEGRATION-CONFIG-SENTINEL'],
            'last_error' => 'RAW-INTEGRATION-ERROR-SENTINEL',
            'last_tested_at' => now()->subHour(),
        ]);
        IntegrationSyncLog::create([
            'tenant_id' => 42,
            'provider' => 'milesight',
            'action' => 'sync_health',
            'status' => IntegrationSyncLog::STATUS_SUCCESS,
            'items_processed' => 2,
            'started_at' => now()->subMinutes(15),
            'completed_at' => now()->subMinutes(14),
            'error_message' => 'RAW-SYNC-ERROR-SENTINEL',
        ]);

        $this->actingAs($this->admin)
            ->get('/security-devices/facilities-iot?tab=utilities')
            ->assertOk()
            ->assertInertia(function ($page) use ($utility): void {
                $facilities = $page->toArray()['props']['facilitiesWorkspace'];
                $row = $facilities['activeTab']['utilities'][0];

                $this->assertSame($utility->id, $row['id']);
                $this->assertSame('Milesight IoT', $row['integration']['name']);
                $this->assertSame(['environmental', 'event_stream'], $row['integration']['capabilities']);
                $this->assertSame('success', $row['integration']['lastSync']['status']);
                $this->assertStringNotContainsString('RAW-', json_encode($facilities, JSON_THROW_ON_ERROR));
            });

        $this->actingAs($this->admin)
            ->get('/security-devices/facilities-iot?tab=automations')
            ->assertOk()
            ->assertInertia(function ($page) use ($automation): void {
                $facilities = $page->toArray()['props']['facilitiesWorkspace'];
                $row = $facilities['activeTab']['automations'][0];

                $this->assertSame($automation->id, $row['id']);
                $this->assertSame('Ventilation schedule', $row['automation']['name']);
                $this->assertTrue($row['automation']['enabled']);
                $this->assertSame('success', $row['automation']['status']);
                $this->assertStringNotContainsString('RAW-', json_encode($facilities, JSON_THROW_ON_ERROR));
            });
    }

    public function test_history_filters_canonical_events_and_observations_and_gates_export(): void
    {
        $device = $this->facilityDevice('Leak detector', 'leak_detection', 'water_sensor');
        $monitor = $this->monitor($device, 'Leak sensor state', MonitorState::Healthy);
        $this->observe($monitor, value: 0, unit: 'boolean');
        DeviceEvent::create([
            'device_id' => $device->id,
            'event_type' => 'leak_threshold_exceeded',
            'severity' => 'critical',
            'source' => 'native',
            'occurred_at' => now()->subMinutes(2),
        ]);
        DeviceEvent::create([
            'device_id' => $device->id,
            'event_type' => 'sensor_restored',
            'severity' => 'info',
            'source' => 'native',
            'occurred_at' => now()->subMinute(),
        ]);

        $this->actingAs($this->admin)
            ->get('/security-devices/facilities-iot?tab=history&history_kind=events&severity=critical')
            ->assertOk()
            ->assertInertia(function ($page): void {
                $facilities = $page->toArray()['props']['facilitiesWorkspace'];
                $history = $facilities['activeTab']['history'];

                $this->assertCount(1, $history['events']);
                $this->assertSame('leak_threshold_exceeded', $history['events'][0]['type']);
                $this->assertSame([], $history['observations']);
                $this->assertSame('critical', $history['filters']['severity']);
                $this->assertStringContainsString('domain=facilities', $history['exportHref']);
                $this->assertStringContainsString('severity=critical', $history['exportHref']);
            });

        $this->denyPermissions($this->admin, [
            'securityDevices.events.view',
            'securityDevices.maintenance.view',
            'securityDevices.integrations.view',
            'securityDevices.reports.view',
        ]);

        $this->actingAs($this->admin->fresh())
            ->get('/security-devices/facilities-iot?tab=history')
            ->assertOk()
            ->assertInertia(function ($page): void {
                $facilities = $page->toArray()['props']['facilitiesWorkspace'];

                $this->assertFalse($facilities['permissions']['events']);
                $this->assertFalse($facilities['permissions']['maintenance']);
                $this->assertFalse($facilities['permissions']['integrations']);
                $this->assertFalse($facilities['permissions']['export']);
                $this->assertNull($facilities['overview']['attention']['active_events']);
                $this->assertNull($facilities['overview']['attention']['overdue_maintenance']);
                $this->assertSame([], $facilities['activeTab']['history']['events']);
                $this->assertNull($facilities['activeTab']['history']['exportHref']);
            });
    }

    public function test_facility_event_export_applies_workspace_domain_and_event_filters(): void
    {
        $facility = $this->facilityDevice('Filtered leak detector', 'leak_detection', 'water_sensor');
        $network = Device::factory()->itInfrastructure()->create([
            'tenant_id' => 42,
            'name' => 'Unrelated network device',
        ]);
        foreach ([
            [$facility, 'facility_critical_event', 'critical'],
            [$facility, 'facility_info_event', 'info'],
            [$network, 'network_critical_event', 'critical'],
        ] as [$device, $type, $severity]) {
            DeviceEvent::create([
                'device_id' => $device->id,
                'event_type' => $type,
                'severity' => $severity,
                'source' => 'native',
                'occurred_at' => now(),
            ]);
        }

        $response = $this->actingAs($this->admin)
            ->get('/security-devices/reports/events.csv?domain=facilities&severity=critical');

        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertStringContainsString('facility_critical_event', $content);
        $this->assertStringNotContainsString('facility_info_event', $content);
        $this->assertStringNotContainsString('network_critical_event', $content);
    }

    private function facilityDevice(
        string $name,
        string $category,
        string $subcategory,
        array $attributes = [],
    ): Device {
        return Device::factory()->facilities()->create([
            'tenant_id' => 42,
            'name' => $name,
            'category' => $category,
            'subcategory' => $subcategory,
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
        MonitorState $state,
        array $attributes = [],
    ): Monitor {
        return Monitor::factory()->create([
            'tenant_id' => 42,
            'device_id' => $device->id,
            'name' => $name,
            'kind' => MonitorKind::Provider,
            'current_state' => $state,
            'last_observation_at' => now(),
            ...$attributes,
        ]);
    }

    private function observe(
        Monitor $monitor,
        int|float $value,
        string $unit,
        array $metrics = [],
    ): MonitorObservation {
        return MonitorObservation::factory()->create([
            'tenant_id' => 42,
            'monitor_id' => $monitor->id,
            'state' => $monitor->current_state,
            'value' => $value,
            'unit' => $unit,
            'metrics' => $metrics,
            'observed_at' => now(),
            'ingested_at' => now(),
        ]);
    }

    /** @param array<int, string> $keys */
    private function denyPermissions(User $user, array $keys): void
    {
        $overrides = Permission::query()
            ->whereIn('key', $keys)
            ->pluck('id')
            ->mapWithKeys(fn (int $id) => [$id => ['allowed' => false]])
            ->all();
        $user->permissionOverrides()->syncWithoutDetaching($overrides);
    }
}
