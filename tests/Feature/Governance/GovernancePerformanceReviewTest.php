<?php

namespace Tests\Feature\Governance;

use App\Domain\Governance\Models\PerformanceGoal;
use App\Domain\Governance\Models\PerformanceReview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

class GovernancePerformanceReviewTest extends TestCase
{
    use RefreshDatabase;
    use GovernanceTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGovernance();
    }

    public function test_admin_can_create_review_with_defaults(): void
    {
        $admin = $this->createAdminUser();
        $reviewee = $this->createAdminUser(['email' => 'reviewee@example.test']);

        $response = $this->actingAs($admin)->post('/governance/performance', [
            'reviewee_id' => $reviewee->id,
            'review_cycle' => now()->year . '-Annual',
            'review_type' => 'annual',
            'period_start' => now()->subYear()->toDateString(),
            'period_end' => now()->toDateString(),
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('performance_reviews', [
            'reviewee_id' => $reviewee->id,
            'review_type' => 'annual',
        ]);

        $reviewId = \App\Domain\Governance\Models\PerformanceReview::first()?->id;
        $this->assertDatabaseCount('performance_goals', 6);
        $this->assertDatabaseCount('performance_kpis', 9);
        $this->assertNotNull($reviewId);
    }

    public function test_admin_can_submit_assessment(): void
    {
        $admin = $this->createAdminUser();
        $review = $this->createPerformanceReview($admin, $admin);

        $goal = PerformanceGoal::create([
            'performance_review_id' => $review->id,
            'pillar' => 'finance',
            'goal_description' => 'Balance the budget',
            'success_criteria' => 'Variance within 5%',
            'weight' => 20,
            'target_score' => 3,
            'status' => 'not_started',
        ]);

        $response = $this->actingAs($admin)->post("/governance/performance/{$review->id}/assess", [
            'goal_assessments' => [
                $goal->id => [
                    'score' => 4,
                    'comments' => 'Exceeded expectations',
                ],
            ],
            'overall_rating' => 'exceeds',
            'overall_assessment' => 'A strong year across every goal.',
            'board_decision' => 'maintain',
            'decision_notes' => 'Strong year',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success', "The board's assessment is saved. The CEO won't see it until the review is completed.");

        $this->assertDatabaseHas('performance_goals', [
            'id' => $goal->id,
            'actual_score' => 4,
            'status' => 'achieved',
        ]);

        $review->refresh();
        $this->assertEquals('board_review', $review->status);
        $this->assertEquals('exceeds', $review->overall_rating);
        $this->assertEquals('A strong year across every goal.', $review->overall_assessment);
    }

    public function test_create_and_edit_deep_links_open_the_wizard_dialogs(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 14)->setTime(12, 0));

        $admin = $this->createAdminUser();
        $ceo = $this->createUserWithRole('ceo', ['name' => 'Aroha Chief']);
        $memberUser = $this->createUserWithRole('board_member');
        $this->createBoardMember($memberUser, ['board_role' => 'chair']);
        $review = $this->createPerformanceReview($admin, $admin);

        $this->actingAs($admin)->get('/governance/performance/create')
            ->assertRedirect('/governance/performance?create=1');
        $this->actingAs($admin)->get('/governance/performance?create=1')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Governance/Performance/Index')
                ->where('can_create', true)
                // The CEO is listed first; board members are not reviewees.
                ->where('reviewees.0.user_id', $ceo->id)
                ->where('reviewees.0.role_label', 'Chief executive')
                ->where('reviewees', fn ($reviewees) => collect($reviewees)
                    ->pluck('user_id')
                    ->doesntContain($memberUser->id))
                // Previous, current and next financial year: one annual
                // review and four quarters each.
                ->has('review_cycles', 15)
                ->where('current_financial_year', '2026/27')
                ->where('current_cycle', '2026-Q3')
                ->has('summary')
                ->has('filters'));

        $this->actingAs($admin)->get("/governance/performance/{$review->id}/edit")
            ->assertRedirect("/governance/performance/{$review->id}?edit=1");
        $this->actingAs($admin)->get("/governance/performance/{$review->id}?edit=1")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Governance/Performance/Show')
                ->where('can_update', true)
                ->where('can_assess', true));
    }

    public function test_review_cycles_are_named_for_the_nz_financial_year(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 14)->setTime(12, 0));

        $admin = $this->createAdminUser();

        $this->actingAs($admin)->get('/governance/performance')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('review_cycles', function ($cycles) {
                    $cycles = collect($cycles);
                    $current = $cycles->where('review_type', 'annual')->firstWhere('is_current', true);

                    return $cycles->where('review_type', 'annual')->pluck('value')->all() === ['2026-Annual', '2027-Annual', '2028-Annual']
                        && $current['value'] === '2027-Annual'
                        && $current['label'] === 'Annual review 2027 (financial year 2026/27)'
                        && $current['period_start'] === '2026-07-01'
                        && $current['period_end'] === '2027-06-30'
                        && $cycles->where('is_current', true)->pluck('value')->sort()->values()->all() === ['2026-Q3', '2027-Annual'];
                }));
    }

    public function test_reviewee_gets_no_wizard_options_and_masked_rows(): void
    {
        $admin = $this->createAdminUser();
        $ceo = $this->createUserWithRole('ceo');
        $this->createBoardMember($this->createUserWithRole('board_member'));
        $review = $this->createPerformanceReview($ceo, $admin, [
            'status' => 'board_review',
            'overall_rating' => 'meets',
            'overall_assessment' => 'Raw board narrative',
        ]);

        $this->actingAs($ceo)->get('/governance/performance/create')->assertForbidden();
        $this->actingAs($ceo)->get("/governance/performance/{$review->id}/edit")->assertForbidden();

        $this->actingAs($ceo)->get('/governance/performance?status=board_review')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can_create', false)
                ->where('reviewees', [])
                ->has('reviews.data', 1)
                ->where('reviews.data.0.overall_rating', null)
                ->where('reviews.data.0.overall_assessment', null)
                ->where('reviews.data.0.assessment_hidden', true)
                ->where('summary.board_review', 1)
                // The CEO reaches their own review from the sidebar.
                ->where('auth.can.governance.performance.reviewee', true));

        $this->actingAs($ceo)->get("/governance/performance/{$review->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can_update', false)
                ->where('can_assess', false)
                ->where('can_complete', false)
                ->where('completion_resolutions', [])
                ->where('review.overall_assessment', null));

        $this->actingAs($admin)->get('/governance/performance?status=completed')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('reviews.data', 0)
                ->where('filters.status', 'completed')
                ->where('summary.total', 1));
    }

    /**
     * P0-11: the CEO must not receive the board's goal scores, comments, the
     * statuses derived from them, the scorecard figures or the overall
     * rating, decision and notes until the review is completed — in the page
     * payload itself, not just the UI.
     */
    public function test_reviewee_cannot_see_the_boards_scores_until_the_review_is_completed(): void
    {
        $admin = $this->createAdminUser();
        $ceo = $this->createUserWithRole('ceo');
        [$review, $goal] = $this->assessedReviewFor($ceo, $admin);

        $hidden = $this->actingAs($ceo)->get("/governance/performance/{$review->id}");
        $hidden->assertOk()->assertInertia(fn ($page) => $page
            ->component('Governance/Performance/Show')
            ->where('assessment_hidden', true)
            ->where('board_assessment_recorded', null)
            ->where('review.overall_rating', null)
            ->where('review.overall_assessment', null)
            ->where('review.board_decision', null)
            ->where('review.decision_notes', null)
            ->where('review.goals.0.id', $goal->id)
            ->where('review.goals.0.actual_score', null)
            ->where('review.goals.0.board_assessment', null)
            ->where('review.goals.0.status', null)
            ->where('scorecard.overall_score', null)
            ->where('scorecard.overall_rating', null)
            ->where('scorecard.board_decision', null)
            ->where('scorecard.pillars.finance.score', null)
            ->where('scorecard.pillars.finance.goals.0.actual', null)
            ->where('scorecard.pillars.finance.goals.0.status', null));

        $payload = json_encode($hidden->viewData('page'));
        foreach (['BoardOnlyGoalComment', 'BoardOnlyNarrative', 'BoardOnlyDecisionNotes'] as $secret) {
            $this->assertStringNotContainsString($secret, $payload);
        }

        // The JSON variant of the page is masked the same way.
        $json = $this->actingAs($ceo)->getJson("/governance/performance/{$review->id}");
        $json->assertOk()
            ->assertJsonPath('review.overall_rating', null)
            ->assertJsonPath('review.goals.0.actual_score', null)
            ->assertJsonPath('scorecard.overall_score', null);
        $this->assertStringNotContainsString('BoardOnlyGoalComment', $json->getContent());

        // The list never carries the rating either.
        $this->actingAs($ceo)->get('/governance/performance')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('reviews.data.0.overall_rating', null)
                ->where('reviews.data.0.board_decision', null)
                ->where('reviews.data.0.assessment_hidden', true)
                ->missing('reviews.data.0.goals'));

        // The people running the review still see everything.
        $this->actingAs($admin)->get("/governance/performance/{$review->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('assessment_hidden', false)
                ->where('board_assessment_recorded', true)
                ->where('review.overall_rating', 'meets')
                ->where('review.goals.0.board_assessment', 'BoardOnlyGoalComment'));

        $this->actingAs($admin)->post("/governance/performance/{$review->id}/approve")
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('completed', $review->fresh()->status);

        $this->actingAs($ceo)->get("/governance/performance/{$review->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('assessment_hidden', false)
                ->where('board_assessment_recorded', true)
                ->where('review.overall_rating', 'meets')
                ->where('review.overall_assessment', 'BoardOnlyNarrative')
                ->where('review.board_decision', 'maintain')
                ->where('review.decision_notes', 'BoardOnlyDecisionNotes')
                ->where('review.goals.0.board_assessment', 'BoardOnlyGoalComment')
                ->where('review.goals.0.status', 'achieved'));

        $this->actingAs($ceo)->get('/governance/performance')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('reviews.data.0.overall_rating', 'meets')
                ->where('reviews.data.0.assessment_hidden', false));
    }

    /** A self-assessment is the reviewee's own — managers can't send it for them. */
    public function test_only_the_person_being_reviewed_can_send_their_self_assessment(): void
    {
        $admin = $this->createAdminUser();
        $ceo = $this->createUserWithRole('ceo');
        $review = $this->createPerformanceReview($ceo, $admin, ['status' => 'self_review']);

        $this->actingAs($admin)->post("/governance/performance/{$review->id}/self-assessment", [
            'self_assessment' => 'Written by someone else on the CEO\'s behalf.',
        ])->assertForbidden();

        $this->assertNull($review->fresh()->self_assessment_submitted_at);
    }

    /** P0-10: the reviewee sends a self-assessment once; the board reads it. */
    public function test_reviewee_sends_their_self_assessment_to_the_board_once(): void
    {
        $admin = $this->createAdminUser();
        $ceo = $this->createUserWithRole('ceo');
        $review = $this->createPerformanceReview($ceo, $admin, ['status' => 'self_review']);

        $this->actingAs($ceo)->get("/governance/performance/{$review->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('is_reviewee', true)
                ->where('can_submit_self_assessment', true));

        $this->actingAs($admin)->get("/governance/performance/{$review->id}")
            ->assertInertia(fn ($page) => $page->where('can_submit_self_assessment', false));

        $this->actingAs($ceo)->post("/governance/performance/{$review->id}/self-assessment", [
            'self_assessment' => '',
        ])->assertSessionHasErrors(['self_assessment' => 'Write your self-assessment before sending it to the board.']);

        $this->actingAs($ceo)->post("/governance/performance/{$review->id}/self-assessment", [
            'self_assessment' => 'I delivered the safety and finance goals.',
        ])->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Your self-assessment has been sent to the board.');

        $review->refresh();
        $this->assertNotNull($review->self_assessment_submitted_at);
        $this->assertSame('board_review', $review->status);

        $this->actingAs($ceo)->post("/governance/performance/{$review->id}/self-assessment", [
            'self_assessment' => 'A rewritten version.',
        ])->assertSessionHasErrors(['self_assessment' => 'This self-assessment has already been sent to the board.']);

        $this->assertSame('I delivered the safety and finance goals.', $review->fresh()->self_assessment);

        $this->actingAs($ceo)->get("/governance/performance/{$review->id}")
            ->assertInertia(fn ($page) => $page->where('can_submit_self_assessment', false));

        $this->actingAs($admin)->get("/governance/performance/{$review->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('review.self_assessment', 'I delivered the safety and finance goals.')
                ->whereNot('review.self_assessment_submitted_at', null));
    }

    /** P0-10: the review can be finished, but only once the board has assessed it. */
    public function test_review_is_completed_only_after_the_board_has_assessed_it(): void
    {
        $admin = $this->createAdminUser();
        $ceo = $this->createUserWithRole('ceo');
        $review = $this->createPerformanceReview($ceo, $admin, ['status' => 'board_review']);

        $this->actingAs($admin)->get("/governance/performance/{$review->id}")
            ->assertInertia(fn ($page) => $page
                ->where('can_complete', false)
                ->where('board_assessment_recorded', false));

        $this->actingAs($admin)->post("/governance/performance/{$review->id}/approve")
            ->assertSessionHasErrors(['resolution_id' => "Record the board's assessment (overall rating and decision) before completing the review."]);
        $this->assertSame('board_review', $review->fresh()->status);

        [$review] = $this->assessedReviewFor($ceo, $admin, $review);

        $this->actingAs($admin)->get("/governance/performance/{$review->id}")
            ->assertInertia(fn ($page) => $page
                ->where('can_complete', true)
                ->where('board_assessment_recorded', true));

        $this->actingAs($admin)->post("/governance/performance/{$review->id}/approve")
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', "Review completed. The CEO can now see the board's rating, decision and notes.");

        $review->refresh();
        $this->assertSame('completed', $review->status);
        $this->assertNotNull($review->approved_by_board_at);
        $this->assertNull($review->approval_resolution_id);

        $this->actingAs($admin)->post("/governance/performance/{$review->id}/approve")
            ->assertSessionHasErrors(['resolution_id' => 'This performance review is already complete.']);

        // A completed review's assessment is locked.
        $this->actingAs($admin)->post("/governance/performance/{$review->id}/assess", [
            'overall_rating' => 'exceeds',
            'board_decision' => 'maintain',
        ])->assertSessionHasErrors(['overall_rating' => "This review is complete, so the board's assessment can no longer change."]);
        $this->assertSame('meets', $review->fresh()->overall_rating);

        $this->actingAs($admin)->get("/governance/performance/{$review->id}")
            ->assertInertia(fn ($page) => $page
                ->where('can_assess', false)
                ->where('can_complete', false));
    }

    public function test_reviewee_cannot_assess_or_complete_their_own_review(): void
    {
        $admin = $this->createAdminUser();
        $ceo = $this->createUserWithRole('ceo');
        [$review] = $this->assessedReviewFor($ceo, $admin);

        $this->actingAs($ceo)->post("/governance/performance/{$review->id}/approve")->assertForbidden();
        $this->actingAs($ceo)->post("/governance/performance/{$review->id}/assess", [
            'overall_rating' => 'exceeds',
            'board_decision' => 'remuneration_increase',
        ])->assertForbidden();

        $this->assertSame('board_review', $review->fresh()->status);
        $this->assertSame('meets', $review->fresh()->overall_rating);
    }

    /**
     * @return array{0: PerformanceReview, 1: PerformanceGoal}
     */
    private function assessedReviewFor(User $reviewee, User $admin, ?PerformanceReview $review = null): array
    {
        $review ??= $this->createPerformanceReview($reviewee, $admin, ['status' => 'board_review']);

        $goal = PerformanceGoal::create([
            'performance_review_id' => $review->id,
            'pillar' => 'finance',
            'goal_description' => 'Balance the budget',
            'success_criteria' => 'Variance within 5%',
            'weight' => 100,
            'target_score' => 3,
            'status' => 'not_started',
        ]);

        $this->actingAs($admin)->post("/governance/performance/{$review->id}/assess", [
            'goal_assessments' => [
                $goal->id => ['score' => 4, 'comments' => 'BoardOnlyGoalComment'],
            ],
            'overall_rating' => 'meets',
            'overall_assessment' => 'BoardOnlyNarrative',
            'board_decision' => 'maintain',
            'decision_notes' => 'BoardOnlyDecisionNotes',
        ])->assertSessionHasNoErrors();

        return [$review->fresh(), $goal->fresh()];
    }
}
