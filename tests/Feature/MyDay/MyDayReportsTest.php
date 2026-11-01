<?php

namespace Tests\Feature\MyDay;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ModifiedDuty;
use App\Models\ReturnToWorkPlan;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkplaceInjury;
use App\Services\MyDay\MyReportsReadService;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * My Day's "What you reported" and "Your workplace injury" (board G4-1, approved by Stephan 1 Nov 2026; Q3, D-5).
 */
class MyDayReportsTest extends TestCase
{
    use RefreshDatabase;

    private User $worker;

    private Site $site;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-11-01 10:00:00', 'Pacific/Auckland')->utc());
        $this->worker = User::factory()->create(['role' => 'support_worker', 'name' => 'Mere Tane', 'approved_at' => now(), 'email_verified_at' => now()]);
        $this->worker->roles()->attach(Role::where('name', 'support_worker')->first());
        $this->site = Site::factory()->create(['name' => 'Kauri House']);
        $this->client = Client::factory()->create(['site_id' => $this->site->id, 'first_name' => 'Aroha', 'last_name' => 'Kingi']);
        $this->client->supportWorkers()->attach($this->worker->id);
        HrEmployeeProfile::factory()->create(['user_id' => $this->worker->id, 'primary_site_id' => $this->site->id]);
    }

    /** @param array<string, mixed> $attributes */
    private function incident(array $attributes): ClientIncident
    {
        return ClientIncident::factory()->create(array_replace([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'reported_by' => $this->worker->id,
            'type' => 'fall',
            'status' => 'submitted',
            'incident_owner_user_id' => null,
            'is_hr_confidential' => false,
            'independent_handling_requested' => false,
        ], $attributes));
    }

    public function test_she_sees_her_own_reports_in_her_four_words_and_nothing_private(): void
    {
        $owner = User::factory()->create(['name' => 'Hemi Walker']);
        $draft = $this->incident(['status' => 'draft', 'occurred_at' => '2026-10-31T17:52:00Z']);
        // Her own report for someone she no longer supports: the Incidents list can't open it, My Day still shows it.
        $elsewhere = Client::factory()->create(['site_id' => $this->site->id, 'first_name' => 'Noah', 'last_name' => 'Williams']);
        $tap = $this->incident(['client_id' => $elsewhere->id, 'type' => 'property_damage', 'occurred_at' => '2026-10-29T01:00:00Z']);
        $fall = $this->incident(['incident_owner_user_id' => $owner->id, 'occurred_at' => '2026-10-26T06:40:00Z']);
        $closed = $this->incident(['status' => 'closed', 'occurred_at' => '2026-10-22T01:00:00Z']);
        // Never listed: safeguarding, restraint, HR-confidential, independently handled, someone else's report.
        $this->incident(['type' => 'safeguarding', 'occurred_at' => '2026-10-30T01:00:00Z']);
        $this->incident(['type' => 'restraint', 'occurred_at' => '2026-10-30T01:00:00Z']);
        $this->incident(['is_hr_confidential' => true, 'occurred_at' => '2026-10-30T01:00:00Z']);
        $this->incident(['independent_handling_requested' => true, 'occurred_at' => '2026-10-30T01:00:00Z']);
        $this->incident(['reported_by' => $owner->id, 'occurred_at' => '2026-10-30T01:00:00Z']);

        $reports = app(MyReportsReadService::class)->recent($this->worker);

        $this->assertSame(
            [[$draft->id, 'draft'], [$tap->id, 'sent'], [$fall->id, 'followed_up'], [$closed->id, 'closed']],
            array_map(fn (array $row) => [$row['id'], $row['status']], $reports),
        );
        $this->assertSame('/incidents/'.$draft->id.'?return_to=%2Fmy-day', $reports[0]['finish_url']);
        $this->assertNull($reports[1]['finish_url']);
        $this->assertSame('Noah Williams', $reports[1]['client_name']);
        $this->assertSame('Aroha Kingi', $reports[2]['client_name']);
        $this->assertNull($reports[2]['site_name']);
    }

    public function test_her_own_injury_report_shows_with_its_word_and_only_the_last_five(): void
    {
        WorkplaceInjury::factory()->create([
            'user_id' => $this->worker->id,
            'created_by' => $this->worker->id,
            'injury_type' => 'laceration',
            'body_part_affected' => 'finger',
            'injury_date' => '2026-10-27T01:00:00Z',
            'status' => 'under_treatment',
        ]);
        foreach (range(1, 5) as $day) {
            $this->incident(['occurred_at' => CarbonImmutable::parse('2026-10-0'.$day.'T01:00:00Z')]);
        }

        $reports = app(MyReportsReadService::class)->recent($this->worker);

        $this->assertCount(5, $reports);
        $this->assertSame('injury', $reports[0]['kind']);
        $this->assertSame('followed_up', $reports[0]['status']);
        $this->assertTrue($reports[0]['own']);
        $this->assertSame('finger', $reports[0]['body_part']);
    }

    public function test_your_workplace_injury_says_duties_for_now_the_next_review_and_who_coordinates(): void
    {
        $coordinator = User::factory()->create(['name' => 'Hemi Walker']);
        $injury = WorkplaceInjury::factory()->create([
            'user_id' => $this->worker->id,
            'injury_type' => 'laceration',
            'body_part_affected' => 'finger',
            'injury_date' => '2026-10-27T01:00:00Z',
            'status' => 'return_to_work',
            'coordinator_user_id' => $coordinator->id,
            'next_review_date' => '2026-11-03',
        ]);
        $plan = ReturnToWorkPlan::factory()->create([
            'workplace_injury_id' => $injury->id,
            'worker_id' => $this->worker->id,
            'plan_start_date' => '2026-10-28',
            'status' => 'active',
        ]);
        ModifiedDuty::factory()->create([
            'return_to_work_plan_id' => $plan->id,
            'user_id' => $this->worker->id,
            'start_date' => '2026-10-28',
            'end_date' => null,
            'status' => 'active',
        ]);
        WorkplaceInjury::factory()->create(['user_id' => $this->worker->id, 'status' => 'closed', 'injury_date' => '2026-10-30T01:00:00Z']);

        $this->actingAs($this->worker)->get('/my-day')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('own_injury.id', $injury->id)
            ->where('own_injury.on_modified_duties', true)
            ->where('own_injury.next_review_date', '2026-11-03')
            ->where('own_injury.coordinator', 'Hemi Walker')
            ->has('my_reports'));
    }

    public function test_no_open_injury_means_no_card(): void
    {
        WorkplaceInjury::factory()->create(['user_id' => $this->worker->id, 'status' => 'recovered', 'injury_date' => '2026-10-20T01:00:00Z']);
        // Someone else's open injury is never hers.
        WorkplaceInjury::factory()->create(['status' => 'reported', 'injury_date' => '2026-10-20T01:00:00Z']);

        $this->assertNull(app(MyReportsReadService::class)->openInjury($this->worker));
    }

    public function test_the_four_words(): void
    {
        $this->assertSame('draft', MyReportsReadService::incidentWord('draft', false));
        $this->assertSame('sent', MyReportsReadService::incidentWord('submitted', false));
        $this->assertSame('followed_up', MyReportsReadService::incidentWord('submitted', true));
        $this->assertSame('followed_up', MyReportsReadService::incidentWord('reviewed', false));
        $this->assertSame('closed', MyReportsReadService::incidentWord('closed', true));
        $this->assertSame('sent', MyReportsReadService::injuryWord('reported', false));
        $this->assertSame('followed_up', MyReportsReadService::injuryWord('reported', true));
        $this->assertSame('closed', MyReportsReadService::injuryWord('recovered', true));
    }
}
