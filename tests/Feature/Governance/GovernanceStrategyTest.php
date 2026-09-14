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
                ->where('canApprove', true)
                ->where('canCreateVersion', false)
                ->has('formOptions.pillars', 6));
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
        $this->assertStringContainsString('approved baseline', $changeData['baseline_label']);
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
