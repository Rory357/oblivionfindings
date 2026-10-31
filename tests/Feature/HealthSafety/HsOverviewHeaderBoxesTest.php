<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * G2 (B2.3, K2; Stephan's approved header, 31 Oct): the Overview's four boxes arrive in their own background request,
 * each person gets their own four, every number counts only records they can open, and a trend is shown only where the
 * records' own dates prove what the number was.
 */
class HsOverviewHeaderBoxesTest extends TestCase
{
    use RefreshDatabase;

    private Site $own;

    private Site $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->own = Site::factory()->create(['is_active' => true]);
        $this->other = Site::factory()->create(['is_active' => true]);
    }

    public function test_each_role_gets_its_own_four_boxes(): void
    {
        $expected = [
            'admin' => ['need_owner', 'worksafe', 'actions_overdue', 'actions_to_check'],
            'health_safety_officer' => ['need_owner', 'worksafe', 'actions_overdue', 'actions_to_check'],
            'team_lead' => ['need_owner', 'worksafe', 'actions_overdue', 'actions_to_check'],
            'provider_manager' => ['incidents_need_owner', 'open_incidents', 'actions_overdue', 'open_hazards'],
        ];
        foreach ($expected as $role => $keys) {
            $boxes = $this->boxes($this->viewer($role));
            $this->assertSame($keys, collect($boxes['boxes'])->pluck('key')->all(), $role);
            $this->assertSame(now()->subDays(30)->toDateString(), $boxes['since']);
        }
    }

    public function test_numbers_count_only_the_viewers_own_sites(): void
    {
        $this->overdueAction($this->own, 'CA-OWN', createdDaysAgo: 10, dueDaysAgo: 3);
        $this->overdueAction($this->other, 'CA-OTHER', createdDaysAgo: 10, dueDaysAgo: 3);

        $this->assertSame(2, $this->box($this->viewer('health_safety_officer'), 'actions_overdue')['value'], 'All Sites.');
        $this->assertSame(1, $this->box($this->viewer('team_lead'), 'actions_overdue')['value'], 'Only their own Site.');
    }

    public function test_the_trend_and_weekly_bars_come_from_the_records_own_dates(): void
    {
        // Added 40 days ago, due 35 days ago: overdue on the trend start (30 days ago) and in the bars from 28 days ago.
        $this->overdueAction($this->own, 'CA-OLD', createdDaysAgo: 40, dueDaysAgo: 35);
        // Added 10 days ago, overdue for the last 3 days: only in the last bar (now).
        $this->overdueAction($this->own, 'CA-NEW', createdDaysAgo: 10, dueDaysAgo: 3);

        $box = $this->box($this->viewer('health_safety_officer'), 'actions_overdue');
        $this->assertSame(2, $box['value']);
        $this->assertSame(['delta' => 1, 'since' => now()->subDays(30)->toDateString()], $box['trend']);
        // Bars: 49, 42, 35, 28, 21, 14 and 7 days ago, then now.
        $this->assertSame([0, 0, 0, 1, 1, 1, 1, 2], $box['weeks']);
        $this->assertSame('alert', $box['tone']);
    }

    public function test_a_change_the_dates_cannot_prove_withholds_the_trend(): void
    {
        $this->overdueAction($this->own, 'CA-OLD', createdDaysAgo: 40, dueDaysAgo: 35);
        // Verified with no verification time: when it stopped being overdue isn't known.
        $this->overdueAction($this->own, 'CA-UNDATED', createdDaysAgo: 40, dueDaysAgo: 35, status: HsCorrectiveAction::STATUS_VERIFIED);

        $box = $this->box($this->viewer('health_safety_officer'), 'actions_overdue');
        $this->assertSame(1, $box['value']);
        $this->assertNull($box['trend'], 'No trend yet.');
        $this->assertSame(array_fill(0, 8, null), $box['weeks']);
    }

    public function test_worksafe_decide_or_tell_counts_open_reviews_still_waiting_and_never_shows_a_trend(): void
    {
        $creator = User::factory()->create();
        HsEvent::factory()->create(['site_id' => $this->own->id, 'event_category' => 'hazard', 'severity' => 'high', 'created_by' => $creator->id]);
        HsEvent::factory()->create(['site_id' => $this->own->id, 'event_category' => 'hazard', 'severity' => 'low', 'created_by' => $creator->id]);

        $box = $this->box($this->viewer('health_safety_officer'), 'worksafe');
        $this->assertSame(1, $box['value']);
        $this->assertNull($box['trend']);
        $this->assertSame(array_fill(0, 8, null), $box['weeks']);
    }

    public function test_each_chart_arrives_on_its_own_and_counts_only_what_the_viewer_can_open(): void
    {
        $this->overdueAction($this->own, 'CA-SOON', createdDaysAgo: 2, dueDaysAgo: -5);  // due in 5 days
        $this->overdueAction($this->other, 'CA-ELSEWHERE', createdDaysAgo: 2, dueDaysAgo: -5);
        $manager = $this->viewer('provider_manager');
        $officer = $this->viewer('health_safety_officer');

        // A team lead has no incident read, so their chart row is the hazard version (never zero incident charts).
        $this->assertSame('hazards', $this->chartFor($this->viewer('team_lead'), 'due')['kind']);

        $due = $this->chartFor($manager, 'due');
        $this->assertSame('actions', $due['kind']);
        $this->assertCount(30, $due['days']);
        $this->assertSame(1, $due['total'], 'Only their own Site.');
        $this->assertSame(1, $due['days'][5]['count']);
        $this->assertSame(2, $this->chartFor($officer, 'due')['total'], 'All Sites.');

        foreach (['severity' => 'items', 'over_time' => 'days', 'types' => 'items'] as $chart => $key) {
            $this->assertArrayHasKey($key, $this->chartFor($officer, $chart), $chart);
        }
        $this->assertSame(['critical', 'high', 'medium', 'low'], array_column($this->chartFor($officer, 'severity')['items'], 'key'));
        $this->assertCount(30, $this->chartFor($officer, 'over_time')['days']);
    }

    public function test_the_concerns_rail_counts_follow_the_boxes_and_the_viewers_sites(): void
    {
        $this->overdueAction($this->own, 'CA-OWN', createdDaysAgo: 10, dueDaysAgo: 3);
        $this->overdueAction($this->other, 'CA-OTHER', createdDaysAgo: 10, dueDaysAgo: 3);

        $lead = $this->actingAs($this->viewer('team_lead'))->getJson('/health-safety/rail-counts')->assertOk()->json();
        $this->assertSame(1, $lead['actions'], 'Only their own Site.');
        $officer = $this->actingAs($this->viewer('health_safety_officer'))->getJson('/health-safety/rail-counts')->assertOk()->json();
        $this->assertSame(2, $officer['actions']);
        $this->assertSame(['incidents', 'reviews', 'actions', 'notifications'], array_keys($officer));

        $worker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $worker->roles()->attach(Role::where('name', 'support_worker')->sole());
        $this->actingAs($worker)->getJson('/health-safety/rail-counts')->assertForbidden();
    }

    private function chartFor(User $viewer, string $chart): array
    {
        return $this->actingAs($viewer)->get('/health-safety', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Component' => 'health-safety/dashboard',
            'X-Inertia-Partial-Data' => 'header_chart_'.$chart,
        ])->assertOk()->json('props.header_chart_'.$chart);
    }

    private function overdueAction(Site $site, string $reference, int $createdDaysAgo, int $dueDaysAgo, string $status = HsCorrectiveAction::STATUS_OPEN): void
    {
        $creator = User::factory()->create();
        $event = HsEvent::factory()->create(['site_id' => $site->id, 'event_category' => 'hazard', 'severity' => 'low',
            'created_by' => $creator->id, 'created_at' => now()->subDays($createdDaysAgo + 1)]);
        HsCorrectiveAction::factory()->create(['hs_event_id' => $event->id, 'reference_number' => $reference, 'status' => $status,
            // Due dates are worker-calendar (NZ) dates, like the service's "overdue" and "due each day" (stable across midnight).
            'priority' => 'medium', 'due_date' => now((string) (config('app.worker_timezone', 'Pacific/Auckland') ?: config('app.timezone')))->subDays($dueDaysAgo)->toDateString(), 'created_by' => $creator->id,
            'created_at' => now()->subDays($createdDaysAgo), 'verified_at' => null, 'closed_at' => null, 'completed_at' => null]);
    }

    private function viewer(string $role): User
    {
        $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        $user->roles()->attach(Role::where('name', $role)->sole());
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'position_role' => $role, 'primary_site_id' => $this->own->id,
            'secondary_site_ids' => [], 'start_date' => today()->subYear(), 'end_date' => null, 'is_active' => true]);

        return $user->fresh();
    }

    private function box(User $viewer, string $key): array
    {
        $box = collect($this->boxes($viewer)['boxes'])->firstWhere('key', $key);
        $this->assertNotNull($box, "Box {$key} is missing.");

        return $box;
    }

    private function boxes(User $viewer): array
    {
        return $this->actingAs($viewer)->get('/health-safety', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Component' => 'health-safety/dashboard',
            'X-Inertia-Partial-Data' => 'header_boxes',
        ])->assertOk()->json('props.header_boxes');
    }
}
