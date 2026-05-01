<?php

namespace Tests\Feature\ControlRoom;

use App\Models\ControlRoom\Shift;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ControlRoomHandoverControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RbacSeeder::class);

        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());
    }

    public function test_show_requires_manage_permission(): void
    {
        $stranger = User::factory()->create(['approved_at' => now()]);
        $shift = Shift::create([
            'name' => 'Day Shift',
            'starts_at' => now()->subHours(4),
            'status' => 'active',
            'shift_lead_user_id' => $this->admin->id,
        ]);

        $this->actingAs($stranger)
            ->get("/control-room/shifts/{$shift->id}/handover")
            ->assertForbidden();
    }

    public function test_show_returns_404_for_inactive_shift(): void
    {
        $shift = Shift::create([
            'name' => 'Day Shift',
            'starts_at' => now()->subHours(8),
            'ends_at' => now()->subHour(),
            'status' => 'completed',
            'shift_lead_user_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->get("/control-room/shifts/{$shift->id}/handover")
            ->assertNotFound();
    }

    public function test_show_renders_handover_wizard(): void
    {
        $shift = Shift::create([
            'name' => 'Active Shift',
            'starts_at' => now()->subHours(4),
            'status' => 'active',
            'shift_lead_user_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->get("/control-room/shifts/{$shift->id}/handover")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('control-room/shifts/handover')
                ->where('shift.id', $shift->id)
                ->where('shift.status', 'active')
                ->has('openAlertsCount')
                ->has('criticalAlerts')
                ->has('staff')
            );
    }
}
