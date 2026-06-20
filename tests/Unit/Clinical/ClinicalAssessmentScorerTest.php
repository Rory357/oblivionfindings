<?php

namespace Tests\Unit\Clinical;

use App\Domain\Clinical\Enums\ClinicalAssessmentType;
use App\Domain\Clinical\Enums\ClinicalRiskBand;
use App\Domain\Clinical\Services\Assessments\BradenScorer;
use App\Domain\Clinical\Services\Assessments\ClinicalAssessmentScorerRegistry;
use App\Domain\Clinical\Services\Assessments\FratScorer;
use App\Domain\Clinical\Services\Assessments\IddsiClassifier;
use App\Domain\Clinical\Services\Assessments\MustScorer;
use Tests\TestCase;

/**
 * Reference-case tests pin each clinical tool to its published scoring. A
 * regression in a cut-point — which could mis-band a real client — fails here.
 */
class ClinicalAssessmentScorerTest extends TestCase
{
    // ── MUST (BAPEN) ───────────────────────────────────────────────────────

    public function test_must_all_low_scores_zero(): void
    {
        $r = (new MustScorer)->score(['bmi' => 25, 'weight_loss_percent' => 2, 'acute_disease_effect' => false]);

        $this->assertSame(0, $r->score);
        $this->assertSame(ClinicalRiskBand::Low, $r->band);
        $this->assertSame('MUST 0 — Low risk', $r->summary);
    }

    public function test_must_one_point_is_medium(): void
    {
        // BMI 19 (1) + weight loss 3% (0) + no acute (0) = 1.
        $r = (new MustScorer)->score(['bmi' => 19, 'weight_loss_percent' => 3, 'acute_disease_effect' => false]);

        $this->assertSame(1, $r->score);
        $this->assertSame(ClinicalRiskBand::Medium, $r->band);
    }

    public function test_must_max_is_high(): void
    {
        // BMI 17 (2) + weight loss 12% (2) + acute (2) = 6.
        $r = (new MustScorer)->score(['bmi' => 17, 'weight_loss_percent' => 12, 'acute_disease_effect' => true]);

        $this->assertSame(6, $r->score);
        $this->assertSame(ClinicalRiskBand::High, $r->band);
        $this->assertTrue($r->band->needsAction());
    }

    public function test_must_bmi_boundaries(): void
    {
        $must = new MustScorer;
        $this->assertSame(0, $must->score(['bmi' => 20.1])->breakdown[0]['points']); // >20 → 0
        $this->assertSame(1, $must->score(['bmi' => 20.0])->breakdown[0]['points']); // 18.5–20 → 1
        $this->assertSame(1, $must->score(['bmi' => 18.5])->breakdown[0]['points']);
        $this->assertSame(2, $must->score(['bmi' => 18.4])->breakdown[0]['points']); // <18.5 → 2
    }

    public function test_must_weight_loss_boundaries(): void
    {
        $must = new MustScorer;
        $this->assertSame(0, $must->score(['weight_loss_percent' => 4.9])->breakdown[1]['points']);
        $this->assertSame(1, $must->score(['weight_loss_percent' => 5])->breakdown[1]['points']);
        $this->assertSame(1, $must->score(['weight_loss_percent' => 10])->breakdown[1]['points']);
        $this->assertSame(2, $must->score(['weight_loss_percent' => 10.1])->breakdown[1]['points']);
    }

    public function test_must_derives_bmi_from_height_and_weight(): void
    {
        // 50kg at 170cm → BMI 17.3 → 2 points.
        $r = (new MustScorer)->score(['height_cm' => 170, 'weight_kg' => 50]);

        $this->assertSame(17.3, $r->meta['bmi']);
        $this->assertSame(2, $r->breakdown[0]['points']);
    }

    // ── FRAT (Peninsula Health) ────────────────────────────────────────────

