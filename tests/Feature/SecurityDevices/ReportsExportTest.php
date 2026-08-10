<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Models\DeviceMaintenanceRecord;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers ReportsController index + three CSV exports.
 *
 * Routes:
 *   GET /security-devices/reports
 *   GET /security-devices/reports/devices.csv
 *   GET /security-devices/reports/events.csv
 *   GET /security-devices/reports/maintenance.csv
 */
class ReportsExportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        // support_worker lacks securityDevices.reports.view.
        $this->viewer = User::factory()->create();
        $this->viewer->roles()->attach(Role::where('name', 'support_worker')->first());
    }

    public function test_reports_index_requires_reports_view_permission(): void
    {
        $this->actingAs($this->viewer)
            ->get('/security-devices/reports')
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->get('/security-devices/reports')
            ->assertOk();
    }

    public function test_devices_csv_has_bom_expected_header_and_matching_row_count(): void
    {
        Device::factory()->count(3)->create();

        $response = $this->actingAs($this->admin)
            ->get('/security-devices/reports/devices.csv');

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();

        // UTF-8 BOM must prefix the stream so Excel opens UTF-8 cleanly.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);

        $bomStripped = substr($content, 3);
        $lines = array_values(array_filter(explode("\n", trim($bomStripped))));

        // Header + 3 data rows.
        $this->assertCount(4, $lines);

        $header = $lines[0];
        foreach (['id', 'device_uid', 'name', 'domain', 'category', 'next_service_due'] as $column) {
            $this->assertStringContainsString($column, $header);
        }
    }

    public function test_events_csv_streams_with_correct_header(): void
    {
        $device = Device::factory()->create();
        DeviceEvent::create([
            'device_id' => $device->id,
            'event_type' => 'alarm_trigger',
            'severity' => 'critical',
            'source' => 'test',
            'occurred_at' => now()->subHours(2),
        ]);

        $response = $this->actingAs($this->admin)
            ->get('/security-devices/reports/events.csv');

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);

        $firstLine = strtok(substr($content, 3), "\n");
        foreach (['id', 'device_id', 'device_name', 'event_type', 'severity', 'occurred_at'] as $col) {
            $this->assertStringContainsString($col, $firstLine);
        }
    }

    public function test_maintenance_csv_streams_with_correct_header(): void
    {
        $device = Device::factory()->create();
        DeviceMaintenanceRecord::create([
            'device_id' => $device->id,
            'type' => 'preventive',
            'status' => 'scheduled',
            'description' => 'Quarterly service',
            'scheduled_for' => now()->addDays(7)->toDateString(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get('/security-devices/reports/maintenance.csv');

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);

        $firstLine = strtok(substr($content, 3), "\n");
        foreach (['id', 'device_id', 'device_name', 'type', 'scheduled_for', 'completed_at'] as $col) {
            $this->assertStringContainsString($col, $firstLine);
        }
    }

    public function test_exports_neutralise_spreadsheet_formula_prefixes_in_every_free_text_row(): void
    {
        $device = Device::factory()->create(['name' => '=2+3']);
        DeviceEvent::create([
            'device_id' => $device->id,
            'event_type' => 'alarm_trigger',
            'severity' => 'critical',
            'source' => '+CMD',
            'occurred_at' => now(),
        ]);
        DeviceMaintenanceRecord::create([
            'device_id' => $device->id,
            'type' => 'preventive',
            'status' => 'scheduled',
            'description' => '@SUM(1+1)',
            'scheduled_for' => now()->addDay()->toDateString(),
            'cost' => -12.5,
        ]);

        $inventory = $this->actingAs($this->admin)
            ->get('/security-devices/reports/devices.csv')
            ->streamedContent();
        $events = $this->actingAs($this->admin)
            ->get('/security-devices/reports/events.csv')
            ->streamedContent();
        $maintenance = $this->actingAs($this->admin)
            ->get('/security-devices/reports/maintenance.csv')
            ->streamedContent();

        $this->assertStringContainsString("'=2+3", $inventory);
        $this->assertStringContainsString("'=2+3", $events);
        $this->assertStringContainsString("'+CMD", $events);
        $this->assertStringContainsString("'=2+3", $maintenance);
        $this->assertStringContainsString("'@SUM(1+1)", $maintenance);
        $this->assertStringContainsString('-12.5', $maintenance);
        $this->assertStringNotContainsString("'-12.5", $maintenance);
    }

    public function test_exports_are_forbidden_without_reports_view_permission(): void
    {
        $this->actingAs($this->viewer)
            ->get('/security-devices/reports/devices.csv')
            ->assertForbidden();

        $this->actingAs($this->viewer)
            ->get('/security-devices/reports/events.csv')
            ->assertForbidden();

        $this->actingAs($this->viewer)
            ->get('/security-devices/reports/maintenance.csv')
            ->assertForbidden();
    }

    public function test_selected_device_export_uses_requested_rows_from_the_single_application_registry(): void
    {

        $selected = Device::factory()->create(['name' => 'Selected device']);
        Device::factory()->create(['name' => 'Unselected device']);
        $unrelated = Device::factory()->create(['name' => 'Unrelated device']);

        $response = $this->actingAs($this->admin)
            ->get("/security-devices/reports/devices.csv?ids={$selected->id},{$unrelated->id}");

        $response->assertOk();
        $content = $response->streamedContent();

        $this->assertStringContainsString('Selected device', $content);
        $this->assertStringNotContainsString('Unselected device', $content);
        $this->assertStringContainsString('Unrelated device', $content);
    }
}
