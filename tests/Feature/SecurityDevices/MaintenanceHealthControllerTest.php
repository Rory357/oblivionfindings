<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Enums\HealthStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceMaintenanceRecord;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaintenanceHealthControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $viewer;

    private User $noPerms;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        // Coordinator has maintenance.view but not maintenance.manage.
        $this->viewer = User::factory()->create();
        $this->viewer->roles()->attach(Role::where('name', 'coordinator')->first());

        $this->noPerms = User::factory()->create();
    }

    // ── Index page ────────────────────────────────────────────────

    public function test_index_requires_authentication(): void
    {
        $this->get('/security-devices/maintenance-health')->assertRedirect('/login');
    }

    public function test_index_requires_maintenance_view_permission(): void
    {
        $this->actingAs($this->noPerms)
            ->get('/security-devices/maintenance-health')
            ->assertForbidden();
    }

    public function test_index_accessible_with_view_permission(): void
    {
        $this->actingAs($this->viewer)
            ->get('/security-devices/maintenance-health')
            ->assertOk();
    }

    public function test_index_returns_expected_props(): void
    {
        $this->actingAs($this->admin)
            ->get('/security-devices/maintenance-health')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('security-devices/maintenance-health')
                ->has('stats')
                ->has('records.data')
                ->has('records.meta')
                ->has('attentionDevices')
                ->has('lowBatteryDevices')
                ->has('filters')
                ->has('can')
            );
    }

    public function test_index_stats_are_correct(): void
    {
        $device = Device::factory()->create();

        // Overdue maintenance.
        DeviceMaintenanceRecord::create([
            'device_id' => $device->id,
            'type' => 'inspection',
            'status' => 'scheduled',
            'description' => 'Overdue check',
            'scheduled_for' => now()->subDays(5),
        ]);

        // Upcoming maintenance.
        DeviceMaintenanceRecord::create([
            'device_id' => $device->id,
            'type' => 'firmware_update',
            'status' => 'scheduled',
            'description' => 'Next week update',
            'scheduled_for' => now()->addDays(3),
        ]);

        // Offline device.
        Device::factory()->create(['status' => DeviceStatus::Offline]);

        // Low battery device.
        Device::factory()->create(['battery_level' => 10, 'battery_updated_at' => now()]);

        $response = $this->actingAs($this->admin)->get('/security-devices/maintenance-health');

        $response->assertInertia(fn ($page) => $page
            ->where('stats.overdue', 1)
            // `scopeUpcoming` is "scheduled, in the future, within N days" — the
            // overdue record falls under `scopeOverdue`, not `scopeUpcoming`.
            ->where('stats.upcoming', 1)
            ->where('stats.offline', 1)
            ->where('stats.lowBattery', 1)
        );
    }

    public function test_index_shows_attention_devices(): void
    {
        Device::factory()->create(['health_status' => HealthStatus::Critical, 'status' => DeviceStatus::Active]);
        Device::factory()->create(['health_status' => HealthStatus::Healthy, 'status' => DeviceStatus::Active]);

        $response = $this->actingAs($this->admin)->get('/security-devices/maintenance-health');

        $response->assertInertia(fn ($page) => $page
            ->has('attentionDevices', 1) // only the critical one
        );
    }

    public function test_index_shows_low_battery_devices(): void
    {
        Device::factory()->create(['battery_level' => 15, 'battery_updated_at' => now(), 'status' => DeviceStatus::Active]);
        Device::factory()->create(['battery_level' => 80, 'battery_updated_at' => now()]);
        Device::factory()->create(); // no battery

        $response = $this->actingAs($this->admin)->get('/security-devices/maintenance-health');

        $response->assertInertia(fn ($page) => $page
            ->has('lowBatteryDevices', 1)
        );
    }

    public function test_maintenance_health_data_covers_the_single_application_registry_for_all_sites_users(): void
    {

        $primaryDevice = Device::factory()->create([
            'name' => 'Primary sensor',
            'health_status' => HealthStatus::Critical,
            'status' => DeviceStatus::Active,
            'battery_level' => 10,
            'battery_updated_at' => now(),
        ]);
        $unrelatedDevice = Device::factory()->create([
            'name' => 'Unrelated sensor',
            'health_status' => HealthStatus::Critical,
            'status' => DeviceStatus::Active,
            'battery_level' => 10,
            'battery_updated_at' => now(),
        ]);

        foreach ([$primaryDevice, $unrelatedDevice] as $device) {
            DeviceMaintenanceRecord::create([
                'device_id' => $device->id,
                'type' => 'inspection',
                'status' => 'scheduled',
                'description' => $device->name.' maintenance',
                'scheduled_for' => now()->subDay(),
            ]);
        }

        $response = $this->actingAs($this->admin)->get('/security-devices/maintenance-health');

        $response->assertInertia(function ($page) {
            $props = $page->toArray()['props'];

            $this->assertSame(2, $props['stats']['overdue']);
            $this->assertSame(2, $props['stats']['critical']);
            $this->assertEqualsCanonicalizing(
                ['Primary sensor maintenance', 'Unrelated sensor maintenance'],
                collect($props['records']['data'])->pluck('description')->all(),
            );
            $this->assertEqualsCanonicalizing(
                ['Primary sensor', 'Unrelated sensor'],
                collect($props['attentionDevices'])->pluck('name')->all(),
            );
            $this->assertEqualsCanonicalizing(
                ['Primary sensor', 'Unrelated sensor'],
                collect($props['lowBatteryDevices'])->pluck('name')->all(),
            );
        });
    }

    public function test_index_filters_by_status(): void
    {
        $device = Device::factory()->create();

        DeviceMaintenanceRecord::create([
            'device_id' => $device->id, 'type' => 'repair', 'status' => 'completed',
            'description' => 'Done', 'completed_at' => now(),
        ]);
        DeviceMaintenanceRecord::create([
            'device_id' => $device->id, 'type' => 'inspection', 'status' => 'scheduled',
            'description' => 'Pending', 'scheduled_for' => now()->addDays(7),
        ]);

        $response = $this->actingAs($this->admin)
            ->get('/security-devices/maintenance-health?status=completed');

        $response->assertInertia(fn ($page) => $page->has('records.data', 1));
    }

    public function test_index_search_by_description(): void
    {
        $device = Device::factory()->create();

        DeviceMaintenanceRecord::create([
            'device_id' => $device->id, 'type' => 'repair', 'status' => 'scheduled',
            'description' => 'Replace faulty sensor cable',
        ]);
        DeviceMaintenanceRecord::create([
            'device_id' => $device->id, 'type' => 'inspection', 'status' => 'scheduled',
            'description' => 'Routine annual check',
        ]);

        $response = $this->actingAs($this->admin)
            ->get('/security-devices/maintenance-health?search=sensor');

        $response->assertInertia(fn ($page) => $page->has('records.data', 1));
    }

    // ── Store ─────────────────────────────────────────────────────

    public function test_store_requires_manage_permission(): void
    {
        $device = Device::factory()->create();

        $this->actingAs($this->viewer)
            ->post("/security-devices/devices/{$device->id}/maintenance", [
                'type' => 'inspection',
                'description' => 'Test',
            ])
            ->assertForbidden();
    }

    public function test_store_creates_maintenance_record(): void
    {
        $device = Device::factory()->create();

        $this->actingAs($this->admin)
            ->post("/security-devices/devices/{$device->id}/maintenance", [
                'type' => 'firmware_update',
                'description' => 'Update to v3.2.1',
                'scheduled_for' => now()->addDays(7)->format('Y-m-d'),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('device_maintenance_records', [
            'device_id' => $device->id,
            'type' => 'firmware_update',
            'status' => 'scheduled',
            'description' => 'Update to v3.2.1',
        ]);
    }

    public function test_store_validates_required_fields(): void
    {
        $device = Device::factory()->create();

        $this->actingAs($this->admin)
            ->post("/security-devices/devices/{$device->id}/maintenance", [])
            ->assertSessionHasErrors(['type', 'description']);
    }

    public function test_store_validates_type(): void
    {
        $device = Device::factory()->create();

        $this->actingAs($this->admin)
            ->post("/security-devices/devices/{$device->id}/maintenance", [
                'type' => 'invalid_type',
                'description' => 'Test',
            ])
            ->assertSessionHasErrors(['type']);
    }

    public function test_non_completed_records_cannot_claim_completion_evidence(): void
    {
        $device = Device::factory()->create();

        $this->actingAs($this->admin)
            ->post("/security-devices/devices/{$device->id}/maintenance", [
                'type' => 'inspection',
                'status' => 'scheduled',
                'description' => 'Contradictory completion evidence',
                'completed_at' => now()->subHour()->toIso8601String(),
            ])
            ->assertSessionHasErrors('completed_at');

        $this->assertDatabaseMissing('device_maintenance_records', [
            'description' => 'Contradictory completion evidence',
        ]);
    }

    public function test_maintenance_mutations_allow_authorized_unassigned_stock(): void
    {

        $unrelatedDevice = Device::factory()->create([]);
        $unrelatedRecord = DeviceMaintenanceRecord::create([
            'device_id' => $unrelatedDevice->id,
            'type' => 'inspection',
            'status' => 'scheduled',
            'description' => 'Unrelated maintenance',
        ]);

        $this->actingAs($this->admin)
            ->post("/security-devices/devices/{$unrelatedDevice->id}/maintenance", [
                'type' => 'inspection',
                'description' => 'Should not be created',
            ])
            ->assertRedirect();

        $this->actingAs($this->admin)
            ->put("/security-devices/maintenance/{$unrelatedRecord->id}", [
                'description' => 'Should not be updated',
            ])
            ->assertRedirect();

        $this->actingAs($this->admin)
            ->post("/security-devices/maintenance/{$unrelatedRecord->id}/complete")
            ->assertRedirect();

        $unrelatedRecord->refresh();
        $this->assertSame('Should not be updated', $unrelatedRecord->description);
        $this->assertSame('completed', $unrelatedRecord->status);
        $this->assertDatabaseHas('device_maintenance_records', [
            'device_id' => $unrelatedDevice->id,
            'description' => 'Should not be created',
        ]);
    }

    // ── Update ────────────────────────────────────────────────────

    public function test_update_modifies_record(): void
    {
        $device = Device::factory()->create();
        $record = DeviceMaintenanceRecord::create([
            'device_id' => $device->id,
            'type' => 'inspection',
            'status' => 'scheduled',
            'description' => 'Original',
        ]);

        $this->actingAs($this->admin)
            ->put("/security-devices/maintenance/{$record->id}", [
                'description' => 'Updated description',
                'status' => 'in_progress',
            ])
            ->assertRedirect();

        $record->refresh();
        $this->assertEquals('Updated description', $record->description);
        $this->assertEquals('in_progress', $record->status);
    }

    public function test_update_requires_manage_permission(): void
    {
        $device = Device::factory()->create();
        $record = DeviceMaintenanceRecord::create([
            'device_id' => $device->id,
            'type' => 'inspection',
            'status' => 'scheduled',
            'description' => 'Test',
        ]);

        $this->actingAs($this->viewer)
            ->put("/security-devices/maintenance/{$record->id}", ['description' => 'New'])
            ->assertForbidden();
    }

    // ── Complete ──────────────────────────────────────────────────

    public function test_complete_marks_record_completed(): void
    {
        $device = Device::factory()->create();
        $record = DeviceMaintenanceRecord::create([
            'device_id' => $device->id,
            'type' => 'inspection',
            'status' => 'scheduled',
            'description' => 'Annual inspection',
            'scheduled_for' => now()->addDays(3),
        ]);

        $this->actingAs($this->admin)
            ->post("/security-devices/maintenance/{$record->id}/complete")
            ->assertRedirect();

        $record->refresh();
        $this->assertEquals('completed', $record->status);
        $this->assertNotNull($record->completed_at);
        $this->assertEquals($this->admin->id, $record->performed_by_user_id);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'security_devices.maintenance.completed',
            'auditable_id' => $record->id,
        ]);
    }

    public function test_completed_and_cancelled_maintenance_history_is_terminal_and_immutable(): void
    {
        $device = Device::factory()->create();
        $completed = DeviceMaintenanceRecord::create([
            'device_id' => $device->id,
            'type' => 'inspection',
            'status' => 'completed',
            'description' => 'Completed evidence',
            'completed_at' => now()->subHour(),
            'performed_by_user_id' => $this->admin->id,
        ]);
        $completedAt = $completed->completed_at;
        $cancelled = DeviceMaintenanceRecord::create([
            'device_id' => $device->id,
            'type' => 'repair',
            'status' => 'cancelled',
            'description' => 'Cancelled evidence',
        ]);

        $this->actingAs($this->admin)
            ->put("/security-devices/maintenance/{$completed->id}", [
                'status' => 'scheduled',
                'description' => 'Rewritten evidence',
            ])
            ->assertSessionHasErrors('status');
        $this->actingAs($this->admin)
            ->post("/security-devices/maintenance/{$completed->id}/complete")
            ->assertSessionHasErrors('status');
        $this->actingAs($this->admin)
            ->put("/security-devices/maintenance/{$cancelled->id}", [
                'status' => 'in_progress',
            ])
            ->assertSessionHasErrors('status');

        $this->assertSame('Completed evidence', $completed->fresh()->description);
        $this->assertTrue($completedAt->equalTo($completed->fresh()->completed_at));
        $this->assertSame('cancelled', $cancelled->fresh()->status);

        $mutationBlocked = false;
        try {
            $completed->refresh()->update(['notes' => 'Direct rewrite']);
        } catch (\UnexpectedValueException $exception) {
            $mutationBlocked = str_contains($exception->getMessage(), 'immutable');
        }
        $this->assertTrue($mutationBlocked);
    }

    public function test_complete_requires_manage_permission(): void
    {
        $device = Device::factory()->create();
        $record = DeviceMaintenanceRecord::create([
            'device_id' => $device->id,
            'type' => 'inspection',
            'status' => 'scheduled',
            'description' => 'Test',
        ]);

        $this->actingAs($this->viewer)
            ->post("/security-devices/maintenance/{$record->id}/complete")
            ->assertForbidden();
    }

    // ── Overdue calculation ───────────────────────────────────────

    public function test_overdue_flag_set_correctly(): void
    {
        $device = Device::factory()->create();

        DeviceMaintenanceRecord::create([
            'device_id' => $device->id, 'type' => 'inspection', 'status' => 'scheduled',
            'description' => 'Past due', 'scheduled_for' => now()->subDays(3),
        ]);
        DeviceMaintenanceRecord::create([
            'device_id' => $device->id, 'type' => 'inspection', 'status' => 'scheduled',
            'description' => 'Not yet due', 'scheduled_for' => now()->addDays(3),
        ]);

        $response = $this->actingAs($this->admin)->get('/security-devices/maintenance-health');

        $response->assertInertia(function ($page) {
            $data = $page->toArray()['props']['records']['data'];
            $overdue = collect($data)->where('is_overdue', true);
            $notOverdue = collect($data)->where('is_overdue', false);
            $this->assertCount(1, $overdue);
            $this->assertCount(1, $notOverdue);
        });
    }
}
