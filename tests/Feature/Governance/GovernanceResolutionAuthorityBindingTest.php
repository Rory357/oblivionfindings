<?php

namespace Tests\Feature\Governance;

use App\Domain\Governance\Models\BoardCommittee;
use App\Domain\Governance\Models\Budget;
use App\Domain\Governance\Models\BudgetAdjustment;
use App\Domain\Governance\Models\BudgetLineItem;
use App\Domain\Governance\Models\GovernanceResolutionBinding;
use App\Domain\Governance\Models\GovernanceVotingProfile;
use App\Domain\Governance\Models\PerformanceGoal;
use App\Domain\Governance\Models\PerformanceReview;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Governance\Models\StrategicPlan;
use App\Domain\Governance\Services\GovernanceNestedMutationService;
use App\Domain\Governance\Services\GovernanceResolutionAuthorityService;
use App\Domain\Governance\Services\GovernanceVotingProfileService;
use App\Domain\Governance\Services\VotingService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

/**
 * GOV-R03 / GOV-R08 (post-push audit 2026-09-13): approval authority must be
 * an explicit, immutable binding to the exact record and revision, never an
 * inference from motion wording, titles, keywords or JSON hints.
 */
class GovernanceResolutionAuthorityBindingTest extends TestCase
{
    use GovernanceTestHelpers;
    use RefreshDatabase;

