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
}
