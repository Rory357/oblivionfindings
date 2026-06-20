<?php

namespace App\Domain\Clinical\Services\Assessments;

use App\Domain\Clinical\Enums\ClinicalAssessmentType;
use App\Domain\Clinical\Enums\ClinicalRiskBand;

/**
 * Malnutrition Universal Screening Tool (BAPEN MUST, 2003).
 *
 * Three components, each 0–2, summed to a 0–6 total:
 *   Step 1  BMI (kg/m²):  >20 → 0,  18.5–20 → 1,  <18.5 → 2
 *   Step 2  Unplanned weight loss in 3–6 months:  <5% → 0,  5–10% → 1,  >10% → 2
 *   Step 3  Acute disease effect (acutely ill AND no/▼ nutritional intake >5 days):  yes → 2
 *
 * Overall risk: 0 → low, 1 → medium, ≥2 → high.
 *
 * Inputs: `bmi` (float) OR `height_cm` + `weight_kg` to derive it; plus
 * `weight_loss_percent` (float) and `acute_disease_effect` (bool).
 */
class MustScorer implements ClinicalAssessmentScorer
{
    public function type(): ClinicalAssessmentType
    {
        return ClinicalAssessmentType::MalnutritionMust;
    }

    public function score(array $inputs): ClinicalAssessmentResult
    {
        $bmi = $this->resolveBmi($inputs);
        $weightLoss = $this->floatOrZero($inputs['weight_loss_percent'] ?? null);
        $acuteDisease = (bool) ($inputs['acute_disease_effect'] ?? false);

        $bmiPoints = $this->scoreBmi($bmi);
        $weightLossPoints = $this->scoreWeightLoss($weightLoss);
        $acutePoints = $acuteDisease ? 2 : 0;

        $total = $bmiPoints + $weightLossPoints + $acutePoints;
        $band = $this->band($total);

        $breakdown = [
            [
                'key' => 'bmi',
                'label' => 'BMI',
                'detail' => $bmi === null ? 'Not provided' : number_format($bmi, 1).' kg/m²',
                'points' => $bmiPoints,
            ],
            [
                'key' => 'weight_loss',
                'label' => 'Unplanned weight loss (3–6 months)',
                'detail' => number_format($weightLoss, 1).'%',
                'points' => $weightLossPoints,
            ],
            [
                'key' => 'acute_disease',
                'label' => 'Acute disease effect (no intake >5 days)',
                'detail' => $acuteDisease ? 'Yes' : 'No',
                'points' => $acutePoints,
            ],
        ];

        return new ClinicalAssessmentResult(
            type: $this->type(),
            score: $total,
            band: $band,
            summary: "MUST {$total} — {$band->label()}",
            advice: $this->advice($band),
            breakdown: $breakdown,
            meta: ['bmi' => $bmi],
        );
    }

    private function scoreBmi(?float $bmi): int
    {
        if ($bmi === null) {
            // Defensive floor only — the store endpoint requires a BMI basis (direct
            // BMI, or a height+weight pair), so a null BMI cannot reach storage. If
            // BMI is truly unobtainable, BAPEN directs using surrogate measures
            // (MUAC / ulna length) rather than scoring it 0.
            return 0;
        }

        return match (true) {
            $bmi > 20.0 => 0,
            $bmi >= 18.5 => 1,
            default => 2,
        };
    }

    private function scoreWeightLoss(float $percent): int
    {
        return match (true) {
            $percent < 5.0 => 0,
            $percent <= 10.0 => 1,
            default => 2,
        };
    }

    private function band(int $total): ClinicalRiskBand
    {
        return match (true) {
            $total >= 2 => ClinicalRiskBand::High,
            $total === 1 => ClinicalRiskBand::Medium,
            default => ClinicalRiskBand::Low,
        };
    }

    private function advice(ClinicalRiskBand $band): string
    {
        return match ($band) {
            ClinicalRiskBand::High => 'Treat — refer to dietitian, set goals and monitor; repeat screening per setting.',
            ClinicalRiskBand::Medium => 'Observe — document dietary intake for 3 days, then re-screen.',
            default => 'Routine clinical care — re-screen (hospital weekly, care home monthly, community annually).',
        };
    }

    private function resolveBmi(array $inputs): ?float
    {
        $bmi = $this->floatOrNull($inputs['bmi'] ?? null);
        if ($bmi !== null && $bmi > 0) {
            return $bmi;
        }

        $heightCm = $this->floatOrNull($inputs['height_cm'] ?? null);
        $weightKg = $this->floatOrNull($inputs['weight_kg'] ?? null);
        if ($heightCm !== null && $heightCm > 0 && $weightKg !== null && $weightKg > 0) {
            $heightM = $heightCm / 100;

            return round($weightKg / ($heightM * $heightM), 1);
        }

        return null;
    }

    private function floatOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function floatOrZero(mixed $value): float
    {
        return $this->floatOrNull($value) ?? 0.0;
    }
}
