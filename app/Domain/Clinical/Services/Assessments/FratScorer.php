<?php

namespace App\Domain\Clinical\Services\Assessments;

use App\Domain\Clinical\Enums\ClinicalAssessmentType;
use App\Domain\Clinical\Enums\ClinicalRiskBand;

/**
 * Falls Risk Assessment Tool — Peninsula Health FRAT (Part 1, Falls Risk Status).
 *
 * Four risk factors, summed to a 5–20 total:
 *   Recent falls       2 / 4 / 6 / 8
 *   Medications        1 / 2 / 3 / 4   (sedatives, anti-Parkinson's, antidepressants,
 *                                       antihypertensives, hypnotics — count taken)
 *   Psychological      1 / 2 / 3 / 4   (anxiety/depression/agitation affecting safety)
 *   Cognitive status   1 / 2 / 3 / 4   (AMTS: intact 9–10 … severe ≤4)
 *
 * Risk band: 5–11 low, 12–15 medium, 16–20 high.
 *
 * Inputs are the option keys below (one per factor).
 */
class FratScorer implements ClinicalAssessmentScorer
{
    /** @var array<string, array{points: int, label: string}> */
    private const RECENT_FALLS = [
        'none_12mo' => ['points' => 2, 'label' => 'No falls in the last 12 months'],
        'one_plus_3_12mo' => ['points' => 4, 'label' => 'One or more between 3 and 12 months ago'],
        'one_plus_3mo' => ['points' => 6, 'label' => 'One or more in the last 3 months'],
        'one_plus_3mo_resident' => ['points' => 8, 'label' => 'One or more in the last 3 months whilst a resident/inpatient'],
    ];

    /** @var array<string, array{points: int, label: string}> */
    private const MEDICATIONS = [
        'none' => ['points' => 1, 'label' => 'Not taking any listed medications'],
        'one' => ['points' => 2, 'label' => 'Taking one'],
        'two' => ['points' => 3, 'label' => 'Taking two'],
        'more_than_two' => ['points' => 4, 'label' => 'Taking more than two'],
    ];

    /** @var array<string, array{points: int, label: string}> */
    private const PSYCHOLOGICAL = [
        'none' => ['points' => 1, 'label' => 'No apparent anxiety, depression or agitation'],
        'mild' => ['points' => 2, 'label' => 'Mild'],
        'moderate' => ['points' => 3, 'label' => 'Moderate'],
        'severe' => ['points' => 4, 'label' => 'Severe'],
    ];

    /** @var array<string, array{points: int, label: string}> */
    private const COGNITIVE = [
        'intact' => ['points' => 1, 'label' => 'Intact (AMTS 9–10)'],
        'mild' => ['points' => 2, 'label' => 'Mildly impaired (AMTS 7–8)'],
        'moderate' => ['points' => 3, 'label' => 'Moderately impaired (AMTS 5–6)'],
        'severe' => ['points' => 4, 'label' => 'Severely impaired (AMTS ≤4)'],
    ];

    public function type(): ClinicalAssessmentType
    {
        return ClinicalAssessmentType::FallsFrat;
    }

    public function score(array $inputs): ClinicalAssessmentResult
    {
        $factors = [
            ['key' => 'recent_falls', 'label' => 'Recent falls', 'map' => self::RECENT_FALLS],
            ['key' => 'medications', 'label' => 'Medications', 'map' => self::MEDICATIONS],
            ['key' => 'psychological', 'label' => 'Psychological', 'map' => self::PSYCHOLOGICAL],
            ['key' => 'cognitive', 'label' => 'Cognitive status', 'map' => self::COGNITIVE],
        ];

        $breakdown = [];
        $total = 0;
        foreach ($factors as $factor) {
            [$points, $detail] = $this->resolve($factor['map'], $inputs[$factor['key']] ?? null);
            $total += $points;
            $breakdown[] = [
                'key' => $factor['key'],
                'label' => $factor['label'],
                'detail' => $detail,
                'points' => $points,
            ];
        }

        $band = $this->band($total);

        return new ClinicalAssessmentResult(
            type: $this->type(),
            score: $total,
            band: $band,
            summary: "FRAT {$total}/20 — {$band->label()}",
            advice: $this->advice($band),
            breakdown: $breakdown,
        );
    }

    /**
     * @param  array<string, array{points: int, label: string}>  $map
     * @return array{0: int, 1: string}
     */
    private function resolve(array $map, mixed $value): array
    {
        $key = is_string($value) ? $value : '';
        if (isset($map[$key])) {
            return [$map[$key]['points'], $map[$key]['label']];
        }

        // Unanswered → lowest weight for that factor (the controller requires all four).
        $first = array_key_first($map);

        return [$map[$first]['points'], 'Not specified'];
    }

    private function band(int $total): ClinicalRiskBand
    {
        return match (true) {
            $total >= 16 => ClinicalRiskBand::High,
            $total >= 12 => ClinicalRiskBand::Medium,
            default => ClinicalRiskBand::Low,
        };
    }

    private function advice(ClinicalRiskBand $band): string
    {
        return match ($band) {
            ClinicalRiskBand::High => 'High falls risk — implement a tailored falls-prevention plan and review interventions.',
            ClinicalRiskBand::Medium => 'Medium falls risk — apply standard falls precautions and consider targeted interventions.',
            default => 'Low falls risk — maintain universal falls precautions and re-screen on change in condition.',
        };
    }
}