    public function test_frat_minimum_is_low(): void
    {
        $r = (new FratScorer)->score([
            'recent_falls' => 'none_12mo', 'medications' => 'none', 'psychological' => 'none', 'cognitive' => 'intact',
        ]);

        $this->assertSame(5, $r->score);
        $this->assertSame(ClinicalRiskBand::Low, $r->band);
        $this->assertSame('FRAT 5/20 — Low risk', $r->summary);
    }

    public function test_frat_maximum_is_high(): void
    {
        $r = (new FratScorer)->score([
            'recent_falls' => 'one_plus_3mo_resident', 'medications' => 'more_than_two', 'psychological' => 'severe', 'cognitive' => 'severe',
        ]);

        $this->assertSame(20, $r->score);
        $this->assertSame(ClinicalRiskBand::High, $r->band);
    }

    public function test_frat_low_medium_boundary(): void
    {
        $frat = new FratScorer;
        // 4 + 4 + 2 + 1 = 11 → Low; bump cognitive to mild (2) → 12 → Medium.
        $low = $frat->score(['recent_falls' => 'one_plus_3_12mo', 'medications' => 'more_than_two', 'psychological' => 'mild', 'cognitive' => 'intact']);
        $medium = $frat->score(['recent_falls' => 'one_plus_3_12mo', 'medications' => 'more_than_two', 'psychological' => 'mild', 'cognitive' => 'mild']);

        $this->assertSame(11, $low->score);
        $this->assertSame(ClinicalRiskBand::Low, $low->band);
        $this->assertSame(12, $medium->score);
        $this->assertSame(ClinicalRiskBand::Medium, $medium->band);
    }

    public function test_frat_medium_high_boundary(): void
    {
        $frat = new FratScorer;
        // 8 + 3 + 3 + 1 = 15 → Medium; bump cognitive to mild (2) → 16 → High.
        $medium = $frat->score(['recent_falls' => 'one_plus_3mo_resident', 'medications' => 'two', 'psychological' => 'moderate', 'cognitive' => 'intact']);
        $high = $frat->score(['recent_falls' => 'one_plus_3mo_resident', 'medications' => 'two', 'psychological' => 'moderate', 'cognitive' => 'mild']);

        $this->assertSame(15, $medium->score);
        $this->assertSame(ClinicalRiskBand::Medium, $medium->band);
        $this->assertSame(16, $high->score);
        $this->assertSame(ClinicalRiskBand::High, $high->band);
    }

    public function test_frat_unanswered_factor_defaults_to_lowest(): void
    {
        $r = (new FratScorer)->score(['recent_falls' => 'none_12mo']); // others missing

        $this->assertSame(5, $r->score); // 2 + 1 + 1 + 1
        $this->assertSame('Not specified', $r->breakdown[1]['detail']);
    }

    // ── Braden ─────────────────────────────────────────────────────────────

    public function test_braden_best_is_minimal(): void
    {
        $r = (new BradenScorer)->score([
            'sensory_perception' => 4, 'moisture' => 4, 'activity' => 4, 'mobility' => 4, 'nutrition' => 4, 'friction_shear' => 3,
        ]);

        $this->assertSame(23, $r->score);
        $this->assertSame(ClinicalRiskBand::Minimal, $r->band);
    }

    public function test_braden_worst_is_very_high(): void
    {
        $r = (new BradenScorer)->score([
            'sensory_perception' => 1, 'moisture' => 1, 'activity' => 1, 'mobility' => 1, 'nutrition' => 1, 'friction_shear' => 1,
        ]);

        $this->assertSame(6, $r->score);
        $this->assertSame(ClinicalRiskBand::VeryHigh, $r->band);
    }

