<?php

namespace App\Domain\Clinical\Services;

use App\Domain\Clinical\Enums\ClinicalAssessmentType;
use App\Domain\Clinical\Models\ClinicalRiskAssessment;
use App\Domain\Clinical\Services\Assessments\ClinicalAssessmentScorerRegistry;
use App\Models\Client;
use App\Models\User;
use Carbon\Carbon;

/**
 * Records standardised clinical risk assessments. Computes the transparent
 * score via the scorer registry and persists the inputs + result together, so
 * a stored record always carries the exact tool version, breakdown and band
 * that produced it (the register and client lens never recompute).
 */
class ClinicalAssessmentService
{
    public function __construct(
        private readonly ClinicalAssessmentScorerRegistry $registry,
    ) {}

    /**
     * @param  array<string, mixed>  $inputs  the clinician's structured answers
     */
    public function record(
        Client $client,
        User $assessor,
        ClinicalAssessmentType $type,
        array $inputs,
        ?string $notes = null,
        ?\DateTimeInterface $assessedAt = null,
    ): ClinicalRiskAssessment {
        $result = $this->registry->score($type, $inputs);
        $when = $assessedAt ? Carbon::parse($assessedAt) : now();

        return ClinicalRiskAssessment::create([
            'organization_id' => $client->organization_id,
            'client_id' => $client->id,
            'assessed_by' => $assessor->id,
            'assessment_type' => $type,
            'assessed_at' => $when,
            'inputs' => $inputs,
            'total_score' => $result->score,
            'risk_band' => $result->band,
            'breakdown' => $result->breakdown,
            'summary' => $result->summary,
            'advice' => $result->advice,
            'meta' => $result->meta,
            'tool_version' => $type->toolVersion(),
            'notes' => $notes,
            'review_due_at' => $when->copy()->addDays($type->reviewIntervalDays())->toDateString(),
        ]);
    }
}
