<?php

namespace App\Domain\Clinical\Services\Assessments;

use App\Domain\Clinical\Enums\ClinicalAssessmentType;

/**
 * Resolves a {@see ClinicalAssessmentType} to its scorer/classifier. Each
 * scorer is stateless and auto-wired by the container; the register and the
 * store endpoint go through here so a new tool only needs adding once.
 */
class ClinicalAssessmentScorerRegistry
{
    /** @var array<string, ClinicalAssessmentScorer> */
    private array $scorers;

    public function __construct(
        FratScorer $frat,
        BradenScorer $braden,
        MustScorer $must,
        IddsiClassifier $iddsi,
    ) {
        $this->scorers = [
            ClinicalAssessmentType::FallsFrat->value => $frat,
            ClinicalAssessmentType::PressureBraden->value => $braden,
            ClinicalAssessmentType::MalnutritionMust->value => $must,
            ClinicalAssessmentType::DysphagiaIddsi->value => $iddsi,
        ];
    }

    public function for(ClinicalAssessmentType $type): ClinicalAssessmentScorer
    {
        return $this->scorers[$type->value];
    }

    /**
     * @param  array<string, mixed>  $inputs
     */
    public function score(ClinicalAssessmentType $type, array $inputs): ClinicalAssessmentResult
    {
        return $this->for($type)->score($inputs);
    }
}
