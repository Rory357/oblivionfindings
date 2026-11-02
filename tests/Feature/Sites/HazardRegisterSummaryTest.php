<?php

namespace Tests\Feature\Sites;

use App\Models\Role;
use App\Models\Site;
use App\Models\SiteHazard;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * G4-14 (board approved by Stephan 1 Nov 2026): the Hazards register's one lead block — open hazards with no owner or
 * past their due date first (oldest due date first), then open ones nobody has started — and the closed hazards count
 * for the quiet row. Closed and mitigated hazards never lead.
 */
class HazardRegisterSummaryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-31T01:00:00Z'));
        $this->seed(RbacSeeder::class);
        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());
        $this->site = Site::factory()->create(['type' => 'house']);
    }

    private function hazard(array $overrides): SiteHazard
    {
        return SiteHazard::create(['site_id' => $this->site->id, 'reported_by_user_id' => $this->admin->id, 'hazard_type' => 'slip_trip_fall',
            'severity' => 'low', 'likelihood' => 'rare', 'description' => 'Synthetic hazard', 'status' => 'open', ...$overrides]);
    }

    public function test_no_owner_and_past_due_lead_then_not_started_and_closed_ones_stay_quiet(): void
    {
        $rail = $this->hazard(['description' => 'Wobbly bathroom rail', 'due_date' => '2026-10-28', 'assigned_to_user_id' => null]);
        $steps = $this->hazard(['description' => 'Loose handrail on the back steps', 'assigned_to_user_id' => $this->admin->id, 'due_date' => '2026-11-20']);
        $this->hazard(['status' => 'in_progress', 'assigned_to_user_id' => $this->admin->id, 'due_date' => '2026-12-01']);
        $this->hazard(['status' => 'mitigated', 'assigned_to_user_id' => null, 'due_date' => '2026-01-01']);
        $this->hazard(['status' => 'closed', 'closed_at' => '2026-10-20 01:00:00']);
        $this->hazard(['status' => 'closed', 'closed_at' => '2026-08-01 01:00:00']);

        $this->actingAs($this->admin)->get('/compliance/hazards')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('compliance/hazards/index')
                ->has('register_summary.lead', 2)
                ->where('register_summary.lead.0.id', $rail->id)
                ->where('register_summary.lead.0.flags.unassigned', true)
                ->where('register_summary.lead.0.flags.overdue', true)
                ->where('register_summary.lead.1.id', $steps->id)
                ->where('register_summary.lead_total', 2)
                ->where('register_summary.closed_30d', 1));
    }
}
