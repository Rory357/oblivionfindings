<?php

namespace App\Domain\Clinical\Services\Assessments;

use App\Domain\Clinical\Enums\ClinicalAssessmentType;
use App\Domain\Clinical\Enums\ClinicalRiskBand;

/**
 * Braden Scale for Predicting Pressure Sore Risk (Bergstrom & Braden, 1988).
 *
 * Six subscales summed to a 6–23 total — note the scale is INVERTED: a LOWER
 * total means HIGHER risk.
 *   Sensory perception 1–4 · Moisture 1–4 · Activity 1–4 ·
 *   Mobility 1–4 · Nutrition 1–4 · Friction & shear 1–3
 *
 * Risk band (widely-used cut-points):
 *   ≤9 very high · 10–12 high · 13–14 medium · 15–18 low · ≥19 minimal
 */
class BradenScorer implements ClinicalAssessmentScorer
{
    /** @var array<string, array{label: string, max: int, options: array<int, string>}> */
    private const SUBSCALES = [
        'sensory_perception' => ['label' => 'Sensory perception', 'max' => 4, 'options' => [
            1 => 'Completely limited', 2 => 'Very limited', 3 => 'Slightly limited', 4 => 'No impairment',
        ]],
        'moisture' => ['label' => 'Moisture', 'max' => 4, 'options' => [
            1 => 'Constantly moist', 2 => 'Very moist', 3 => 'Occasionally moist', 4 => 'Rarely moist',
        ]],
        'activity' => ['label' => 'Activity', 'max' => 4, 'options' => [
            1 => 'Bedfast', 2 => 'Chairfast', 3 => 'Walks occasionally', 4 => 'Walks frequently',
        ]],
        'mobility' => ['label' => 'Mobility', 'max' => 4, 'options' => [
            1 => 'Completely immobile', 2 => 'Very limited', 3 => 'Slightly limited', 4 => 'No limitations',
        ]],
        'nutrition' => ['label' => 'Nutrition', 'max' => 4, 'options' => [
            1 => 'Very poor', 2 => 'Probably inadequate', 3 => 'Adequate', 4 => 'Excellent',
        ]],
        'friction_shear' => ['label' => 'Friction & shear', 'max' => 3, 'options' => [
            1 => 'Problem', 2 => 'Potential problem', 3 => 'No apparent problem',
        ]],
    ];

    public function type(): ClinicalAssessmentType
    {
        return ClinicalAssessmentType::PressureBraden;
    }

    public function score(array $inputs): ClinicalAssessmentResult
    {
        $breakdown = [];
        $total = 0;
        foreach (self::SUBSCALES as $key => $config) {
            $value = $this->clamp($inputs[$key] ?? null, $config['max']);
            $total += $value;
            $breakdown[] = [
                'key' => $key,
                'label' => $config['label'],
                'detail' => $config['options'][$value] ?? (string) $value,
                'points' => $value,
            ];
        }

        $band = $this->band($total);

        return new ClinicalAssessmentResult(
            type: $this->type(),
            score: $total,
            band: $band,
            summary: "Braden {$total}/23 — {$band->label()}",
            advice: $this->advice($band),
            breakdown: $breakdown,
        );
    }

    private function clamp(mixed $value, int $max): int
    {
        if (! is_numeric($value)) {
            return $max; // Unanswered → best case (form requires all subscales).
        }

        $int = (int) $value;

        return max(1, min($max, $int));
    }

    private function band(int $total): ClinicalRiskBand
    {
        return match (true) {
            $total <= 9 => ClinicalRiskBand::VeryHigh,
            $total <= 12 => ClinicalRiskBand::High,
            $total <= 14 => ClinicalRiskBand::Medium,
            $total <= 18 => ClinicalRiskBand::Low,
            default => ClinicalRiskBand::Minimal,
        };
    }

    private function advice(ClinicalRiskBand $band): string
    {
        return match ($band) {
            ClinicalRiskBand::VeryHigh, ClinicalRiskBand::High => 'Initiate a pressure-injury prevention plan — repositioning schedule, support surface and skin inspection.',
            ClinicalRiskBand::Medium => 'Apply preventive measures — regular repositioning and skin checks; reassess frequently.',
            default => 'Maintain routine skin care and reassess on any change in mobility or condition.',
        };
    }
}
