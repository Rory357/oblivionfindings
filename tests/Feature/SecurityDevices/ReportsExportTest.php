<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Models\DeviceMaintenanceRecord;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
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

    public function test_report_page_and_event_export_use_the_same_inclusive_past_window(): void
    {
        $this->travelTo(now()->startOfSecond());
        $device = Device::factory()->create();
        foreach (['window_start' => now()->subDays(90), 'window_end' => now(), 'too_old' => now()->subDays(90)->subSecond(), 'future' => now()->addSecond()] as $type => $time) {
            DeviceEvent::create(['device_id' => $device->id, 'event_type' => $type, 'severity' => 'info', 'source' => 'test', 'occurred_at' => $time]);
        }
        $this->actingAs($this->admin)->get('/security-devices/reports')->assertOk()->assertInertia(fn ($page) => $page
            ->component('security-devices/reports')->where('actor_id', $this->admin->id)->where('as_of', now()->toISOString())->where('windowDays', 90)->where('stats.events_90d', 2));
        $response = $this->get('/security-devices/reports/events.csv')->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('window_start', $csv);
        $this->assertStringContainsString('window_end', $csv);
        $this->assertStringNotContainsString('too_old', $csv);
        $this->assertStringNotContainsString('future', $csv);
        $this->assertCount(3, array_filter(explode("\n", trim(substr($csv, 3)))));
    }

    public function test_reports_preserve_current_device_scope_and_event_occurrence_custody(): void
    {
        $this->travelTo(now()->startOfSecond());
        $allowedSite = Site::factory()->create();
        $hiddenSite = Site::factory()->create();
        $this->viewer->forceFill(['approved_at' => now()])->save();
        HrEmployeeProfile::factory()->create(['user_id' => $this->viewer->id, 'primary_site_id' => $allowedSite->id, 'secondary_site_ids' => [], 'is_active' => true]);
        $permission = Permission::query()->where('key', 'securityDevices.reports.view')->firstOrFail();
        $this->viewer->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
        $visible = Device::factory()->create(['name' => 'Visible device']);
        $hidden = Device::factory()->create(['name' => 'Hidden device']);
        $moved = Device::factory()->create(['name' => 'Hidden renamed device', 'domain' => 'it_infrastructure']);
        foreach ([[$visible, $allowedSite], [$hidden, $hiddenSite]] as [$device, $site]) {
            DeviceAssignment::create(['device_id' => $device->id, 'assignable_type' => DeviceAssignment::TARGET_SITE, 'assignable_id' => $site->id, 'assigned_at' => now()->subDays(5)]);
            DeviceEvent::create(['device_id' => $device->id, 'event_type' => $device === $visible ? 'visible_event' : 'hidden_event', 'severity' => 'info', 'source' => 'test', 'occurred_at' => now()->subDay()]);
            DeviceMaintenanceRecord::create(['device_id' => $device->id, 'type' => 'inspection', 'status' => 'scheduled', 'description' => $device === $visible ? 'Visible work' : 'Hidden work']);
        }
        DeviceAssignment::create(['device_id' => $moved->id, 'assignable_type' => DeviceAssignment::TARGET_SITE, 'assignable_id' => $allowedSite->id, 'assigned_at' => now()->subDays(5), 'released_at' => now()->subHours(12)]);
        DeviceAssignment::create(['device_id' => $moved->id, 'assignable_type' => DeviceAssignment::TARGET_SITE, 'assignable_id' => $hiddenSite->id, 'assigned_at' => now()->subHours(12)]);
        DeviceEvent::create(['device_id' => $moved->id, 'event_type' => 'allowed_occurrence', 'severity' => 'info', 'source' => 'test', 'occurred_at' => now()->subDay()]);
        DeviceEvent::create(['device_id' => $moved->id, 'event_type' => 'hidden_occurrence', 'severity' => 'info', 'source' => 'test', 'occurred_at' => now()->subHour()]);
        $this->actingAs($this->viewer)->get('/security-devices/reports')->assertOk()->assertInertia(fn ($page) => $page->where('actor_id', $this->viewer->id)->where('stats.devices', 1)->where('stats.events_90d', 2)->where('stats.maintenance', 1));
        $inventory = $this->get('/security-devices/reports/devices.csv?ids='.$visible->id.','.$hidden->id.','.$moved->id)->assertOk()->streamedContent();
        $this->assertStringContainsString('Visible device', $inventory);
        $this->assertStringNotContainsString('Hidden device', $inventory);
        $this->assertStringNotContainsString('Hidden renamed device', $inventory);
        $maintenance = $this->get('/security-devices/reports/maintenance.csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('Visible work', $maintenance);
        $this->assertStringNotContainsString('Hidden work', $maintenance);
        $events = $this->get('/security-devices/reports/events.csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('visible_event', $events);
        $this->assertStringContainsString('allowed_occurrence', $events);
        $this->assertStringNotContainsString('hidden_event', $events);
        $this->assertStringNotContainsString('hidden_occurrence', $events);
        $this->assertStringNotContainsString('Hidden renamed device', $events);
        $rows = array_map(fn ($line) => str_getcsv($line, ',', '"', ''), array_filter(explode("\n", trim(substr($events, 3)))));
        $header = array_shift($rows);
        $mapped = array_map(fn ($row) => array_combine($header, $row), $rows);
        $old = collect($mapped)->firstWhere('event_type', 'allowed_occurrence');
        $this->assertSame((string) $moved->id, $old['device_id']);
        $this->assertSame('', $old['device_name']);
        $domain = $this->get('/security-devices/reports/events.csv?domain=it_infrastructure&device_id='.$moved->id)->assertOk()->streamedContent();
        $this->assertStringNotContainsString('allowed_occurrence', $domain);
        $this->assertStringNotContainsString('Hidden renamed device', $domain);

    }
}
