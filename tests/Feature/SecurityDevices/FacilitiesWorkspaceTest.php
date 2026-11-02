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

            'approved_at' => now(),
        ]);
        $this->admin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
    }

    public function test_facility_event_export_applies_workspace_domain_and_event_filters(): void
    {
        $facility = $this->facilityDevice('Filtered leak detector', 'leak_detection', 'water_sensor');
        $network = Device::factory()->itInfrastructure()->create([
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