    public function test_braden_band_boundaries(): void
    {
        $braden = new BradenScorer;
        $band = fn (array $i) => $braden->score($i)->band;

        // 9 → VeryHigh, 10 → High
        $this->assertSame(ClinicalRiskBand::VeryHigh, $band(['sensory_perception' => 1, 'moisture' => 1, 'activity' => 1, 'mobility' => 1, 'nutrition' => 2, 'friction_shear' => 3]));
        $this->assertSame(ClinicalRiskBand::High, $band(['sensory_perception' => 1, 'moisture' => 1, 'activity' => 1, 'mobility' => 2, 'nutrition' => 2, 'friction_shear' => 3]));
        // 14 → Medium, 15 → Low
        $this->assertSame(ClinicalRiskBand::Medium, $band(['sensory_perception' => 2, 'moisture' => 3, 'activity' => 3, 'mobility' => 2, 'nutrition' => 2, 'friction_shear' => 2]));
        $this->assertSame(ClinicalRiskBand::Low, $band(['sensory_perception' => 3, 'moisture' => 3, 'activity' => 3, 'mobility' => 2, 'nutrition' => 2, 'friction_shear' => 2]));
        // 18 → Low, 19 → Minimal
        $this->assertSame(ClinicalRiskBand::Low, $band(['sensory_perception' => 3, 'moisture' => 4, 'activity' => 3, 'mobility' => 3, 'nutrition' => 3, 'friction_shear' => 2]));
        $this->assertSame(ClinicalRiskBand::Minimal, $band(['sensory_perception' => 4, 'moisture' => 4, 'activity' => 3, 'mobility' => 3, 'nutrition' => 3, 'friction_shear' => 2]));
    }

    public function test_braden_clamps_out_of_range_and_missing(): void
    {
        // Out-of-range high clamps to max; missing subscale defaults to max.
        $r = (new BradenScorer)->score([
            'sensory_perception' => 9, 'moisture' => 4, 'activity' => 4, 'mobility' => 4, 'nutrition' => 4, // friction missing
        ]);

        $this->assertSame(23, $r->score); // 4+4+4+4+4 + friction max(3)
        $this->assertSame(3, $r->breakdown[5]['points']);
    }

    // ── IDDSI (classification, not a score) ─────────────────────────────────

    public function test_iddsi_captures_levels_without_a_score(): void
    {
        $r = (new IddsiClassifier)->score(['drink_level' => 2, 'food_level' => 5]);

        $this->assertNull($r->score);
        $this->assertNull($r->band);
        $this->assertSame('IDDSI · Drinks L2 (Mildly Thick) · Food L5 (Minced & Moist)', $r->summary);
        $this->assertSame(2, $r->meta['drink_level']);
        $this->assertSame('Minced & Moist', $r->meta['food_label']);
    }

    public function test_iddsi_handles_partial_and_invalid_levels(): void
    {
        $iddsi = new IddsiClassifier;
        $this->assertSame('IDDSI · Drinks L0 (Thin)', $iddsi->score(['drink_level' => 0])->summary);
        $this->assertNull($iddsi->score(['food_level' => 9])->meta['food_level']); // out of 3–7 range
        $this->assertSame('IDDSI — no levels specified', $iddsi->score([])->summary);
    }

    // ── Registry + serialization ───────────────────────────────────────────

    public function test_registry_routes_to_the_right_scorer(): void
    {
        $registry = new ClinicalAssessmentScorerRegistry(new FratScorer, new BradenScorer, new MustScorer, new IddsiClassifier);

        $this->assertSame(ClinicalAssessmentType::MalnutritionMust, $registry->score(ClinicalAssessmentType::MalnutritionMust, ['bmi' => 25])->type);
        $this->assertSame(ClinicalAssessmentType::FallsFrat, $registry->for(ClinicalAssessmentType::FallsFrat)->type());
    }

    public function test_result_serializes_for_the_frontend(): void
    {
        $array = (new MustScorer)->score(['bmi' => 17, 'weight_loss_percent' => 12, 'acute_disease_effect' => true])->toArray();

        $this->assertSame('malnutrition_must', $array['type']);
        $this->assertSame('BAPEN MUST (2003)', $array['tool_version']);
        $this->assertSame('critical', $array['band_tone']);
        $this->assertTrue($array['needs_action']);
        $this->assertIsArray($array['breakdown']);
    }
}
