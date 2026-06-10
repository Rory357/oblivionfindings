<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Smoke-test the redesigned operations/timesheets index page.
 *
 * What we want to know:
 *   1. The route returns 200 (not 500) under normal auth.
 *   2. The new prop contract is present (heroSummary, tabCounts,
 *      availableShifts, sites) so the React page can render.
 *   3. The legacy /create + /approvals routes redirect into the unified
 *      page rather than 404ing or rendering the old Inertia component.
 *   4. The unified store endpoint accepts both `shift` and `manual` modes
 *      and writes the new activity_type / activity_items columns.
 *   5. The new archive/restore endpoints round-trip cleanly.
 */
class TimesheetUnifiedIndexTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RbacSeeder::class);

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'approved_at' => now(),
        ]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());
    }

    public function test_index_returns_redesigned_prop_shape(): void
    {
        $response = $this->actingAs($this->admin)->get('/operations/timesheets');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('operations/timesheets/index')
            ->has('heroSummary', fn ($hero) => $hero
                ->has('firstName')
                ->has('week_start')
                ->has('week_end')
                ->has('timesheets_total')
                ->has('timesheets_submitted')
                ->has('timesheets_approved')
                ->has('timesheets_returned')
                ->has('hours_this_week')
                ->has('hours_target')
                ->etc()
            )
            ->has('tabCounts')
            ->has('availableShifts')
            ->has('clients')
            ->has('sites')
        );
    }

    public function test_legacy_create_route_redirects_to_unified_dialog(): void
    {
        $response = $this->actingAs($this->admin)
            ->get('/operations/timesheets/create?shift_id=9303');

        $response->assertRedirect('/operations/timesheets?create=1&shift_id=9303');
    }

    public function test_legacy_approvals_route_redirects_to_pending_tab(): void
    {
        $response = $this->actingAs($this->admin)
            ->get('/operations/timesheets/approvals');

        $response->assertRedirect('/operations/timesheets?tab=submitted');
    }

    public function test_manual_mode_store_writes_activity_type_and_items(): void
    {
        $response = $this->actingAs($this->admin)->post('/operations/timesheets', [
            'mode' => 'manual',
            'activity_type' => 'training',
            'activity_items' => ['Module 1', 'Module 2', 'Module 3'],
            'client_id' => null,
            'site_id' => null,
            'work_date' => now()->toDateString(),
            'starts_at' => now()->setTime(9, 0)->toIso8601String(),
            'ends_at' => now()->setTime(11, 30)->toIso8601String(),
            'break_minutes' => 0,
            'mileage_km' => 0,
            'sleepover' => false,
            'on_call' => false,
            'public_holiday' => false,
            'notes' => 'Feature test — manual mode',
            'submit' => false,
        ]);

        $response->assertRedirect();

        $created = Timesheet::latest('id')->first();
        $this->assertNotNull($created);
        $this->assertSame('training', $created->activity_type);
        $this->assertSame(['Module 1', 'Module 2', 'Module 3'], $created->activity_items);
        $this->assertNull($created->shift_id);
        $this->assertSame('draft', $created->status);
    }

    public function test_archive_route_marks_timesheet_archived_with_reason(): void
    {
        // Build a manual-mode timesheet directly so it doesn't depend on the
        // shift factory's randomised staff assignment (which would trip the
        // ShiftSafetyInvariantService guard).
        $timesheet = Timesheet::factory()->create([
            'user_id' => $this->admin->id,
            'shift_id' => null,
            'activity_type' => 'admin',
            'status' => 'approved',
        ]);

        $this->actingAs($this->admin)
            ->post("/operations/timesheets/{$timesheet->id}/archive", [
                'reason' => 'End of pay run',
            ])
            ->assertRedirect();

        $timesheet->refresh();
        $this->assertNotNull($timesheet->archived_at);
        $this->assertSame('End of pay run', $timesheet->archived_reason);

        $this->actingAs($this->admin)->post("/operations/timesheets/{$timesheet->id}/restore");
        $timesheet->refresh();
        $this->assertNull($timesheet->archived_at);
    }

    /**
     * `?view={id}` deep links (attendance sessions, dashboards) open the
     * ViewTimesheetDialog by finding the row client-side — the controller must
     * guarantee the target row is in the payload even when the default tab
     * would hide it (archived here; same path covers pagination).
     */
    public function test_view_deep_link_includes_hidden_target_row(): void
    {
        $timesheet = Timesheet::factory()->create([
            'user_id' => $this->admin->id,
            'shift_id' => null,
            'activity_type' => 'admin',
            'status' => 'approved',
            'archived_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/operations/timesheets?view={$timesheet->id}");

        $response->assertOk();
        $ids = collect($response->viewData('page')['props']['timesheets']['data'])
            ->pluck('id');
        $this->assertTrue($ids->contains($timesheet->id));
    }

    public function test_edit_deep_link_also_materialises_the_target_row(): void
    {
        $timesheet = Timesheet::factory()->create([
            'user_id' => $this->admin->id,
            'shift_id' => null,
            'activity_type' => 'admin',
            'status' => 'returned',
            'archived_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/operations/timesheets?edit={$timesheet->id}");

        $response->assertOk();
        $ids = collect($response->viewData('page')['props']['timesheets']['data'])
            ->pluck('id');
        $this->assertTrue($ids->contains($timesheet->id));
    }

    public function test_view_deep_link_does_not_leak_foreign_timesheets(): void
    {
        $worker = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $supportRole = Role::where('name', 'support_worker')->first();
        if ($supportRole) {
            $worker->roles()->attach($supportRole);
        }

        $otherUser = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $foreign = Timesheet::factory()->create([
            'user_id' => $otherUser->id,
            'shift_id' => null,
            'activity_type' => 'admin',
            'status' => 'draft',
        ]);

        $response = $this->actingAs($worker)
            ->get("/operations/timesheets?view={$foreign->id}");

        $response->assertOk();
        $ids = collect($response->viewData('page')['props']['timesheets']['data'])
            ->pluck('id');
        $this->assertFalse($ids->contains($foreign->id));
    }
}
