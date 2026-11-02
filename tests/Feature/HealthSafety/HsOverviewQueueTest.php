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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * G2 (B2.2, M1/M3): "Your queue" blocks the work lists don't carry arrive in their own background part. Each block
 * holds only records the viewer can open; a block they can't act on or read is null, never a 0.
 */
class HsOverviewQueueTest extends TestCase
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

    public function test_worksafe_lists_open_reviews_still_waiting_at_the_viewers_sites_most_severe_first(): void
    {
        $creator = User::factory()->create();
        $high = HsEvent::factory()->create(['site_id' => $this->own->id, 'event_category' => 'hazard', 'severity' => 'high', 'created_by' => $creator->id]);
        $critical = HsEvent::factory()->create(['site_id' => $this->own->id, 'event_category' => 'hazard', 'severity' => 'critical', 'created_by' => $creator->id]);
        HsEvent::factory()->create(['site_id' => $this->own->id, 'event_category' => 'hazard', 'severity' => 'low', 'created_by' => $creator->id]);
        HsEvent::factory()->create(['site_id' => $this->other->id, 'event_category' => 'hazard', 'severity' => 'high', 'created_by' => $creator->id]);

        $queue = $this->queue($this->viewer('team_lead'));
        $this->assertSame(2, $queue['worksafe']['count'], 'Own Site only, waiting reviews only.');
        $this->assertSame([$critical->id, $high->id], array_column($queue['worksafe']['rows'], 'id'));
        $this->assertSame('decide', $queue['worksafe']['rows'][0]['step']);
    }

    public function test_actions_to_check_leave_out_what_the_viewer_added_owns_or_did(): void
    {
        $lead = $this->viewer('team_lead');
        $other = User::factory()->create();
        $event = HsEvent::factory()->create(['site_id' => $this->own->id, 'event_category' => 'hazard', 'severity' => 'low', 'created_by' => $other->id]);
        $checkable = HsCorrectiveAction::factory()->create(['hs_event_id' => $event->id, 'status' => HsCorrectiveAction::STATUS_COMPLETED,
            'created_by' => $other->id, 'assigned_to_user_id' => $other->id, 'completed_by_user_id' => $other->id, 'completed_at' => now()->subDay()]);
        HsCorrectiveAction::factory()->create(['hs_event_id' => $event->id, 'status' => HsCorrectiveAction::STATUS_COMPLETED,
            'created_by' => $other->id, 'assigned_to_user_id' => $other->id, 'completed_by_user_id' => $lead->id, 'completed_at' => now()->subDay()]);

        $toCheck = $this->queue($lead)['to_check'];
        $this->assertSame(1, $toCheck['count']);
        $this->assertSame([$checkable->id], array_column($toCheck['rows'], 'id'));
    }

    public function test_actions_to_check_follow_the_servers_separation_of_duties(): void
    {
        $lead = $this->viewer('team_lead');
        $other = User::factory()->create();
        $opened = HsEvent::factory()->create(['site_id' => $this->own->id, 'event_category' => 'hazard', 'severity' => 'low', 'created_by' => $lead->id]);
        $event = HsEvent::factory()->create(['site_id' => $this->own->id, 'event_category' => 'hazard', 'severity' => 'low', 'created_by' => $other->id]);
        $done = ['status' => HsCorrectiveAction::STATUS_COMPLETED, 'created_by' => $other->id, 'assigned_to_user_id' => $other->id,
            'completed_by_user_id' => $other->id, 'completed_at' => now()->subDay()];
        // On a review the lead opened: the server won't let them check it.
        HsCorrectiveAction::factory()->create(['hs_event_id' => $opened->id, ...$done]);
        // Once owned by the lead (handed on since): the server won't let them check it either.
        $handedOn = HsCorrectiveAction::factory()->create(['hs_event_id' => $event->id, ...$done, 'owner_revision' => 1]);
        DB::table('hs_corrective_action_owner_changes')->insert(['action_id' => $handedOn->id, 'revision' => 1,
            'previous_owner_user_id' => $lead->id, 'previous_owner_name' => 'Lead', 'owner_user_id' => $other->id, 'owner_name' => 'Other',
            'actor_user_id' => $lead->id, 'actor_name' => 'Lead', 'reason' => 'Handed on', 'request_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'request_hash' => str_repeat('a', 64), 'created_at' => now()->subDays(2)]);
        $checkable = HsCorrectiveAction::factory()->create(['hs_event_id' => $event->id, ...$done]);

        $this->assertSame([$checkable->id], array_column($this->queue($lead)['to_check']['rows'], 'id'));
    }

    public function test_people_without_incident_read_get_the_hazard_charts(): void
    {
        $compliance = $this->viewer('compliance_lead');
        $page = $this->actingAs($compliance)->get('/health-safety', [
            'X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
        ])->assertOk()->json('props');
        if (! $compliance->canDo('incidents.viewAny') && ! $compliance->canDo('incidents.viewAssigned')) {
            $this->assertSame('hazards', $page['header_chart_kind']);
        }
        $this->assertSame('incidents', $this->actingAs($this->viewer('health_safety_officer'))->get('/health-safety', [
            'X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
        ])->assertOk()->json('props.header_chart_kind'));
    }

    public function test_stages_count_open_reviews_by_stage_and_reviews_closed_this_month(): void
    {
        $creator = User::factory()->create();
        foreach ([HsEvent::STATUS_OPEN, HsEvent::STATUS_OPEN, HsEvent::STATUS_INVESTIGATING] as $status) {
            HsEvent::factory()->create(['site_id' => $this->own->id, 'event_category' => 'hazard', 'severity' => 'low', 'status' => $status, 'created_by' => $creator->id]);
        }
        HsEvent::factory()->closed()->create(['site_id' => $this->own->id, 'event_category' => 'hazard', 'severity' => 'low', 'created_by' => $creator->id, 'closed_at' => now()->subDays(3)]);

        $stages = $this->queue($this->viewer('health_safety_officer'))['stages'];
        $this->assertSame(2, $stages['open']);
        $this->assertSame(1, $stages['investigating']);
        $this->assertSame(1, $stages['closed_30d']);
    }

    public function test_blocks_a_person_cannot_act_on_or_read_are_left_out(): void
    {
        $lead = $this->queue($this->viewer('team_lead'));
        $this->assertNull($lead['incidents_need_owner'], 'Team leads start from H&S reviews, not incident ownership.');
        $this->assertNotNull($lead['to_check']);

        $manager = $this->queue($this->viewer('provider_manager'));
        $this->assertSame('incidents', $manager['set']);
        $this->assertIsArray($manager['incidents_need_owner'], 'Provider managers assign incidents (Stephan\u{2019}s M3).');
    }

    private function viewer(string $role): User
    {
        $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        $user->roles()->attach(Role::where('name', $role)->sole());
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'position_role' => $role, 'primary_site_id' => $this->own->id,
            'secondary_site_ids' => [], 'start_date' => today()->subYear(), 'end_date' => null, 'is_active' => true]);

        return $user->fresh();
    }

    private function queue(User $viewer): array
    {
        return $this->actingAs($viewer)->get('/health-safety', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Component' => 'health-safety/dashboard',
            'X-Inertia-Partial-Data' => 'queue',
        ])->assertOk()->json('props.queue');
    }
}
