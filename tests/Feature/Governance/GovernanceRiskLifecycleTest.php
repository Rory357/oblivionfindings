<?php

namespace Tests\Feature\Governance;

use App\Domain\Governance\Jobs\CaptureRiskHeatmapSnapshot;
use App\Domain\Governance\Models\BoardCommittee;
use App\Domain\Governance\Models\CommitteeMembership;
use App\Domain\Governance\Models\RiskHeatmapSnapshot;
use App\Domain\Governance\Models\RiskRegisterEntry;
use App\Domain\Governance\Models\RiskTreatment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

/**
 * Risk register: counts match the lists they open, accepting needs the
 * board's resolution, closing needs a reason, actions can be finished, the
 * heatmap/trends tell the truth and committee views use real committees.
 */
class GovernanceRiskLifecycleTest extends TestCase
{
    use GovernanceTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGovernance();
    }

    /** A 5 × 5 risk with no controls: 25 before and after controls. */
    private function criticalRisk($owner, array $overrides = []): RiskRegisterEntry
    {
        return $this->createRisk($owner, array_merge([
            'likelihood_score' => 5,
            'impact_score' => 5,
            'control_effectiveness' => 'none',
            'appetite_threshold' => 12,
        ], $overrides));
    }

    public function test_the_register_counts_match_the_lists_their_blocks_open(): void
    {
        $admin = $this->createAdminUser();
        $this->criticalRisk($admin, ['title' => 'Open critical']);
        $this->criticalRisk($admin, ['title' => 'Accepted critical', 'status' => 'accepted']);
        $this->criticalRisk($admin, ['title' => 'Closed critical', 'status' => 'voided']);
        $this->createRisk($admin, ['title' => 'Low and open']);

        $index = $this->actingAs($admin)->get('/governance/risks')->assertOk();
        $summary = $index->inertiaProps('summary');

        $this->assertSame(3, $summary['current']);
        $this->assertSame(3, $index->inertiaProps('risks.total'));
        $this->assertSame(2, $summary['critical']);
        $this->assertSame(1, $summary['above_limit']);
        $this->assertSame(1, $summary['accepted']);
        $this->assertSame(1, $summary['closed']);

        foreach ([
            'severity=critical' => $summary['critical'],
            'above_appetite=1' => $summary['above_limit'],
            'status=accepted' => $summary['accepted'],
            'status=closed' => $summary['closed'],
        ] as $query => $count) {
            $this->assertSame(
                $count,
                $this->actingAs($admin)->get("/governance/risks?{$query}")->inertiaProps('risks.total'),
                "The block count for {$query} should match its list.",
            );
        }

        $this->actingAs($admin)
            ->get('/governance/risks?status=accepted')
            ->assertInertia(fn (Assert $page) => $page
                ->where('risks.data.0.title', 'Accepted critical')
                ->where('risks.data.0.status', 'accepted'));
    }

    public function test_a_risk_above_the_limit_can_only_be_accepted_with_a_passed_resolution(): void
    {
        $admin = $this->createAdminUser();
        $risk = $this->criticalRisk($admin);
        $draft = $this->createResolution($admin, ['title' => 'Draft acceptance', 'status' => 'draft']);
        $passed = $this->createResolution($admin, [
            'title' => 'Accept the funding risk',
            'status' => 'closed',
            'outcome' => 'carried',
            'closed_at' => now()->subDay(),
        ]);

        $this->actingAs($admin)
            ->get("/governance/risks/{$risk->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('canAccept', true)
                ->has('resolutionOptions', 1)
                ->where('resolutionOptions.0.id', $passed->id));

        $payload = [
            'justification' => str_repeat('The board accepts this risk for now. ', 3),
            'expiry_months' => 6,
            'conditions' => ['Report to the board monthly', ''],
        ];

        $this->actingAs($admin)
            ->post("/governance/risks/{$risk->id}/accept", $payload)
            ->assertSessionHasErrors('resolution_id');

        $this->actingAs($admin)
            ->post("/governance/risks/{$risk->id}/accept", [...$payload, 'resolution_id' => $draft->id])
            ->assertSessionHasErrors(['resolution_id' => "This resolution can't be used yet — voting must be finished and the result recorded as passed."]);

        $this->assertSame('active', $risk->fresh()->status);

        $this->actingAs($admin)
            ->post("/governance/risks/{$risk->id}/accept", [...$payload, 'resolution_id' => $passed->id])
            ->assertSessionHasNoErrors();

        $this->assertSame('accepted', $risk->fresh()->status);
        $this->assertDatabaseHas('risk_acceptances', [
            'risk_register_entry_id' => $risk->id,
            'resolution_id' => $passed->id,
            'acceptance_type' => 'board_resolution',
        ]);

        $this->actingAs($admin)
            ->get("/governance/risks/{$risk->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('risk.status', 'accepted')
                ->where('risk.accepted_until', now()->addMonths(6)->toDateString())
                ->where('risk.acceptances.0.resolution.title', 'Accept the funding risk')
                ->where('risk.acceptances.0.conditions', ['Report to the board monthly']));
    }

    public function test_closing_a_risk_needs_a_reason_and_keeps_it_on_record(): void
    {
        $admin = $this->createAdminUser();
        $risk = $this->createRisk($admin);

        $this->actingAs($admin)
            ->post("/governance/risks/{$risk->id}/close", ['rationale' => 'Too short'])
            ->assertSessionHasErrors(['rationale' => 'Say why this risk is being closed in at least 20 characters.']);

        $this->actingAs($admin)
            ->post("/governance/risks/{$risk->id}/close", ['rationale' => 'The service this risk related to has closed.'])
            ->assertRedirect("/governance/risks/{$risk->id}");

        $this->actingAs($admin)
            ->get("/governance/risks/{$risk->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('risk.status', 'closed')
                ->where('risk.closure_rationale', 'The service this risk related to has closed.')
                ->where('canClose', false));
    }

    public function test_an_action_that_needs_evidence_can_only_be_marked_done_once_evidence_is_attached(): void
    {
        $admin = $this->createAdminUser();
        $risk = $this->createRisk($admin);
        $treatment = RiskTreatment::create([
            'risk_register_entry_id' => $risk->id,
            'action_description' => 'Turn on two-step sign-in',
            'assigned_to' => $admin->id,
            'due_date' => now()->addWeek()->toDateString(),
            'status' => 'planned',
            'evidence_required' => true,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get("/governance/risks/{$risk->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('risk.treatments.0.can_complete', false)
                ->where('risk.treatments.0.complete_blocked_reason', 'Attach evidence first — this action was set up to need evidence before it is marked done.'));

        $this->actingAs($admin)
            ->post("/governance/risks/{$risk->id}/treatments/{$treatment->id}/complete")
            ->assertSessionHasErrors('treatment');
        $this->assertSame('planned', $treatment->fresh()->status);

        $treatment->update(['evidence_attachments' => [[
            'id' => 'evidence-1',
            'path' => 'governance/risks/x.pdf',
            'original_name' => 'Sign-in report.pdf',
        ]]]);

        $this->actingAs($admin)
            ->post("/governance/risks/{$risk->id}/treatments/{$treatment->id}/complete", [
                'completion_notes' => 'Turned on for every system.',
            ])
            ->assertSessionHasNoErrors();

        $treatment->refresh();
        $this->assertSame('complete', $treatment->status);
        $this->assertSame($admin->id, (int) $treatment->completed_by);
        $this->assertSame('Turned on for every system.', $treatment->completion_evidence);
    }

    public function test_an_action_due_date_can_change_and_the_change_is_logged(): void
    {
        $admin = $this->createAdminUser();
        $member = $this->createUserWithRole('board_member');
        $risk = $this->createRisk($admin);
        $other = $this->createRisk($admin);
        $treatment = RiskTreatment::create([
            'risk_register_entry_id' => $risk->id,
            'action_description' => 'Review the supplier contract',
            'assigned_to' => $admin->id,
            'due_date' => now()->subDays(3)->toDateString(),
            'status' => 'planned',
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get("/governance/risks/{$risk->id}")
            ->assertInertia(fn (Assert $page) => $page->where('risk.treatments.0.status', 'overdue'));

        $this->actingAs($admin)
            ->put("/governance/risks/{$risk->id}/treatments/{$treatment->id}/due-date", [
                'due_date' => now()->subMonth()->toDateString(),
            ])
            ->assertSessionHasErrors(['due_date' => 'Choose today or a later date.']);

        $newDate = now()->addMonth()->toDateString();
        $this->actingAs($admin)
            ->put("/governance/risks/{$risk->id}/treatments/{$treatment->id}/due-date", [
                'due_date' => $newDate,
                'reason' => 'Waiting on the supplier',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($newDate, $treatment->fresh()->due_date->toDateString());
        $this->assertTrue(DB::table('governance_audit_log')
            ->where('action', 'risk_treatment.due_date_changed')
            ->where('resource_id', $treatment->id)
            ->exists());

        // The pair must match, and ordinary members can't change actions.
        $this->actingAs($admin)
            ->put("/governance/risks/{$other->id}/treatments/{$treatment->id}/due-date", ['due_date' => $newDate])
            ->assertNotFound();
        $this->actingAs($member)
            ->post("/governance/risks/{$risk->id}/treatments/{$treatment->id}/complete")
            ->assertForbidden();
    }

    public function test_the_heatmap_shows_open_and_accepted_risks_unless_closed_ones_are_included(): void
    {
        $admin = $this->createAdminUser();
        $this->criticalRisk($admin);
        $this->criticalRisk($admin, ['status' => 'voided']);

        $default = $this->actingAs($admin)->get('/governance/risks/heatmap')->assertOk();
        $this->assertSame(1, collect($default->inertiaProps('heatmap'))->flatten(1)->sum('count'));
        $this->assertSame(1, $default->inertiaProps('bands.before.critical'));
        $this->assertSame(1, $default->inertiaProps('bands.after.critical'));

        $withClosed = $this->actingAs($admin)->get('/governance/risks/heatmap?include_closed=1')->assertOk();
        $this->assertSame(2, collect($withClosed->inertiaProps('heatmap'))->flatten(1)->sum('count'));
        $this->assertSame('1', $withClosed->inertiaProps('filters.include_closed'));

        // A cell links to the register filtered to that likelihood × impact.
        $this->assertSame(
            1,
            $this->actingAs($admin)->get('/governance/risks?likelihood=5&impact=5')->inertiaProps('risks.total'),
        );
    }

    public function test_trends_say_when_monthly_records_start_and_the_job_records_the_register(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin)
            ->get('/governance/risks/trends')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('snapshots', 0)
                ->where('nextSnapshotOn', fn (string $date) => str_ends_with($date, '-01')));

        $this->criticalRisk($admin);
        $this->criticalRisk($admin, ['status' => 'accepted']);
        $this->criticalRisk($admin, ['status' => 'voided']);

        (new CaptureRiskHeatmapSnapshot)->handle();
        (new CaptureRiskHeatmapSnapshot)->handle();

        $snapshot = RiskHeatmapSnapshot::query()->sole();
        $this->assertSame(2, $snapshot->summary['critical']);
        $this->assertSame(1, $snapshot->summary['above_appetite']);
    }

    public function test_committee_views_use_real_committees_and_one_category_map(): void
    {
        $admin = $this->createAdminUser();
        $this->createRisk($admin, ['title' => 'Client safety risk', 'category' => 'client_safety']);
        $this->createRisk($admin, ['title' => 'Clinical risk', 'category' => 'clinical']);
        $this->createRisk($admin, ['title' => 'Finance risk', 'category' => 'financial']);

        // No people committee has been set up yet.
        $this->actingAs($admin)->get('/governance/risks/committee/people')->assertNotFound();

        $committee = BoardCommittee::create([
            'committee_type' => 'people',
            'name' => 'People and care committee',
            'is_active' => true,
        ]);
        $memberUser = $this->createUserWithRole('board_member', ['name' => 'Aroha Committee']);
        CommitteeMembership::create([
            'board_committee_id' => $committee->id,
            'board_member_id' => $this->createBoardMember($memberUser)->id,
            'role' => 'chair',
            'appointed_at' => now()->subMonth()->toDateString(),
            'is_active' => true,
        ]);

        foreach (["/governance/risks/committee/{$committee->id}", '/governance/risks/committee/people'] as $url) {
            $this->actingAs($admin)
                ->get($url)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Governance/Risks/Committee')
                    ->where('committee.id', $committee->id)
                    ->where('committee.name', 'People and care committee')
                    ->where('committee.members.0.name', 'Aroha Committee')
                    ->where('committee.members.0.role', 'Chair')
                    ->has('risks', 2)
                    ->where('risks', fn ($risks) => collect($risks)->pluck('title')->sort()->values()->all() === ['Client safety risk', 'Clinical risk']));
        }

        // Every category belongs to at least one committee.
        $covered = collect(\App\Domain\Governance\Support\RiskCommitteeScope::CATEGORY_MAP)->flatten()->unique();
        foreach (array_keys(\App\Domain\Governance\Services\RiskScoringService::DEFAULT_APPETITE_THRESHOLDS) as $category) {
            $this->assertTrue($covered->contains($category), "{$category} has no committee.");
        }
    }
}
