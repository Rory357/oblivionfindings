<?php

namespace Tests\Feature\HealthSafety;

use App\Models\HsEvent;
use App\Models\HsRiskAssessment;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\HsRiskAssessmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HsRiskAssessmentTest extends TestCase
{
    use RefreshDatabase;

    private HsRiskAssessmentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(HsRiskAssessmentService::class);
    }

    // ──────────────────────────────────────────────────────
    // 5x5 Matrix scoring
    // ──────────────────────────────────────────────────────

    public function test_score_calculation_low(): void
    {
        $result = HsRiskAssessment::calculateScore(1, 2);
        $this->assertEquals(2, $result['score']);
        $this->assertEquals('low', $result['level']);
    }

    public function test_score_calculation_medium(): void
    {
        $result = HsRiskAssessment::calculateScore(2, 3);
        $this->assertEquals(6, $result['score']);
        $this->assertEquals('medium', $result['level']);
    }

    public function test_score_calculation_high(): void
    {
        $result = HsRiskAssessment::calculateScore(3, 4);
        $this->assertEquals(12, $result['score']);
        $this->assertEquals('high', $result['level']);
    }

    public function test_score_calculation_extreme(): void
    {
        $result = HsRiskAssessment::calculateScore(4, 5);
        $this->assertEquals(20, $result['score']);
        $this->assertEquals('extreme', $result['level']);
    }

    public function test_score_clamps_to_valid_range(): void
    {
        $result = HsRiskAssessment::calculateScore(0, 10);
        $this->assertEquals(5, $result['score']); // clamped to 1*5
    }

    public function test_score_band_boundaries(): void
    {
        $this->assertEquals('low', HsRiskAssessment::scoreToLevel(4));
        $this->assertEquals('medium', HsRiskAssessment::scoreToLevel(5));
        $this->assertEquals('medium', HsRiskAssessment::scoreToLevel(9));
        $this->assertEquals('high', HsRiskAssessment::scoreToLevel(10));
        $this->assertEquals('high', HsRiskAssessment::scoreToLevel(15));
        $this->assertEquals('extreme', HsRiskAssessment::scoreToLevel(16));
        $this->assertEquals('extreme', HsRiskAssessment::scoreToLevel(25));
    }

    // ──────────────────────────────────────────────────────
    // Creation
    // ──────────────────────────────────────────────────────

    public function test_creates_assessment_with_calculated_score(): void
    {
        $assessment = $this->service->create([
            'title' => 'Kitchen slip hazard',
            'likelihood' => 3,
            'consequence' => 4,
        ]);

        $this->assertDatabaseHas('hs_risk_assessments', [
            'id' => $assessment->id,
            'risk_score' => 12,
            'risk_level' => 'high',
            'status' => 'draft',
        ]);

        $this->assertStringStartsWith('RA-', $assessment->reference_number);
    }

    public function test_creates_assessment_with_residual_risk(): void
    {
        $assessment = $this->service->create([
            'title' => 'Chemical storage',
            'likelihood' => 4,
            'consequence' => 5,
            'existing_controls' => 'Locked cabinet, SDS available',
            'residual_likelihood' => 2,
            'residual_consequence' => 3,
        ]);

        $this->assertEquals(20, $assessment->risk_score);
        $this->assertEquals('extreme', $assessment->risk_level);
        $this->assertEquals(6, $assessment->residual_risk_score);
        $this->assertEquals('medium', $assessment->residual_risk_level);
    }

    public function test_creates_assessment_for_site(): void
    {
        $site = Site::factory()->create();

        $assessment = $this->service->create([
            'title' => 'Site fire risk',
            'assessable_type' => Site::class,
            'assessable_id' => $site->id,
            'likelihood' => 2,
            'consequence' => 5,
        ]);

        $this->assertEquals(Site::class, $assessment->assessable_type);
        $this->assertEquals($site->id, $assessment->assessable_id);
    }

    public function test_creates_assessment_linked_to_event(): void
    {
        $event = HsEvent::factory()->high()->create();

        $assessment = $this->service->create([
            'title' => 'Post-incident risk review',
            'hs_event_id' => $event->id,
            'likelihood' => 3,
            'consequence' => 3,
        ]);

        $this->assertEquals($event->id, $assessment->hs_event_id);
        $this->assertCount(1, $event->fresh()->riskAssessments);
    }

    // ──────────────────────────────────────────────────────
    // Lifecycle
    // ──────────────────────────────────────────────────────

    public function test_activate_assessment(): void
    {
        $assessment = HsRiskAssessment::factory()->create([
            'review_frequency_days' => 90,
        ]);

        $result = $this->service->activate($assessment);

        $this->assertEquals('active', $result->status);
        $this->assertNotNull($result->approved_at);
        $this->assertNotNull($result->review_due_at);
    }

    public function test_cannot_activate_non_draft(): void
    {
        $assessment = HsRiskAssessment::factory()->active()->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->service->activate($assessment);
    }

    public function test_mark_for_review(): void
    {
        $assessment = HsRiskAssessment::factory()->active()->create();

        $result = $this->service->markForReview($assessment);

        $this->assertEquals('under_review', $result->status);
    }

    public function test_supersede_creates_new_version(): void
    {
        $assessment = HsRiskAssessment::factory()->active()->create();

        $newAssessment = $this->service->supersede($assessment, [
            'title' => 'Revised assessment',
            'likelihood' => 2,
            'consequence' => 2,
        ]);

        $assessment->refresh();
        $this->assertEquals('superseded', $assessment->status);
        $this->assertEquals($newAssessment->id, $assessment->superseded_by_id);
        $this->assertEquals('draft', $newAssessment->status);
    }

    public function test_update_residual_risk(): void
    {
        $assessment = HsRiskAssessment::factory()->create();

        $result = $this->service->updateResidualRisk($assessment, 1, 2, true);

        $this->assertEquals(2, $result->residual_risk_score);
        $this->assertEquals('low', $result->residual_risk_level);
        $this->assertTrue($result->risk_acceptable);
    }

    // ──────────────────────────────────────────────────────
    // Scopes & helpers
    // ──────────────────────────────────────────────────────

    public function test_due_for_review_scope(): void
    {
        HsRiskAssessment::factory()->dueForReview()->create();
        HsRiskAssessment::factory()->active()->create(['review_due_at' => now()->addDays(30)]);

        $this->assertCount(1, HsRiskAssessment::dueForReview()->get());
    }

    public function test_high_or_extreme_scope(): void
    {
        HsRiskAssessment::factory()->highRisk()->create();
        HsRiskAssessment::factory()->create(['likelihood' => 1, 'consequence' => 1, 'risk_score' => 1, 'risk_level' => 'low']);

        $this->assertCount(1, HsRiskAssessment::highOrExtreme()->get());
    }

    public function test_is_due_for_review_helper(): void
    {
        $assessment = HsRiskAssessment::factory()->dueForReview()->create();
        $this->assertTrue($assessment->isDueForReview());
    }
}
