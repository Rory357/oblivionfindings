<?php

namespace App\Domain\Clinical\Services\Assessments;

use App\Domain\Clinical\Enums\ClinicalAssessmentType;

/**
 * Contract for a single clinical assessment tool. Implementations are pure
 * (input → result, no I/O) so they can be unit-tested against published
 * reference cases — the mechanism that pins clinical correctness.
 */
interface ClinicalAssessmentScorer
{
    public function type(): ClinicalAssessmentType;

    /**
     * @param  array<string, mixed>  $inputs  the clinician's structured answers
     */
    public function score(array $inputs): ClinicalAssessmentResult;
}
