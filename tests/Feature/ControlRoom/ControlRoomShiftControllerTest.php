<?php

namespace Tests\Feature\ControlRoom;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\ControlRoom\Shift;
use App\Models\ControlRoomAlert;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

        $this->seed(RbacSeeder::class);

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

    public function test_shift_index_avoids_a_mixed_status_recent_shift_sort_query(): void
    {
        Shift::query()->create([
            'name' => 'Completed Shift',
            'starts_at' => now()->subHours(2),
            'ends_at' => now()->subHour(),
            'status' => 'completed',
            'shift_lead_user_id' => $this->visibleWorker->id,
            'team_members' => [$this->visibleWorker->id],
        ]);

        $shiftQueries = [];
        DB::listen(function (QueryExecuted $query) use (&$shiftQueries): void {
            if (str_contains(strtolower($query->sql), 'control_room_shifts')) {
                $shiftQueries[] = str_replace('`', '"', strtolower($query->sql));
            }
        });

        $this->actingAs($this->coordinator)
            ->get('/control-room/shifts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('recentShifts.0.name', 'Completed Shift')
            );

        $this->assertFalse(
            collect($shiftQueries)->contains(
                fn (string $sql): bool => str_contains($sql, 'status" in')
                    && str_contains($sql, 'order by "starts_at" desc'),
            ),
            'Recent shifts must not use the mixed-status filesort query that exhausted MySQL sort memory after handover acceptance.',
        );
    }

    public function test_shift_index_returns_record_level_actions_for_active_and_recent_shifts(): void
    {
        Shift::query()->create([
            'name' => 'Active Shift',
            'starts_at' => now()->subHour(),
            'status' => 'active',
            'shift_lead_user_id' => $this->coordinator->id,
            'team_members' => [$this->visibleWorker->id],
        ]);
        Shift::query()->create([
            'name' => 'Previous Shift',
            'starts_at' => now()->subHours(3),
            'ends_at' => now()->subHours(2),
            'status' => 'completed',
            'shift_lead_user_id' => $this->visibleWorker->id,
            'team_members' => [$this->visibleWorker->id],
        ]);

        $this->actingAs($this->coordinator)
            ->get('/control-room/shifts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('activeShift.actions.can_open_handover', true)
                ->where('activeShift.actions.can_add_note', true)
                ->where('activeShift.actions.can_copy_summary', true)
                ->where('recentShifts.0.actions.can_copy_summary', true)
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
                ->has('requiredAlerts', 1)
                ->where('requiredAlerts.0.severity', 'critical')
                ->where('carryForward.total', 0)
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
            ->patch("/control-room/shifts/{$shift->id}/handover/draft", [
                'handover_notes' => 'Scope test handover',
                'incoming_lead_user_id' => $this->hiddenWorker->id,
                'incoming_team_members' => [$this->visibleWorker->id],
                'reviewed_alert_ids' => [],
                'priority_alert_ids' => [],
                'expected_version' => $shift->fresh()->handover_version,
            ])
            ->assertForbidden();
    }

    public function test_handover_keeps_the_operator_supplied_incoming_shift_name(): void
    {
        $shift = Shift::query()->create([
            'name' => 'Outgoing Shift',
            'starts_at' => now()->subHours(8),
            'status' => 'active',
            'shift_lead_user_id' => $this->coordinator->id,
            'team_members' => [$this->visibleWorker->id],
        ]);

        $this->prepareAndAccept($shift, [
            'handover_notes' => 'Quiet night — nothing outstanding.',
            'incoming_shift_name' => 'Sunrise Team',
        ]);

        // Acceptance completes outgoing ownership and activates the named shift.
        $this->assertSame('completed', $shift->fresh()->status);
        $this->assertDatabaseHas('control_room_shifts', [
            'name' => 'Sunrise Team',
            'status' => 'active',
            'shift_lead_user_id' => $this->coordinator->id,
        ]);
    }

    public function test_handover_notes_are_optional_and_skip_the_operator_note(): void
    {
        $shift = Shift::query()->create([
            'name' => 'Outgoing Shift',
            'starts_at' => now()->subHours(8),
            'status' => 'active',
            'shift_lead_user_id' => $this->coordinator->id,
            'team_members' => [$this->visibleWorker->id],
        ]);

        $this->prepareAndAccept($shift, [
            'incoming_shift_name' => 'Quiet Night Crew',
        ]);

        $this->assertSame('completed', $shift->fresh()->status);
        $this->assertDatabaseHas('control_room_shifts', ['name' => 'Quiet Night Crew', 'status' => 'active']);

        // No empty handover note should be written to the operator log.
        $this->assertDatabaseMissing('control_room_operator_notes', [
            'shift_id' => $shift->id,
            'type' => 'handover',
        ]);
    }

    public function test_handover_falls_back_to_a_timestamped_name_when_none_supplied(): void
    {
        $shift = Shift::query()->create([
            'name' => 'Outgoing Shift',
            'starts_at' => now()->subHours(8),
            'status' => 'active',
            'shift_lead_user_id' => $this->coordinator->id,
            'team_members' => [$this->visibleWorker->id],
        ]);

        $this->prepareAndAccept($shift, [
            'handover_notes' => 'Quiet night — nothing outstanding.',
        ]);

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

    /** @param array<string, mixed> $draftOverrides */
    protected function prepareAndAccept(Shift $shift, array $draftOverrides = []): void
    {
        $draft = array_replace([
            'handover_notes' => '',
            'incoming_shift_name' => '',
            'incoming_lead_user_id' => $this->coordinator->id,
            'incoming_team_members' => [$this->visibleWorker->id],
            'reviewed_alert_ids' => [],
            'priority_alert_ids' => [],
            'expected_version' => $shift->fresh()->handover_version,
        ], $draftOverrides);

        $this->actingAs($this->coordinator)
            ->patch("/control-room/shifts/{$shift->id}/handover/draft", $draft)
            ->assertSessionHasNoErrors();

        $shift->refresh();
        $this->actingAs($this->coordinator)
            ->post("/control-room/shifts/{$shift->id}/handover", [
                'incoming_lead_user_id' => $this->coordinator->id,
                'reviewed_alert_ids' => [],
                'expected_version' => $shift->handover_version,
            ])
            ->assertSessionHasNoErrors();

        $shift->refresh();
        $this->actingAs($this->coordinator)
            ->post("/control-room/shifts/{$shift->id}/accept-handover", [
                'expected_version' => $shift->handover_version,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('control-room.shifts.index'));
    }
}
