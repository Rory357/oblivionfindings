<?php

namespace Tests\Feature\ControlRoom;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\ControlRoom\Shift;
use App\Models\ControlRoomAlert;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ControlRoomShiftControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $coordinator;

    protected User $visibleWorker;

    protected User $hiddenWorker;

    protected Site $visibleSite;

    protected Site $hiddenSite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RbacSeeder::class);

        $this->coordinator = $this->makeRoleUser('coordinator');
        $this->visibleWorker = $this->makeRoleUser('support_worker');
        $this->hiddenWorker = $this->makeRoleUser('support_worker');
        $this->visibleSite = Site::factory()->create(['type' => 'house']);
        $this->hiddenSite = Site::factory()->create(['type' => 'house']);

        $this->scopeUserToSite($this->coordinator, $this->visibleSite);
        $this->scopeUserToSite($this->visibleWorker, $this->visibleSite);
        $this->scopeUserToSite($this->hiddenWorker, $this->hiddenSite);
    }

    public function test_shift_index_scopes_alert_counts_and_staff_list(): void
    {
        ControlRoomAlert::factory()->open()->critical()->create([
            'site_id' => $this->visibleSite->id,
        ]);

        ControlRoomAlert::factory()->open()->critical()->create([
            'site_id' => $this->hiddenSite->id,
        ]);

        $this->actingAs($this->coordinator)
            ->get('/control-room/shifts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('control-room/shifts')
                ->where('openAlertsCount', 1)
                ->where('criticalAlertsCount', 1)
                ->has('staff', 2)
            );
    }

    public function test_shift_store_rejects_out_of_scope_staff_selection(): void
    {
        $this->actingAs($this->coordinator)
            ->post('/control-room/shifts', [
                'name' => 'Night Shift',
                'shift_lead_user_id' => $this->hiddenWorker->id,
                'team_members' => [$this->visibleWorker->id],
            ])
            ->assertForbidden();
    }

    public function test_handover_form_scopes_alert_counts_and_staff_list(): void
    {
        $shift = Shift::query()->create([
            'name' => 'Active Shift',
            'starts_at' => now()->subHour(),
            'status' => 'active',
            'shift_lead_user_id' => $this->visibleWorker->id,
            'team_members' => [$this->visibleWorker->id],
        ]);

        ControlRoomAlert::factory()->open()->critical()->create([
            'site_id' => $this->visibleSite->id,
        ]);

        ControlRoomAlert::factory()->open()->critical()->create([
            'site_id' => $this->hiddenSite->id,
        ]);

        $this->actingAs($this->coordinator)
            ->get("/control-room/shifts/{$shift->id}/handover")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('control-room/shifts/handover')
                ->where('openAlertsCount', 1)
                ->where('criticalAlertsCount', 1)
                ->has('staff', 2)
            );
    }

    public function test_handover_rejects_out_of_scope_incoming_staff_selection(): void
    {
        $shift = Shift::query()->create([
            'name' => 'Outgoing Shift',
            'starts_at' => now()->subHours(8),
            'status' => 'active',
            'shift_lead_user_id' => $this->visibleWorker->id,
            'team_members' => [$this->visibleWorker->id],
        ]);

        $this->actingAs($this->coordinator)
            ->post("/control-room/shifts/{$shift->id}/handover", [
                'handover_notes' => 'Scope test handover',
                'priority_items' => ['Check outstanding alert'],
                'incoming_lead_user_id' => $this->hiddenWorker->id,
                'incoming_team_members' => [$this->visibleWorker->id],
            ])
            ->assertForbidden();
    }

    public function test_handover_keeps_the_operator_supplied_incoming_shift_name(): void
    {
        $shift = Shift::query()->create([
            'name' => 'Outgoing Shift',
            'starts_at' => now()->subHours(8),
            'status' => 'active',
            'shift_lead_user_id' => $this->visibleWorker->id,
            'team_members' => [$this->visibleWorker->id],
        ]);

        $this->actingAs($this->coordinator)
            ->post("/control-room/shifts/{$shift->id}/handover", [
                'handover_notes' => 'Quiet night — nothing outstanding.',
                'incoming_shift_name' => 'Sunrise Team',
                'incoming_lead_user_id' => $this->visibleWorker->id,
                'incoming_team_members' => [$this->visibleWorker->id],
            ])
            ->assertRedirect(route('control-room.shifts.index'));

        // The outgoing shift is completed and a new active shift carries the typed name.
        $this->assertSame('completed', $shift->fresh()->status);
        $this->assertDatabaseHas('control_room_shifts', [
            'name' => 'Sunrise Team',
            'status' => 'active',
            'shift_lead_user_id' => $this->visibleWorker->id,
        ]);
    }

    public function test_handover_falls_back_to_a_timestamped_name_when_none_supplied(): void
    {
        $shift = Shift::query()->create([
            'name' => 'Outgoing Shift',
            'starts_at' => now()->subHours(8),
            'status' => 'active',
            'shift_lead_user_id' => $this->visibleWorker->id,
            'team_members' => [$this->visibleWorker->id],
        ]);

        $this->actingAs($this->coordinator)
            ->post("/control-room/shifts/{$shift->id}/handover", [
                'handover_notes' => 'Quiet night — nothing outstanding.',
                'incoming_lead_user_id' => $this->visibleWorker->id,
                'incoming_team_members' => [$this->visibleWorker->id],
            ])
            ->assertRedirect(route('control-room.shifts.index'));

        $newShift = Shift::query()->where('status', 'active')->latest('id')->first();
        $this->assertNotNull($newShift);
        $this->assertStringStartsWith('Shift ', $newShift->name);
    }

    protected function makeRoleUser(string $roleName): User
    {
        $user = User::factory()->create([
            'role' => $roleName,
            'approved_at' => now(),
        ]);

        $role = Role::query()->where('name', $roleName)->first();
        if ($role) {
            $user->roles()->syncWithoutDetaching([$role->id]);
        }

        return $user;
    }

    protected function scopeUserToSite(User $user, Site $site): void
    {
        HrEmployeeProfile::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'tenant_id' => 1,
                'employee_number' => 'EMP-SHIFT-'.$user->id,
                'work_email' => $user->email,
                'position_title' => 'Control Room',
                'position_role' => $user->role,
                'employment_type' => 'full_time',
                'start_date' => now()->subMonth()->toDateString(),
                'is_active' => true,
                'primary_site_id' => $site->id,
                'secondary_site_ids' => [],
            ],
        );
    }
}
