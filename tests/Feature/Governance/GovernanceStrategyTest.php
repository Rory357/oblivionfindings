<?php

namespace Tests\Feature\Governance;

use App\Domain\Governance\Models\GovernanceResolutionBinding;
use App\Domain\Governance\Models\StrategicPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

class GovernanceStrategyTest extends TestCase
{
    use GovernanceTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGovernance();
    }

    public function test_admin_can_create_plan(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->post('/governance/strategy', [
            'title' => 'Strategy Plan',
            'planning_horizon' => '3_year',
            'period_start' => now()->toDateString(),
            'period_end' => now()->addYears(3)->toDateString(),
            'vision_statement' => 'Vision',
            'mission_statement' => 'Mission',
            'values' => ['integrity'],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('strategic_plans', [
            'title' => 'Strategy Plan',
            'planning_horizon' => '3_year',
        ]);
    }

    public function test_create_and_edit_deep_links_open_the_wizard_dialogs(): void
    {
        $admin = $this->createAdminUser();
        $plan = $this->createStrategicPlan($admin);

        $this->actingAs($admin)->get('/governance/strategy/create')
            ->assertRedirect('/governance/strategy?create=1');

        $this->actingAs($admin)->get('/governance/strategy?create=1')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Governance/Strategy/Index')
                ->where('canCreate', true)
                ->has('formOptions.horizons', 2)
                ->has('formOptions.pillars', 6)
                ->where('summary.total', 1)
                ->has('filters'));

        $this->actingAs($admin)->get("/governance/strategy/{$plan->id}/edit")
            ->assertRedirect("/governance/strategy/{$plan->id}?edit=1");

        $this->actingAs($admin)->get("/governance/strategy/{$plan->id}?edit=1")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Governance/Strategy/Show')
                ->where('canEdit', true)
                ->where('canAddGoal', true)
                // Nothing to record until a linked resolution has passed.
                ->where('canApprove', false)
                ->where('approval.key', 'waiting')
                ->where('canCreateVersion', false)
                ->has('formOptions.pillars', 6));
    }

    /** P1: "Record board approval" is only offered once a linked resolution has passed. */
    public function test_board_approval_is_offered_only_after_a_linked_resolution_passes(): void
    {
        $admin = $this->createAdminUser();
        $plan = $this->createStrategicPlan($admin);

        $paper = $this->createResolution($admin, ['title' => 'Approve the strategic plan']);
        app(\App\Domain\Governance\Services\GovernanceResolutionAuthorityService::class)
            ->bind($paper, GovernanceResolutionBinding::SUBJECT_STRATEGIC_PLAN, $plan->id, $admin);

        $this->actingAs($admin)->get("/governance/strategy/{$plan->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('approval.key', 'drafted')
                ->where('approval.resolution.id', $paper->id)
                ->where('canApprove', false)
                ->has('carriedResolutions', 0));

        // An unpassed resolution can't approve the plan even if posted directly.
        $this->actingAs($admin)->post("/governance/strategy/{$plan->id}/approve", [
            'resolution_id' => $paper->id,
        ])->assertSessionHasErrors(['resolution_id' => "This resolution can't be used yet — voting must be finished and the result recorded as passed."]);

        // A passed resolution that has since been implemented still counts.
        $paper->update(['status' => 'implemented', 'outcome' => 'carried', 'closed_at' => now()]);

        $this->actingAs($admin)->get("/governance/strategy/{$plan->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('approval.key', 'passed')
                ->where('canApprove', true)
                ->has('carriedResolutions', 1)
                ->where('carriedResolutions.0.id', $paper->id));

        $this->actingAs($admin)->post("/governance/strategy/{$plan->id}/approve", [
            'resolution_id' => $paper->id,
        ])->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Board approval recorded. This version is now the approved strategic plan.');

        $this->assertSame('approved', $plan->fresh()->status);
    }

    public function test_approved_plan_is_read_only_until_a_new_version_is_created(): void
    {
        $admin = $this->createAdminUser();
        $plan = $this->createStrategicPlan($admin, ['title' => 'Approved plan']);
        $resolution = $this->createBoundCarriedResolution($admin, GovernanceResolutionBinding::SUBJECT_STRATEGIC_PLAN, $plan->id);
        $plan->approve($resolution->id, $admin->id);

        $this->actingAs($admin)->get("/governance/strategy/{$plan->id}?edit=1")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('canEdit', false)
                ->where('canAddGoal', false)
                ->where('canApprove', false)
                ->where('canCreateVersion', true)
                ->where('formOptions', null)
                ->where('approval.key', 'approved'));

        $this->actingAs($admin)->put("/governance/strategy/{$plan->id}", [
            'title' => 'Quietly renamed',
            'period_start' => $plan->period_start->toDateString(),
            'period_end' => $plan->period_end->toDateString(),
        ])->assertSessionHas('error', "This plan has been approved by the board, so it can't be edited. Create a new version to change it.");

        $this->actingAs($admin)->post("/governance/strategy/{$plan->id}/goals", [
            'title' => 'Sneaked-in goal',
            'description' => 'Not approved by the board',
        ])->assertSessionHas('error', "This plan has been approved by the board, so it can't be edited. Create a new version to change it.");

        $plan->refresh();
        $this->assertSame('Approved plan', $plan->title);
        $this->assertSame(0, $plan->goals()->count());

        $this->actingAs($admin)->post("/governance/strategy/{$plan->id}/version", [
            'version_notes' => 'Annual refresh',
        ])->assertSessionHasNoErrors();

        $version = StrategicPlan::query()->where('supersedes_plan_id', $plan->id)->firstOrFail();
        $this->assertSame('draft', $version->status);

        $this->actingAs($admin)->get("/governance/strategy/{$version->id}")
            ->assertInertia(fn ($page) => $page->where('canEdit', true));
    }

    public function test_editing_a_plan_cannot_mark_it_approved(): void
    {
        $admin = $this->createAdminUser();
        $plan = $this->createStrategicPlan($admin);

        $this->actingAs($admin)->put("/governance/strategy/{$plan->id}", [
            'title' => 'Strategic Plan',
            'period_start' => $plan->period_start->toDateString(),
            'period_end' => $plan->period_end->toDateString(),
            'status' => 'approved',
        ])->assertSessionHasErrors(['status' => 'A plan is approved by recording the board’s approval, not by editing it.']);

        $this->assertSame('draft', $plan->fresh()->status);
    }

    public function test_unwritten_vision_and_mission_are_empty_not_placeholders(): void
    {
        $admin = $this->createAdminUser();
        $legacy = $this->createStrategicPlan($admin, ['vision_statement' => 'TBD', 'mission_statement' => 'tbd']);

        $this->actingAs($admin)->get("/governance/strategy/{$legacy->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('plan.vision_statement', null)
                ->where('plan.mission_statement', null));

        $this->actingAs($admin)->post('/governance/strategy', [
            'title' => 'Plan without a vision yet',
            'planning_horizon' => '3_year',
            'period_start' => now()->toDateString(),
            'period_end' => now()->addYears(3)->toDateString(),
            'vision_statement' => '',
            'mission_statement' => null,
        ])->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Strategic plan saved as a draft.');

        $plan = StrategicPlan::query()->where('title', 'Plan without a vision yet')->firstOrFail();
        $this->assertNull($plan->vision_statement);
        $this->assertNull($plan->mission_statement);
    }

    public function test_view_only_member_gets_no_plan_wizard_or_approval_options(): void
    {
        $admin = $this->createAdminUser();
        $observer = $this->createUserWithRole('board_observer');
        $plan = $this->createStrategicPlan($admin);
        $this->createBoundCarriedResolution($admin, GovernanceResolutionBinding::SUBJECT_STRATEGIC_PLAN, $plan->id);

        $this->actingAs($observer)->get('/governance/strategy/create')->assertForbidden();
        $this->actingAs($observer)->get("/governance/strategy/{$plan->id}/edit")->assertForbidden();

        $this->actingAs($observer)->get('/governance/strategy')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('canCreate', false)
                ->where('formOptions', null));

        $this->actingAs($observer)->get("/governance/strategy/{$plan->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('canEdit', false)
                ->where('canApprove', false)
                ->where('formOptions', null)
                ->has('carriedResolutions', 0));

        $this->actingAs($admin)->get("/governance/strategy/{$plan->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('carriedResolutions', 1));
    }

    public function test_wizard_creates_plan_with_values_and_nested_goals(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin)->post('/governance/strategy', [
            'title' => 'Strategic Plan 2027-2030',
            'planning_horizon' => '3_year',
            'period_start' => '2027-01-01',
            'period_end' => '2029-12-31',
            'vision_statement' => 'Every person lives a good life at home.',
            'mission_statement' => 'Safe, person-led supported living.',
            'values' => [
                ['value' => 'Manaakitanga', 'description' => 'Care and respect'],
                ['value' => 'Integrity', 'description' => null],
            ],
            'goals' => [
                [
                    'title' => 'Zero avoidable harm',
                    'description' => 'Reduce restrictive practice and medication errors.',
                    'pillar' => 'safety',
                    'timeframe' => '',
                    'key_results' => [['result' => 'Medication errors under 1 per 1,000 doses']],
                ],
                [
                    'title' => 'Stable workforce',
                    'description' => 'Retain experienced support workers.',
                    'pillar' => 'people',
                    'timeframe' => '2027-2028',
                    'key_results' => [],
                ],
            ],
        ])->assertRedirect('/governance/strategy')->assertSessionHasNoErrors();

        $plan = StrategicPlan::query()->where('title', 'Strategic Plan 2027-2030')->firstOrFail();
        $this->assertSame('Manaakitanga', $plan->values[0]['value']);
        $this->assertSame(2, $plan->goals()->count());

        $harm = $plan->goals()->where('title', 'Zero avoidable harm')->firstOrFail();
        $this->assertSame('safety', $harm->pillar);
        $this->assertSame('2027-01-01 - 2029-12-31', $harm->timeframe);
        $this->assertSame($admin->id, (int) $harm->lead_executive_id);
        $this->assertSame([['result' => 'Medication errors under 1 per 1,000 doses', 'status' => 'not_started']], $harm->key_results);
        $this->assertSame('2027-2028', $plan->goals()->where('title', 'Stable workforce')->value('timeframe'));
    }

    public function test_wizard_goal_validation_and_edit_adds_goals_without_resending_legacy_horizon(): void
    {
        $admin = $this->createAdminUser();
        $plan = $this->createStrategicPlan($admin, ['planning_horizon' => '1_year', 'title' => 'Legacy annual plan']);

        $this->actingAs($admin)->put("/governance/strategy/{$plan->id}", [
            'title' => 'Legacy annual plan',
            'period_start' => $plan->period_start->toDateString(),
            'period_end' => $plan->period_end->toDateString(),
            'goals' => [['title' => 'Missing description', 'description' => '', 'pillar' => 'unknown']],
        ])->assertSessionHasErrors(['goals.0.description', 'goals.0.pillar']);
        $this->assertSame(0, $plan->goals()->count());

        $this->actingAs($admin)->put("/governance/strategy/{$plan->id}", [
            'title' => 'Renamed annual plan',
            'period_start' => $plan->period_start->toDateString(),
            'period_end' => $plan->period_end->toDateString(),
            'vision_statement' => 'Updated vision',
            'mission_statement' => 'Updated mission',
            'values' => [['value' => 'Respect', 'description' => null]],
            'goals' => [['title' => 'Open a respite home', 'description' => 'Short breaks for whānau.', 'pillar' => 'quality', 'key_results' => [['result' => 'Home open by June']]]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $plan->refresh();
        $this->assertSame('Renamed annual plan', $plan->title);
        $this->assertSame('1_year', $plan->planning_horizon);
        $this->assertSame('Updated vision', $plan->vision_statement);
        $this->assertSame(1, $plan->goals()->count());
    }

    public function test_register_filters_narrow_rows_but_not_summary(): void
    {
        $admin = $this->createAdminUser();
        $this->createStrategicPlan($admin, ['title' => 'Care quality plan']);
        $approved = $this->createStrategicPlan($admin, ['title' => 'Property plan', 'planning_horizon' => '5_year', 'status' => 'approved']);
        $approved->goals()->create([
            'title' => 'Buy homes',
            'description' => 'Two homes',
            'pillar' => 'finance',
            'timeframe' => '2026-2030',
            'progress_pct' => 40,
            'lead_executive_id' => $admin->id,
        ]);

        $this->actingAs($admin)->get('/governance/strategy?status=approved&horizon=5_year&search=Property')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('plans.data', 1)
                ->where('plans.data.0.title', 'Property plan')
                ->where('plans.data.0.progress_pct', 40)
                ->where('summary.total', 2)
                ->where('summary.draft', 1)
                ->where('summary.approved', 1)
                ->where('inEffect.id', $approved->id)
                ->where('filters.status', 'approved')
                ->where('filters.horizon', '5_year'));

        $this->actingAs($admin)->get('/governance/strategy?status=bogus')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('plans.data', 2)
                ->where('filters.status', null));
    }

    public function test_admin_can_add_goal_and_approve_plan(): void
    {
        $admin = $this->createAdminUser();
        $plan = $this->createStrategicPlan($admin);

        $goalResponse = $this->actingAs($admin)->post("/governance/strategy/{$plan->id}/goals", [
            'title' => 'Improve quality',
            'description' => 'Lift quality outcomes',
            'order' => 1,
        ]);
        $goalResponse->assertRedirect();

        $this->assertDatabaseHas('strategic_goals', [
            'strategic_plan_id' => $plan->id,
            'title' => 'Improve quality',
        ]);

        $resolution = $this->createBoundCarriedResolution($admin, GovernanceResolutionBinding::SUBJECT_STRATEGIC_PLAN, $plan->id, [
            'title' => 'Approve Strategic Plan',
        ]);

        $approveResponse = $this->actingAs($admin)->post("/governance/strategy/{$plan->id}/approve", [
            'resolution_id' => $resolution->id,
        ]);
        $approveResponse->assertRedirect();

        $plan->refresh();
        $this->assertEquals('approved', $plan->status);
        $this->assertEquals($resolution->id, $plan->approval_resolution_id);
        $this->assertNotNull($plan->approved_by_board_at);
        $this->assertNotNull($plan->last_snapshot);
        $this->assertCount(1, $plan->last_snapshot);
    }

    public function test_plan_approval_rejects_draft_open_or_defeated_resolution(): void
    {
        $admin = $this->createAdminUser();
        $plan = $this->createStrategicPlan($admin);

        // Draft resolution
        $draftRes = $this->createResolution($admin, [
            'status' => 'draft',
            'outcome' => 'pending',
        ]);

        $response = $this->actingAs($admin)->post("/governance/strategy/{$plan->id}/approve", [
            'resolution_id' => $draftRes->id,
        ]);
        $response->assertSessionHasErrors('resolution_id');
        $plan->refresh();
        $this->assertEquals('draft', $plan->status);

        // Defeated resolution
        $defeatedRes = $this->createResolution($admin, [
            'status' => 'closed',
            'outcome' => 'defeated',
        ]);

        $response2 = $this->actingAs($admin)->post("/governance/strategy/{$plan->id}/approve", [
            'resolution_id' => $defeatedRes->id,
        ]);
        $response2->assertSessionHasErrors('resolution_id');
        $plan->refresh();
        $this->assertEquals('draft', $plan->status);
    }

    public function test_plan_approval_rejects_already_used_resolution_for_active_plan(): void
    {
        $admin = $this->createAdminUser();
        $planA = $this->createStrategicPlan($admin);
        $planB = $this->createStrategicPlan($admin, ['title' => 'Second Plan']);

        $resolution = $this->createBoundCarriedResolution($admin, GovernanceResolutionBinding::SUBJECT_STRATEGIC_PLAN, $planA->id, [
            'title' => 'Approve Strategic Plan',
        ]);

        $this->actingAs($admin)->post("/governance/strategy/{$planA->id}/approve", [
            'resolution_id' => $resolution->id,
        ])->assertRedirect();

        $planA->refresh();
        $this->assertEquals('approved', $planA->status);

        // Attempting to use the same carried resolution for another active plan must fail
        $response = $this->actingAs($admin)->post("/governance/strategy/{$planB->id}/approve", [
            'resolution_id' => $resolution->id,
        ]);
        $response->assertSessionHasErrors('resolution_id');
        $planB->refresh();
        $this->assertEquals('draft', $planB->status);
    }

    public function test_plan_approval_supersedes_prior_plan_and_captures_snapshot(): void
    {
        $admin = $this->createAdminUser();
        $planA = $this->createStrategicPlan($admin, ['version_number' => 1]);

        $res1 = $this->createBoundCarriedResolution($admin, GovernanceResolutionBinding::SUBJECT_STRATEGIC_PLAN, $planA->id, [
            'title' => 'Approve Strategic Plan',
        ]);

        $planA->approve($res1->id);
        $planA->refresh();
        $this->assertEquals('approved', $planA->status);

        // Create version 2
        $planB = $planA->createNewVersion('Annual refresh', $admin->id);
        $this->assertEquals(2, $planB->version_number);
        $this->assertEquals($planA->id, $planB->supersedes_plan_id);
        $this->assertEquals('draft', $planB->status);

        $res2 = $this->createBoundCarriedResolution($admin, GovernanceResolutionBinding::SUBJECT_STRATEGIC_PLAN, $planB->id, [
            'title' => 'Approve Strategic Plan Refresh',
        ]);

        $planB->approve($res2->id);
        $planB->refresh();
        $planA->refresh();

        $this->assertEquals('approved', $planB->status);
        $this->assertEquals('superseded', $planA->status);
    }

    public function test_version_creation_preserves_goal_lineage_and_prevents_false_positive_added_changes(): void
    {
        $admin = $this->createAdminUser();
        $planA = $this->createStrategicPlan($admin, ['version_number' => 1]);

        $goal = $planA->goals()->create([
            'title' => 'Expand specialist care',
            'description' => 'Add two regional centers',
            'pillar' => 'quality',
            'timeframe' => '2026-2029',
            'progress_pct' => 10,
            'status' => 'in_progress',
            'lead_executive_id' => $admin->id,
            'order' => 1,
        ]);

        $res1 = $this->createBoundCarriedResolution($admin, GovernanceResolutionBinding::SUBJECT_STRATEGIC_PLAN, $planA->id, [
            'title' => 'Approve Strategic Plan',
        ]);

        $planA->approve($res1->id);
        $planA->refresh();

        // Create new version
        $planB = $planA->createNewVersion('Update targets', $admin->id);
        $planB->refresh();

        $newGoal = $planB->goals()->first();
        $this->assertNotNull($newGoal);
        $this->assertNotEquals($goal->id, $newGoal->id);
        $this->assertEquals($goal->id, $newGoal->origin_goal_id);

        // Compare changes on planB before modifications
        $changeData = $planB->getChangesSinceLastSnapshot();
        $this->assertTrue($changeData['has_snapshot']);
        $this->assertSame('version 1, which the board approved', $changeData['baseline_label']);
        $this->assertEmpty($changeData['changes'], 'Cloned goals must preserve lineage and not be falsely reported as added.');

        // Now modify goal on Plan B
        $newGoal->update([
            'progress_pct' => 60,
            'status' => 'achieved',
        ]);

        // Add a brand new goal on Plan B
        $planB->goals()->create([
            'title' => 'Digital Health Record',
            'description' => 'Rollout EHR',
            'pillar' => 'it_resilience',
            'timeframe' => '2026-2029',
            'lead_executive_id' => $admin->id,
            'order' => 2,
        ]);

        $diffs = $planB->getChangesSinceLastSnapshot();
        $this->assertCount(2, $diffs['changes']);

        $types = collect($diffs['changes'])->pluck('type')->toArray();
        $this->assertContains('updated', $types);
        $this->assertContains('added', $types);

        // Changes read as plain sentences, not field names.
        $updated = collect($diffs['changes'])->firstWhere('type', 'updated');
        $this->assertSame('goal', $updated['area']);
        $this->assertStringContainsString('Progress 10% → 60%', $updated['detail']);
        $this->assertSame('New goal added.', collect($diffs['changes'])->firstWhere('type', 'added')['detail']);

        // Direction changes are listed alongside goal changes.
        $planB->update(['vision_statement' => 'A new vision for the next three years']);

        $this->actingAs($admin)->get("/governance/strategy/{$planB->id}/changes")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Governance/Strategy/Changes')
                ->where('changes.baseline_label', 'version 1, which the board approved')
                ->has('changes.changes', 3)
                ->where('changes.changes.0.area', 'direction')
                ->where('changes.changes.0.goal', 'Vision')
                ->where('changes.changes.0.detail', 'Vision rewritten.'));
    }

    public function test_get_changes_without_snapshot_or_prior_baseline_returns_explicit_unavailable(): void
    {
        $admin = $this->createAdminUser();
        $plan = $this->createStrategicPlan($admin);

        $changes = $plan->getChangesSinceLastSnapshot();
        $this->assertFalse($changes['has_snapshot']);
        $this->assertEquals('Comparison not available', $changes['baseline_label']);
        $this->assertEmpty($changes['changes']);
    }

    public function test_legacy_status_mutator_and_scopes(): void
    {
        $admin = $this->createAdminUser();
        $plan = $this->createStrategicPlan($admin);

        $plan->status = 'active';
        $this->assertEquals('approved', $plan->status);

        $plan->status = 'completed';
        $this->assertEquals('archived', $plan->status);

        $plan->save();

        $this->assertTrue(StrategicPlan::active()->where('id', $plan->id)->doesntExist());
        $this->assertTrue(StrategicPlan::archived()->where('id', $plan->id)->exists());
    }
}
