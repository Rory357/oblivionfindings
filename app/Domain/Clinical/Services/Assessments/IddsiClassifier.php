<?php

namespace App\Domain\Clinical\Services\Assessments;

use App\Domain\Clinical\Enums\ClinicalAssessmentType;

/**
 * IDDSI Framework 2.0 (2019) texture-level capture.
 *
 * Unlike FRAT/Braden/MUST this is a CLASSIFICATION, not a risk score: it
 * records the recommended drink thickness (Levels 0–4) and food texture
 * (Levels 3–7) for a client, typically set by a speech-language therapist.
 * `score`/`band` are therefore null; the result carries the levels in `meta`.
 */
class IddsiClassifier implements ClinicalAssessmentScorer
{
    /** @var array<int, string> */
    private const DRINKS = [
        0 => 'Thin',
        1 => 'Slightly Thick',
        2 => 'Mildly Thick',
        3 => 'Moderately Thick',
        4 => 'Extremely Thick',
    ];

    /** @var array<int, string> */
    private const FOODS = [
        3 => 'Liquidised',
        4 => 'Pureed',
        5 => 'Minced & Moist',
        6 => 'Soft & Bite-Sized',
        7 => 'Regular',
    ];

    public function type(): ClinicalAssessmentType
    {
        return ClinicalAssessmentType::DysphagiaIddsi;
    }

    public function score(array $inputs): ClinicalAssessmentResult
    {
        $drinkLevel = $this->levelOrNull($inputs['drink_level'] ?? null, self::DRINKS);
        $foodLevel = $this->levelOrNull($inputs['food_level'] ?? null, self::FOODS);

        $drinkLabel = $drinkLevel === null ? null : self::DRINKS[$drinkLevel];
        $foodLabel = $foodLevel === null ? null : self::FOODS[$foodLevel];

        $breakdown = [
            [
                'key' => 'drink_level',
                'label' => 'Drinks',
                'detail' => $drinkLevel === null ? 'Not specified' : "Level {$drinkLevel} · {$drinkLabel}",
                'points' => null,
            ],
            [
                'key' => 'food_level',
                'label' => 'Food',
                'detail' => $foodLevel === null ? 'Not specified' : "Level {$foodLevel} · {$foodLabel}",
                'points' => null,
            ],
        ];

        return new ClinicalAssessmentResult(
            type: $this->type(),
            score: null,
            band: null,
            summary: $this->summary($drinkLevel, $drinkLabel, $foodLevel, $foodLabel),
            advice: 'Follow the SLT-recommended IDDSI textures; review on any change in swallowing or chest health.',
            breakdown: $breakdown,
            meta: [
                'drink_level' => $drinkLevel,
                'drink_label' => $drinkLabel,
                'food_level' => $foodLevel,
                'food_label' => $foodLabel,
            ],
        );
    }

    /**
     * @param  array<int, string>  $valid
     */
    private function levelOrNull(mixed $value, array $valid): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $level = (int) $value;

        return array_key_exists($level, $valid) ? $level : null;
    }

    private function summary(?int $drinkLevel, ?string $drinkLabel, ?int $foodLevel, ?string $foodLabel): string
    {
        $parts = [];
        if ($drinkLevel !== null) {
            $parts[] = "Drinks L{$drinkLevel} ({$drinkLabel})";
        }
        if ($foodLevel !== null) {
            $parts[] = "Food L{$foodLevel} ({$foodLabel})";
        }

        return $parts === [] ? 'IDDSI — no levels specified' : 'IDDSI · '.implode(' · ', $parts);
    }
}
