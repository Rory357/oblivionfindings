<?php

namespace Tests\Feature\Governance;

use App\Domain\Governance\Models\PerformanceGoal;
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
            'board_decision' => 'maintain',
            'decision_notes' => 'Strong year',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('performance_goals', [
            'id' => $goal->id,
            'actual_score' => 4,
            'status' => 'achieved',
        ]);

        $review->refresh();
        $this->assertEquals('board_review', $review->status);
        $this->assertEquals('exceeds', $review->overall_rating);
    }

    public function test_create_and_edit_deep_links_open_the_wizard_dialogs(): void
    {
        $admin = $this->createAdminUser();
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
                ->where('board_members.0.user_id', $memberUser->id)
                ->where('board_members.0.name', $memberUser->name)
                ->has('review_cycles', 5)
                ->has('summary')
                ->has('filters'));

        $this->actingAs($admin)->get("/governance/performance/{$review->id}/edit")
            ->assertRedirect("/governance/performance/{$review->id}?edit=1");
        $this->actingAs($admin)->get("/governance/performance/{$review->id}?edit=1")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Governance/Performance/Show')
                ->where('can_update', true));
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
                ->where('board_members', [])
                ->has('reviews.data', 1)
                ->where('reviews.data.0.overall_rating', null)
                ->where('reviews.data.0.overall_assessment', null)
                ->where('summary.board_review', 1));

        $this->actingAs($ceo)->get("/governance/performance/{$review->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can_update', false)
                ->where('review.overall_assessment', null));

        $this->actingAs($admin)->get('/governance/performance?status=completed')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('reviews.data', 0)
                ->where('filters.status', 'completed')
                ->where('summary.total', 1));
    }
}