    private User $chair;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGovernance();
        $this->chair = $this->createAdminUser();
    }

    // ── GOV-R03: voting rules ──────────────────────────────────────────────

    public function test_audit_case_rules_motion_for_a_different_body_does_not_activate_board_profile(): void
    {
        $seededBoardProfile = GovernanceVotingProfile::query()->where('is_active', true)->whereNull('board_committee_id')->firstOrFail();
        $resolution = $this->createResolution($this->chair, [
            'title' => 'Approve voting rules for the unrelated gardening working group',
            'exact_motion' => 'Approve voting rules exclusively for the gardening working group. No change to the governing board rules.',
            'status' => 'closed',
            'outcome' => 'carried',
        ]);
        $service = app(GovernanceVotingProfileService::class);
        $profile = $service->createProfile([
            'governing_body' => 'board',
            'governing_document_reference' => 'Synthetic governing board trust deed version 12',
        ], $this->chair);

        try {
            $service->activateProfile($profile, $this->chair, $resolution);
            $this->fail('A motion that was never bound to the board profile activated it.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString("wasn't linked to these voting rules", $exception->getMessage());
        }

        $this->assertFalse($profile->fresh()->is_active);
        $this->assertNull($profile->fresh()->approved_by_resolution_id);
        $this->assertTrue($seededBoardProfile->fresh()->is_active, 'The existing board rules must remain in force.');
    }

    public function test_resolution_bound_to_working_group_rules_cannot_activate_board_rules(): void
    {
        $gardening = BoardCommittee::create(['committee_type' => 'working_group', 'name' => 'Gardening working group', 'is_active' => true]);
        $service = app(GovernanceVotingProfileService::class);
        $gardeningProfile = $service->createProfile([
            'governing_body' => 'committee',
            'board_committee_id' => $gardening->id,
            'governing_document_reference' => 'Gardening working group terms of reference',
            'governing_document_version' => 'v3',
        ], $this->chair);
        $boardProfile = $service->createProfile([
            'governing_body' => 'board',
            'governing_document_reference' => 'Synthetic governing board trust deed version 12',
        ], $this->chair);

        $resolution = $this->createBoundCarriedResolution(
            $this->chair,
            GovernanceResolutionBinding::SUBJECT_VOTING_PROFILE,
            $gardeningProfile->id,
            ['title' => 'Approve voting rules', 'exact_motion' => 'Approve voting rules.'],
        );

        try {
            $service->activateProfile($boardProfile, $this->chair, $resolution);
            $this->fail('Authority bound to the working group profile activated the board profile.');
        } catch (\InvalidArgumentException) {
            $this->assertFalse($boardProfile->fresh()->is_active);
        }

        $activated = $service->activateProfile($gardeningProfile, $this->chair, $resolution);
        $this->assertTrue($activated->is_active);
        $this->assertSame($resolution->id, $activated->approved_by_resolution_id);
        $this->assertFalse($boardProfile->fresh()->is_active);
    }

    public function test_committee_resolution_cannot_bind_or_apply_board_rules(): void
    {
        $gardening = BoardCommittee::create(['committee_type' => 'working_group', 'name' => 'Gardening working group', 'is_active' => true]);
        $service = app(GovernanceVotingProfileService::class);
        $boardProfile = $service->createProfile([
            'governing_body' => 'board',
            'governing_document_reference' => 'Synthetic governing board trust deed version 12',
        ], $this->chair);

        $committeePaper = $this->createResolution($this->chair, ['board_committee_id' => $gardening->id]);

        $this->expectException(\DomainException::class);
        app(GovernanceResolutionAuthorityService::class)->bind(
            $committeePaper,
            GovernanceResolutionBinding::SUBJECT_VOTING_PROFILE,
            $boardProfile->id,
            $this->chair,
        );
    }

    public function test_board_rules_changed_after_binding_or_activated_against_another_document_are_rejected(): void
    {
        $service = app(GovernanceVotingProfileService::class);
        $profile = $service->createProfile([
            'governing_body' => 'board',
            'governing_document_reference' => 'Trust deed 2026',
            'governing_document_version' => 'v12',
            'quorum_mode' => 'fixed_count',
            'quorum_formula' => '4',
        ], $this->chair);
        $resolution = $this->createBoundCarriedResolution($this->chair, GovernanceResolutionBinding::SUBJECT_VOTING_PROFILE, $profile->id);

        try {
            $service->activateProfile($profile, $this->chair, $resolution, 'A different constitution', 'v1');
            $this->fail('Activation against a different governing document than the one bound was accepted.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('voting rules were changed after the resolution was prepared', $exception->getMessage());
        }

        $profile->update(['quorum_formula' => '2']);

        try {
            $service->activateProfile($profile, $this->chair, $resolution);
            $this->fail('Rules changed after the board bound its approval were activated.');
        } catch (\InvalidArgumentException) {
            $this->assertFalse($profile->fresh()->is_active);
        }

        $binding = $resolution->authorityBindings()->firstOrFail();
        $this->assertNull($binding->consumed_at, 'A rejected activation must not consume the authority.');

        $profile->update(['quorum_formula' => '4']);
        $activated = $service->activateProfile($profile, $this->chair, $resolution, 'Trust deed 2026', 'v12');
        $this->assertTrue($activated->is_active);
        $this->assertNotNull($binding->fresh()->consumed_at);
    }

    public function test_bound_rules_authority_is_single_use_and_preserves_frozen_rules_at_vote_open(): void
    {
        $seededBoardProfile = GovernanceVotingProfile::query()->where('is_active', true)->whereNull('board_committee_id')->firstOrFail();
        $this->createBoardMember($this->chair);
        $openPaper = $this->createResolution($this->chair, ['status' => 'draft']);
        (new VotingService)->openVoting($openPaper, now()->addDays(2));
        $openPaper->refresh();
        $frozenProfileId = $openPaper->paper_snapshot['voting_profile']['id'];
        $frozenElectorate = $openPaper->electorate_at_open;

        $service = app(GovernanceVotingProfileService::class);
        $profile = $service->createProfile([
            'governing_body' => 'board',
            'governing_document_reference' => 'Trust deed 2026',
            'quorum_mode' => 'fixed_count',
            'quorum_formula' => '9',
        ], $this->chair);
        $resolution = $this->createBoundCarriedResolution($this->chair, GovernanceResolutionBinding::SUBJECT_VOTING_PROFILE, $profile->id);

        $service->activateProfile($profile, $this->chair, $resolution);
        $this->assertTrue($profile->fresh()->is_active);
        $this->assertFalse($seededBoardProfile->fresh()->is_active);

        // Rules and electorate frozen when voting opened are unaffected.
        $openPaper->refresh();
        $this->assertSame($seededBoardProfile->id, $frozenProfileId);
        $this->assertSame($frozenProfileId, $openPaper->paper_snapshot['voting_profile']['id']);
        $this->assertSame($frozenElectorate, $openPaper->electorate_at_open);

        // The same carried authority cannot be replayed.
        $profile->update(['is_active' => false]);
        $profile->forceFill(['approved_at' => null, 'approved_by_resolution_id' => null])->save();

        try {
            $service->activateProfile($profile, $this->chair, $resolution);
            $this->fail('A consumed rules authority was replayed.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('already been used', $exception->getMessage());
        }
    }

    // ── GOV-R08: strategic plans ───────────────────────────────────────────

    public function test_audit_case_motion_for_different_strategic_plan_does_not_approve_property_plan(): void
    {
        $resolution = $this->createResolution($this->chair, [
            'title' => 'Approve strategic plan for staff wellbeing',
            'exact_motion' => 'Approve strategic plan for staff wellbeing only. Property planning is deferred.',
            'status' => 'closed',
            'outcome' => 'carried',
        ]);
        $plan = $this->propertyPlan();

        try {
            $plan->approve($resolution->id);
            $this->fail('A strategic plan was approved by title/phrase matching.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('resolution_id', $exception->errors());
        }

        $this->assertSame('draft', $plan->fresh()->status);
        $this->assertNull($plan->fresh()->approval_resolution_id);
    }

    public function test_resolution_bound_to_wellbeing_plan_approves_only_that_plan(): void
    {
        $wellbeing = $this->createStrategicPlan($this->chair, ['title' => 'Staff wellbeing strategic plan']);
        $property = $this->propertyPlan();
        $resolution = $this->createBoundCarriedResolution($this->chair, GovernanceResolutionBinding::SUBJECT_STRATEGIC_PLAN, $wellbeing->id, [
            'title' => 'Approve strategic plan',
            'exact_motion' => 'Approve the strategic plan.',
            // JSON hints are not authority either.
            'cost_impact' => ['is_none' => true, 'strategic_plan_id' => $property->id],
        ]);

        try {
            $property->approve($resolution->id);
            $this->fail('Authority bound to the wellbeing plan approved the property plan.');
        } catch (ValidationException) {
            $this->assertSame('draft', $property->fresh()->status);
        }

        $wellbeing->approve($resolution->id, $this->chair->id);
        $this->assertSame('approved', $wellbeing->fresh()->status);
        $this->assertSame($resolution->id, $wellbeing->fresh()->approval_resolution_id);
        $this->assertNotNull($resolution->authorityBindings()->firstOrFail()->consumed_at);
    }

    public function test_plan_changed_after_binding_is_not_approved(): void
    {
        $plan = $this->propertyPlan();
        $resolution = $this->createBoundCarriedResolution($this->chair, GovernanceResolutionBinding::SUBJECT_STRATEGIC_PLAN, $plan->id);

        $plan->goals()->create([
            'title' => 'Sell two homes',
            'description' => 'Added after the board bound its approval.',
            'pillar' => 'sustainability',
            'timeframe' => '2026-2030',
            'lead_executive_id' => $this->chair->id,
        ]);

        try {
            $plan->approve($resolution->id);
            $this->fail('A plan whose content changed after the board bound its approval was approved.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('The strategic plan was edited after its resolution was prepared', $exception->errors()['resolution_id'][0]);
        }

        $this->assertSame('draft', $plan->fresh()->status);
        $this->assertNull($resolution->authorityBindings()->firstOrFail()->consumed_at);
    }

    // ── GOV-R08: budget adjustments ────────────────────────────────────────

    public function test_audit_case_budget_direction_changed_after_bound_authority_is_rejected(): void
    {
        [$budget, $line, $adjustment] = $this->equipmentAdjustment('Equipment increase');
        $resolution = $this->createBoundCarriedResolution($this->chair, GovernanceResolutionBinding::SUBJECT_BUDGET_ADJUSTMENT, $adjustment->id, [
            'title' => 'Approve equipment funding increase',
            'exact_motion' => 'Increase equipment funding by 6000.',
            'cost_impact' => ['amount' => 6000, 'budget_adjustment_id' => $adjustment->id, 'adjustment_type' => 'increase', 'budget_id' => $budget->id, 'budget_line_item_id' => $line->id],
        ]);

        $adjustment->update(['adjustment_type' => 'decrease', 'reason' => 'Changed after board approval: reduce equipment funding']);

        try {
            app(GovernanceNestedMutationService::class)->approveBudgetAdjustment($this->chair, $budget, $adjustment, $resolution->id);
            $this->fail('The adjustment direction changed after binding and was still applied.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('The resolution approved an increase, but this change is now a decrease.', $exception->errors()['approval_resolution_id'][0]);
        }

        $this->assertSame('submitted', $adjustment->fresh()->status);
        $this->assertSame('100000.00', $line->fresh()->budget_amount);
        $this->assertNull($resolution->authorityBindings()->firstOrFail()->consumed_at);
    }

    public function test_cost_impact_id_hints_and_matching_wording_are_not_budget_authority(): void
    {
        [$budget, $line, $adjustment] = $this->equipmentAdjustment('Equipment funding');
        $resolution = $this->createResolution($this->chair, [
            'title' => 'Approve Equipment funding',
            'exact_motion' => 'Increase Equipment funding by 6000.',
            'status' => 'closed',
            'outcome' => 'carried',
            'cost_impact' => ['amount' => 6000, 'budget_adjustment_id' => $adjustment->id, 'budget_id' => $budget->id, 'budget_line_item_id' => $line->id],
        ]);

        try {
            app(GovernanceNestedMutationService::class)->approveBudgetAdjustment($this->chair, $budget, $adjustment, $resolution->id);
            $this->fail('Unbound JSON hints or wording approved a budget adjustment.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString("wasn't linked to this budget change", $exception->errors()['approval_resolution_id'][0]);
        }

        $this->assertSame('submitted', $adjustment->fresh()->status);
        $this->assertSame('100000.00', $line->fresh()->budget_amount);
    }

    public function test_budget_amount_or_line_changed_after_binding_is_rejected(): void
    {
        [$budget, $line, $adjustment] = $this->equipmentAdjustment('Equipment increase');
        $otherLine = BudgetLineItem::create(['budget_id' => $budget->id, 'category' => 'operations', 'description' => 'Vehicle fleet', 'budget_amount' => 50000, 'forecast_amount' => 50000, 'actual_amount' => 0]);
        $resolution = $this->createBoundCarriedResolution($this->chair, GovernanceResolutionBinding::SUBJECT_BUDGET_ADJUSTMENT, $adjustment->id, [
            'cost_impact' => ['amount' => 6000, 'funding_source' => 'Reserves'],
        ]);
        $service = app(GovernanceNestedMutationService::class);

        $adjustment->update(['budget_line_item_id' => $otherLine->id]);
        try {
            $service->approveBudgetAdjustment($this->chair, $budget, $adjustment->fresh(), $resolution->id);
            $this->fail('A bound adjustment moved to another line was applied.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('different budget line', $exception->errors()['approval_resolution_id'][0]);
        }

        $adjustment->update(['budget_line_item_id' => $line->id, 'reason' => 'Equipment increase for a different purpose']);
        try {
            $service->approveBudgetAdjustment($this->chair, $budget, $adjustment->fresh(), $resolution->id);
            $this->fail('A bound adjustment whose terms changed was applied.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('The budget change was edited after its resolution was prepared', $exception->errors()['approval_resolution_id'][0]);
        }

        $this->assertSame('submitted', $adjustment->fresh()->status);
        $this->assertSame('100000.00', $line->fresh()->budget_amount);
        $this->assertSame('50000.00', $otherLine->fresh()->budget_amount);
    }

    public function test_legitimately_bound_catering_equipment_adjustment_reaches_106000_once(): void
    {
        [$budget, $line, $adjustment] = $this->equipmentAdjustment('Catering equipment budget', 'Accessible bathroom equipment');
        $resolution = $this->createBoundCarriedResolution($this->chair, GovernanceResolutionBinding::SUBJECT_BUDGET_ADJUSTMENT, $adjustment->id, [
            'title' => 'Approve catering equipment budget adjustment',
            'exact_motion' => 'Approve catering equipment budget adjustment',
            'cost_impact' => ['amount' => 6000, 'funding_source' => 'Operating reserves'],
        ]);

        $approved = app(GovernanceNestedMutationService::class)->approveBudgetAdjustment($this->chair, $budget, $adjustment, $resolution->id);

        $this->assertSame('approved', $approved->status);
        $this->assertSame($resolution->id, $approved->approval_resolution_id);
        $this->assertSame('106000.00', $line->fresh()->budget_amount);
        $this->assertSame('106000.00', $budget->fresh()->total_budget);

        $binding = $resolution->authorityBindings()->firstOrFail();
        $this->assertNotNull($binding->consumed_at);
        $this->assertSame($this->chair->id, (int) $binding->consumed_by);

        $authority = app(GovernanceResolutionAuthorityService::class);
        $this->expectException(\DomainException::class);
        DB::transaction(fn () => $authority->verifyAndConsume(
            $resolution,
            GovernanceResolutionBinding::SUBJECT_BUDGET_ADJUSTMENT,
            $adjustment->id,
            $authority->budgetAdjustmentTerms($adjustment->fresh()),
            $this->chair->id,
        ));
    }

    // ── Authoring path and immutability ───────────────────────────────────

    public function test_decision_paper_authoring_binds_the_exact_subject_server_side(): void
    {
        [, , $adjustment] = $this->equipmentAdjustment('Equipment increase');

        $this->actingAs($this->chair)->post('/governance/resolutions', [
            'title' => 'Approve equipment funding increase',
            'exact_motion' => 'That the board approves the submitted equipment funding increase.',
            'authority_binding' => [
                'subject_type' => GovernanceResolutionBinding::SUBJECT_BUDGET_ADJUSTMENT,
                'subject_id' => $adjustment->id,
                'subject_fingerprint' => str_repeat('0', 64),
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $resolution = Resolution::query()->where('title', 'Approve equipment funding increase')->firstOrFail();
        $binding = $resolution->authorityBindings()->firstOrFail();
        $authority = app(GovernanceResolutionAuthorityService::class);

        $this->assertSame(GovernanceResolutionBinding::SUBJECT_BUDGET_ADJUSTMENT, $binding->subject_type);
        $this->assertSame($adjustment->id, $binding->subject_id);
        $this->assertSame('increase', $binding->direction);
        $this->assertSame('6000.00', $binding->amount);
        $this->assertSame($adjustment->budget_line_item_id, $binding->budget_line_item_id);
        $this->assertSame(GovernanceResolutionAuthorityService::fingerprint($authority->budgetAdjustmentTerms($adjustment->fresh())), $binding->subject_fingerprint);

        $this->actingAs($this->chair)
            ->get("/governance/resolutions/{$resolution->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('authority_bindings.0.subject_id', $adjustment->id)
                ->where('authority_bindings.0.subject_type_label', 'Budget change')
                ->where('authority_bindings.0.subject_label', fn ($label) => is_string($label) && $label !== '')
                ->has('authoritySubjects.budget_adjustments')
                // The edit wizard renders whichever subject groups the service
                // returns, with plain group names ("What will this resolution approve?").
                ->where('authoritySubjectGroups', fn ($groups) => collect($groups)
                    ->contains(fn ($group) => $group['key'] === 'budget_adjustments'
                        && $group['label'] === 'A budget change'
                        && $group['subject_type'] === GovernanceResolutionBinding::SUBJECT_BUDGET_ADJUSTMENT)));

        // An unknown subject is a validation error, not a silent unbound paper.
        $this->actingAs($this->chair)->put("/governance/resolutions/{$resolution->id}", [
            'authority_binding' => ['subject_type' => GovernanceResolutionBinding::SUBJECT_STRATEGIC_PLAN, 'subject_id' => 999999],
        ])->assertSessionHasErrors('authority_binding');
        $this->assertSame($adjustment->id, $resolution->authorityBindings()->firstOrFail()->subject_id);

        // Draft authors can remove authority explicitly.
        $this->actingAs($this->chair)->put("/governance/resolutions/{$resolution->id}", [
            'authority_binding' => null,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertFalse($resolution->authorityBindings()->exists());
    }

    public function test_binding_cannot_change_after_publication_and_terms_are_immutable(): void
    {
        [, , $adjustment] = $this->equipmentAdjustment('Equipment increase');
        $plan = $this->propertyPlan();
        $authority = app(GovernanceResolutionAuthorityService::class);
        $resolution = $this->createResolution($this->chair);
        $binding = $authority->bind($resolution, GovernanceResolutionBinding::SUBJECT_BUDGET_ADJUSTMENT, $adjustment->id, $this->chair);

        try {
            $binding->update(['amount' => 60000]);
            $this->fail('Binding terms were mutated.');
        } catch (\DomainException) {
            $this->assertSame('6000.00', $binding->fresh()->amount);
        }

        $this->createBoardMember($this->chair);
        (new VotingService)->openVoting($resolution, now()->addDays(2));
        $resolution->refresh();
        $this->assertSame('open', $resolution->status);
        $this->assertSame($binding->subject_fingerprint, $resolution->paper_snapshot['authority_bindings'][0]['subject_fingerprint']);

        try {
            $authority->bind($resolution, GovernanceResolutionBinding::SUBJECT_STRATEGIC_PLAN, $plan->id, $this->chair);
            $this->fail('Authority was re-bound after the paper was published for voting.');
        } catch (\DomainException) {
            $this->assertSame($adjustment->id, $resolution->authorityBindings()->firstOrFail()->subject_id);
        }

        $this->expectException(\DomainException::class);
        $binding->fresh()->delete();
    }

    // ── Whole budgets ──────────────────────────────────────────────────────

    public function test_unrelated_carried_resolution_cannot_approve_a_budget(): void
    {
        $budget = $this->careBudget('Property budget');
        $other = $this->careBudget('Wellbeing budget');
        $unbound = $this->createResolution($this->chair, [
            'title' => 'Budget Approval: Property budget',
            'exact_motion' => 'Approve the Property budget as presented.',
            'decision_type' => 'budget_approval',
            'status' => 'closed',
            'outcome' => 'carried',
            'cost_impact' => ['amount' => 100000, 'budget_id' => $budget->id],
        ]);
        $boundToOther = $this->createBoundCarriedResolution($this->chair, GovernanceResolutionBinding::SUBJECT_BUDGET, $other->id);

        foreach ([$unbound, $boundToOther] as $resolution) {
            try {
                $budget->approve($resolution->id, $this->chair->id);
                $this->fail('A resolution that was never bound to this budget approved it.');
            } catch (ValidationException $exception) {
                $this->assertStringContainsString("wasn't linked to this budget", $exception->errors()['resolution_id'][0]);
            }
        }

        $this->assertSame('proposed', $budget->fresh()->status);
        $this->assertNull($budget->fresh()->approved_by_board_at);
        $this->assertNull($boundToOther->authorityBindings()->firstOrFail()->consumed_at);
    }

    public function test_bound_budget_authority_approves_once(): void
    {
        $budget = $this->careBudget('Care budget');
        $resolution = $this->createBoundCarriedResolution($this->chair, GovernanceResolutionBinding::SUBJECT_BUDGET, $budget->id);

        $binding = $resolution->authorityBindings()->firstOrFail();
        $this->assertSame('v'.$budget->version_number, $binding->subject_revision);
        $this->assertSame('100000.00', $binding->amount);
        $this->assertSame($budget->id, $binding->budget_id);

        $budget->approve($resolution->id, $this->chair->id);

        $this->assertSame('approved', $budget->fresh()->status);
        $this->assertSame($resolution->id, $budget->fresh()->approval_resolution_id);
        $this->assertNotNull($binding->fresh()->consumed_at);
        $this->assertSame($this->chair->id, (int) $binding->fresh()->consumed_by);

        // The consumed authority cannot be replayed onto the same budget.
        $budget->forceFill(['status' => 'proposed', 'approved_by_board_at' => null])->save();
        try {
            $budget->approve($resolution->id, $this->chair->id);
            $this->fail('A consumed budget authority was replayed.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('already been used', $exception->errors()['resolution_id'][0]);
        }
    }

    public function test_budget_edited_after_binding_is_rejected(): void
    {
        $budget = $this->careBudget('Care budget');
        $line = $budget->lineItems()->firstOrFail();
        $resolution = $this->createBoundCarriedResolution($this->chair, GovernanceResolutionBinding::SUBJECT_BUDGET, $budget->id);

        $line->update(['budget_amount' => 150000]);

        try {
            $budget->approve($resolution->id, $this->chair->id);
            $this->fail('A budget whose lines changed after binding was approved.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('The budget was edited after its resolution was prepared', $exception->errors()['resolution_id'][0]);
        }

        $line->update(['budget_amount' => 100000]);
        $budget->update(['title' => 'Care budget (renamed after the vote)']);

        try {
            $budget->approve($resolution->id, $this->chair->id);
            $this->fail('A budget whose identity changed after binding was approved.');
        } catch (ValidationException) {
            $this->assertSame('proposed', $budget->fresh()->status);
        }

        $this->assertNull($resolution->authorityBindings()->firstOrFail()->consumed_at);

        // Recording actual spend is operational, not a change to the approved terms.
        $budget->update(['title' => 'Care budget']);
        $line->update(['actual_amount' => 12000, 'forecast_amount' => 98000]);
        $budget->approve($resolution->id, $this->chair->id);
        $this->assertSame('approved', $budget->fresh()->status);
    }

    public function test_budget_binding_is_draft_only_and_rejects_approved_budgets(): void
    {
        $approved = $this->createBudget($this->chair, ['status' => 'approved']);
        $authority = app(GovernanceResolutionAuthorityService::class);

        try {
            $authority->bind($this->createResolution($this->chair), GovernanceResolutionBinding::SUBJECT_BUDGET, $approved->id, $this->chair);
            $this->fail('An approved budget was bound to a new decision paper.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('Only a draft budget, or one waiting for the board, can be linked to a resolution.', $exception->getMessage());
        }

        $budget = $this->careBudget('Care budget');
        $carried = $this->createResolution($this->chair, ['status' => 'closed', 'outcome' => 'carried']);
        $this->expectException(\DomainException::class);
        $authority->bind($carried, GovernanceResolutionBinding::SUBJECT_BUDGET, $budget->id, $this->chair);
    }

    // ── Performance reviews ────────────────────────────────────────────────

    public function test_unrelated_carried_resolution_cannot_approve_a_performance_review(): void
    {
        $ceo = $this->createUserWithRole('ceo');
        $review = $this->decidedReview($ceo);
        $otherReview = $this->decidedReview($this->createUserWithRole('ceo'));
        $unbound = $this->createResolution($this->chair, [
            'title' => 'Approve CEO performance review',
            'exact_motion' => 'Approve the CEO annual performance review and remuneration increase.',
            'status' => 'closed',
            'outcome' => 'carried',
        ]);
        $boundToOther = $this->createBoundCarriedResolution($this->chair, GovernanceResolutionBinding::SUBJECT_PERFORMANCE_REVIEW, $otherReview->id);

        foreach ([$unbound, $boundToOther] as $resolution) {
            $this->actingAs($this->chair)
                ->post("/governance/performance/{$review->id}/approve", ['resolution_id' => $resolution->id])
                ->assertSessionHasErrors('resolution_id');
        }

        $this->assertSame('board_review', $review->fresh()->status);
        $this->assertNull($review->fresh()->approval_resolution_id);
        $this->assertNull($boundToOther->authorityBindings()->firstOrFail()->consumed_at);
    }

    public function test_bound_performance_review_authority_approves_once(): void
    {
        $review = $this->decidedReview($this->createUserWithRole('ceo'));
        $resolution = $this->createBoundCarriedResolution($this->chair, GovernanceResolutionBinding::SUBJECT_PERFORMANCE_REVIEW, $review->id);

        $this->actingAs($this->chair)
            ->post("/governance/performance/{$review->id}/approve", ['resolution_id' => $resolution->id])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('completed', $review->fresh()->status);
        $this->assertSame($resolution->id, $review->fresh()->approval_resolution_id);
        $binding = $resolution->authorityBindings()->firstOrFail();
        $this->assertNotNull($binding->consumed_at);

        $review = $review->fresh();
        $review->forceFill(['status' => 'board_review', 'approval_resolution_id' => null, 'approved_by_board_at' => null])->save();
        try {
            $review->approve($resolution->id, $this->chair->id);
            $this->fail('A consumed performance review authority was replayed.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('already been used', $exception->errors()['resolution_id'][0]);
        }
    }

    public function test_performance_review_decision_edited_after_binding_is_rejected(): void
    {
        $review = $this->decidedReview($this->createUserWithRole('ceo'));
        $resolution = $this->createBoundCarriedResolution($this->chair, GovernanceResolutionBinding::SUBJECT_PERFORMANCE_REVIEW, $review->id);

        $review->update(['board_decision' => 'remuneration_increase']);

        try {
            $review->approve($resolution->id, $this->chair->id);
            $this->fail('A board decision changed after binding was approved.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString("The board's assessment was changed after the resolution was prepared", $exception->errors()['resolution_id'][0]);
        }

        $review->update(['board_decision' => 'maintain']);
        $review->goals()->firstOrFail()->update(['actual_score' => 5]);

        try {
            $review->approve($resolution->id, $this->chair->id);
            $this->fail('A goal score changed after binding was approved.');
        } catch (ValidationException) {
            $this->assertSame('board_review', $review->fresh()->status);
        }

        $this->assertNull($resolution->authorityBindings()->firstOrFail()->consumed_at);

        // Completing without citing a resolution records no resolution authority.
        $review->approve(null, $this->chair->id);
        $this->assertSame('completed', $review->fresh()->status);
        $this->assertNull($review->fresh()->approval_resolution_id);
    }

    public function test_performance_review_binding_never_discloses_restricted_content(): void
    {
        $ceo = $this->createUserWithRole('ceo', ['name' => 'Aroha Chief']);
        $review = $this->decidedReview($ceo);
        $resolution = $this->createResolution($this->chair);

        $binding = app(GovernanceResolutionAuthorityService::class)
            ->bind($resolution, GovernanceResolutionBinding::SUBJECT_PERFORMANCE_REVIEW, $review->id, $this->chair);

        $stored = json_encode($binding->fresh()->bound_terms);
        $this->assertStringNotContainsString('Confidential board narrative', $stored);
        $this->assertStringNotContainsString('exceeds', $stored);
        $this->assertStringNotContainsString('maintain', $stored);
        $this->assertStringNotContainsString('Balance the budget', $stored);
        $this->assertArrayNotHasKey('reviewee_id', $binding->bound_terms);
        $this->assertSame(64, strlen($binding->bound_terms['decision_digest']));

        $this->createBoardMember($this->chair);
        (new VotingService)->openVoting($resolution, now()->addDays(2));
        $this->assertStringNotContainsString(
            'Confidential board narrative',
            json_encode($resolution->fresh()->paper_snapshot),
        );
    }

    public function test_authoring_options_list_budgets_and_only_decidable_reviews_with_safe_labels(): void
    {
        $ceo = $this->createUserWithRole('ceo', ['name' => 'Aroha Chief']);
        $review = $this->decidedReview($ceo);
        $completed = $this->decidedReview($this->createUserWithRole('ceo'), ['status' => 'completed']);
        $budget = $this->careBudget('Care budget');
        $approvedBudget = $this->createBudget($this->chair, ['status' => 'approved', 'title' => 'Last year']);
        $authority = app(GovernanceResolutionAuthorityService::class);

        $options = $authority->authoringOptions($this->chair);
        $this->assertSame([$budget->id], array_column($options['budgets'], 'id'));
        $this->assertNotContains($approvedBudget->id, array_column($options['budgets'], 'id'));
        $this->assertSame([$review->id], array_column($options['performance_reviews'], 'id'));
        $this->assertNotContains($completed->id, array_column($options['performance_reviews'], 'id'));
        $label = $options['performance_reviews'][0]['label'];
        $this->assertStringContainsString('Aroha Chief', $label);
        $this->assertStringNotContainsString('Confidential', json_encode($options['performance_reviews']));
        $this->assertStringNotContainsString('exceeds', json_encode($options['performance_reviews']));

        // A board member outside the remuneration audience sees no reviews, and
        // cannot bind one directly either.
        $member = $this->createUserWithRole('board_member');
        $this->createBoardMember($member);
        $this->assertSame([], $authority->authoringOptions($member)['performance_reviews']);

        // The reviewee never sees their own review offered for decision.
        $this->assertSame([], $authority->authoringOptions($ceo)['performance_reviews']);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $authority->bind($this->createResolution($member), GovernanceResolutionBinding::SUBJECT_PERFORMANCE_REVIEW, $review->id, $member);
    }

    public function test_decision_paper_authoring_accepts_budget_and_review_subjects(): void
    {
        $budget = $this->careBudget('Care budget');
        $review = $this->decidedReview($this->createUserWithRole('ceo'));

        $this->actingAs($this->chair)->post('/governance/resolutions', [
            'title' => 'Approve care budget',
            'exact_motion' => 'That the board approves the care budget as presented.',
            'authority_binding' => ['subject_type' => GovernanceResolutionBinding::SUBJECT_BUDGET, 'subject_id' => $budget->id],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $paper = Resolution::query()->where('title', 'Approve care budget')->firstOrFail();
        $this->assertSame($budget->id, $paper->authorityBindings()->firstOrFail()->subject_id);

        $this->actingAs($this->chair)->put("/governance/resolutions/{$paper->id}", [
            'authority_binding' => ['subject_type' => GovernanceResolutionBinding::SUBJECT_PERFORMANCE_REVIEW, 'subject_id' => $review->id],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $binding = $paper->authorityBindings()->firstOrFail();
        $this->assertSame(GovernanceResolutionBinding::SUBJECT_PERFORMANCE_REVIEW, $binding->subject_type);
        $this->assertSame($review->id, $binding->subject_id);

        $this->actingAs($this->chair)
            ->get('/governance/resolutions')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('authoritySubjects.budgets.0.id', $budget->id)
                ->where('authoritySubjects.performance_reviews.0.id', $review->id));
    }

    private function careBudget(string $title): Budget
    {
        $budget = $this->createBudget($this->chair, ['title' => $title, 'status' => 'proposed']);
        BudgetLineItem::create([
            'budget_id' => $budget->id,
            'category' => 'staffing',
            'description' => 'Rostered support',
            'budget_amount' => 100000,
            'forecast_amount' => 100000,
            'actual_amount' => 0,
        ]);

        return $budget->fresh();
    }

    private function decidedReview(User $reviewee, array $overrides = []): PerformanceReview
    {
        $review = $this->createPerformanceReview($reviewee, $this->chair, array_merge([
            'status' => 'board_review',
            'overall_rating' => 'exceeds',
            'overall_assessment' => 'Confidential board narrative about the chief executive.',
            'board_decision' => 'maintain',
            'decision_notes' => 'Confidential remuneration context.',
        ], $overrides));

        PerformanceGoal::create([
            'performance_review_id' => $review->id,
            'pillar' => 'finance',
            'goal_description' => 'Balance the budget',
            'success_criteria' => 'Variance within 5%',
            'weight' => 20,
            'target_score' => 3,
            'actual_score' => 4,
            'status' => 'achieved',
        ]);

        return $review->fresh();
    }

    private function propertyPlan(): StrategicPlan
    {
        return StrategicPlan::create([
            'title' => 'Property strategic plan',
            'planning_horizon' => '5_year',
            'period_start' => '2026-01-01',
            'period_end' => '2030-12-31',
            'vision_statement' => 'Homes',
            'mission_statement' => 'Support',
            'values' => ['Respect'],
            'status' => 'draft',
            'created_by' => $this->chair->id,
        ]);
    }

    /**
     * @return array{0: Budget, 1: BudgetLineItem, 2: BudgetAdjustment}
     */
    private function equipmentAdjustment(string $reason, string $lineDescription = 'Equipment funding'): array
    {
        $budget = $this->createBudget($this->chair);
        $line = BudgetLineItem::create([
            'budget_id' => $budget->id,
            'category' => 'operations',
            'description' => $lineDescription,
            'budget_amount' => 100000,
            'forecast_amount' => 100000,
            'actual_amount' => 0,
        ]);
        $adjustment = BudgetAdjustment::create([
            'budget_id' => $budget->id,
            'budget_line_item_id' => $line->id,
            'adjustment_type' => 'increase',
            'amount' => 6000,
            'reason' => $reason,
            'proposed_by' => $this->chair->id,
            'proposed_at' => now(),
            'status' => 'submitted',
            'threshold_applies' => true,
        ]);

        return [$budget, $line, $adjustment];
    }
}
