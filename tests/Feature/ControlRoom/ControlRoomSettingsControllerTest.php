<?php

namespace Tests\Feature\ControlRoom;

use App\Models\ControlRoom\ConfigOption;
use App\Models\ControlRoom\MaintenanceWindow;
use App\Models\ControlRoom\SignalRule;
use App\Models\ControlRoom\TriageQueue;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ControlRoomSettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $supportWorker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RbacSeeder::class);

        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->supportWorker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $this->supportWorker->roles()->attach(Role::where('name', 'support_worker')->first());
    }

    public function test_index_requires_manage_permission(): void
    {
        $this->actingAs($this->supportWorker)
            ->get('/control-room/settings')
            ->assertForbidden();
    }

    public function test_index_renders_inertia_settings_page(): void
    {
        $this->actingAs($this->admin)
            ->get('/control-room/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('control-room/settings')
                ->has('signalRules')
                ->has('triageQueues')
                ->has('maintenanceWindows')
                ->has('configOptions')
            );
    }

    // ── Signal rules ─────────────────────────────────────

    public function test_store_signal_rule_creates_rule(): void
    {
        $this->actingAs($this->admin)
            ->post('/control-room/settings/rules', [
                'name' => 'Critical to T1',
                'priority' => 10,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('control_room_signal_rules', [
            'name' => 'Critical to T1',
            'priority' => 10,
        ]);
    }

    public function test_store_signal_rule_validates_required(): void
    {
        $this->actingAs($this->admin)
            ->post('/control-room/settings/rules', [])
            ->assertSessionHasErrors(['name', 'priority']);
    }

    public function test_delete_signal_rule_removes_record(): void
    {
        $rule = SignalRule::create([
            'name' => 'To delete',
            'priority' => 50,
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->delete("/control-room/settings/rules/{$rule->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('control_room_signal_rules', ['id' => $rule->id]);
    }

    // ── Triage queues ────────────────────────────────────

    public function test_store_queue_creates_record(): void
    {
        $this->actingAs($this->admin)
            ->post('/control-room/settings/queues', [
                'name' => 'Tier 1',
                'code' => 'tier-1',
                'tier' => 1,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('control_room_triage_queues', [
            'name' => 'Tier 1',
            'code' => 'tier-1',
        ]);
    }

    public function test_store_queue_rejects_duplicate_code(): void
    {
        TriageQueue::create([
            'name' => 'First',
            'code' => 'taken',
            'tier' => 1,
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->post('/control-room/settings/queues', [
                'name' => 'Duplicate',
                'code' => 'taken',
                'tier' => 2,
            ])
            ->assertSessionHasErrors('code');
    }

    // ── Maintenance windows ──────────────────────────────

    public function test_store_maintenance_window_schedules(): void
    {
        $this->actingAs($this->admin)
            ->post('/control-room/settings/maintenance', [
                'name' => 'Patch Tuesday',
                'starts_at' => now()->addHour()->toDateTimeString(),
                'ends_at' => now()->addHours(3)->toDateTimeString(),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('control_room_maintenance_windows', [
            'name' => 'Patch Tuesday',
            'status' => 'scheduled',
        ]);
    }

    public function test_store_maintenance_window_rejects_past_start(): void
    {
        $this->actingAs($this->admin)
            ->post('/control-room/settings/maintenance', [
                'name' => 'Past',
                'starts_at' => now()->subHour()->toDateTimeString(),
                'ends_at' => now()->addHour()->toDateTimeString(),
            ])
            ->assertSessionHasErrors('starts_at');
    }

    public function test_cancel_maintenance_window_marks_cancelled(): void
    {
        $window = MaintenanceWindow::create([
            'name' => 'To cancel',
            'starts_at' => now()->addHour(),
            'ends_at' => now()->addHours(2),
            'status' => 'scheduled',
            'created_by_user_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->post("/control-room/settings/maintenance/{$window->id}/cancel")
            ->assertRedirect();

        $this->assertSame('cancelled', $window->fresh()->status);
    }

    public function test_cancel_maintenance_window_rejects_completed(): void
    {
        $window = MaintenanceWindow::create([
            'name' => 'Already done',
            'starts_at' => now()->subHours(3),
            'ends_at' => now()->subHour(),
            'status' => 'completed',
            'created_by_user_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->post("/control-room/settings/maintenance/{$window->id}/cancel")
            ->assertStatus(422);
    }

    // ── Config options ───────────────────────────────────

    public function test_store_config_option_creates_record(): void
    {
        $this->actingAs($this->admin)
            ->post('/control-room/settings/options', [
                'group' => 'category',
                'value' => 'fire',
                'label' => 'Fire',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('control_room_config_options', [
            'group' => 'category',
            'value' => 'fire',
            'label' => 'Fire',
        ]);
    }

    public function test_delete_config_option_removes_record(): void
    {
        $option = ConfigOption::create([
            'group' => 'category',
            'value' => 'temp',
            'label' => 'Temp',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $this->actingAs($this->admin)
            ->delete("/control-room/settings/options/{$option->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('control_room_config_options', ['id' => $option->id]);
    }
}
