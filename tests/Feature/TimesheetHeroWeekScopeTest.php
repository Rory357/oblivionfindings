<?php

namespace Tests\Feature;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Role;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Hero week-nav contract for /operations/timesheets:
 *
 *   1. No from/to → summary describes the CURRENT week and the list shows
 *      every week (the approval queue never week-hides by default).
 *   2. from/to forming an exact Mon–Sun pair (what the hero week-stepper
 *      writes) → the hero summary follows that week and the list is scoped.
 *   3. Any other from/to range → list scoped as before, summary falls back
 *      to the current week.
 */
class TimesheetHeroWeekScopeTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Client $client;

    protected Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RbacSeeder::class);

        $this->site = Site::factory()->create(['name' => 'Hero Week Site']);

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'approved_at' => now(),
        ]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        HrEmployeeProfile::factory()->create([
            'user_id' => $this->admin->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subMonth(),
            'end_date' => null,
            'is_active' => true,
        ]);

        $this->client = Client::factory()->create([
            'site_id' => $this->site->id,
            'first_name' => 'Hero',
            'last_name' => 'Resident',
        ]);
    }

    private function timesheetOn(string $workDate): Timesheet
    {
        return Timesheet::factory()->create([
            'shift_id' => null,
            'user_id' => $this->admin->id,
            'client_id' => $this->client->id,
            'shift_site_id' => $this->site->id,
            'site_id' => $this->site->id,
            'work_date' => $workDate,
            'starts_at' => "{$workDate} 09:00:00",
            'ends_at' => "{$workDate} 17:00:00",
            'shift_site_name_snapshot' => $this->site->name,
            'client_name_snapshot' => $this->client->full_name,
            'staff_name_snapshot' => $this->admin->name,
            'status' => 'submitted',
        ]);
    }

    public function test_default_view_summarises_current_week_and_never_week_hides(): void
    {
        $thisMonday = now()->startOfWeek()->toDateString();
        $oldMonday = now()->startOfWeek()->subWeeks(2)->toDateString();
        $current = $this->timesheetOn($thisMonday);
        $old = $this->timesheetOn($oldMonday);

        $response = $this->actingAs($this->admin)->get('/operations/timesheets');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('heroSummary.week_start', $thisMonday)
            ->where('filters.from', null)
            ->has('timesheets.data', 2)
        );
    }

    public function test_week_pair_filters_scope_summary_and_list(): void
    {
        $thisMonday = now()->startOfWeek()->toDateString();
        $oldMonday = now()->startOfWeek()->subWeeks(2)->toDateString();
        $oldSunday = now()->startOfWeek()->subWeeks(2)->addDays(6)->toDateString();
        $this->timesheetOn($thisMonday);
        $old = $this->timesheetOn($oldMonday);

        $response = $this->actingAs($this->admin)
            ->get("/operations/timesheets?from={$oldMonday}&to={$oldSunday}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('heroSummary.week_start', $oldMonday)
            ->where('heroSummary.week_end', $oldSunday)
            ->has('timesheets.data', 1)
            ->where('timesheets.data.0.id', $old->id)
        );
    }

    public function test_non_week_range_scopes_list_but_summary_falls_back_to_current_week(): void
    {
        $thisMonday = now()->startOfWeek()->toDateString();
        $oldMonday = now()->startOfWeek()->subWeeks(2)->toDateString();
        $oldWednesday = now()->startOfWeek()->subWeeks(2)->addDays(2)->toDateString();
        $this->timesheetOn($thisMonday);
        $old = $this->timesheetOn($oldMonday);

        $response = $this->actingAs($this->admin)
            ->get("/operations/timesheets?from={$oldMonday}&to={$oldWednesday}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('heroSummary.week_start', $thisMonday)
            ->has('timesheets.data', 1)
            ->where('timesheets.data.0.id', $old->id)
        );
    }
}
