<?php

namespace App\Services\HealthSafety;

use App\Models\HsEvent;
use App\Models\HsRiskAssessment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class HsRiskAssessmentService
{
    /* ------------------------------------------------------------------ */
    /*  Creation                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Create a risk assessment for any assessable entity (Site, Client, Asset, etc.)
     * or linked to an HsEvent.
     */
    public function create(array $data): HsRiskAssessment
    {
        $likelihood = (int) $data['likelihood'];
        $consequence = (int) $data['consequence'];
        $inherent = HsRiskAssessment::calculateScore($likelihood, $consequence);

        $residualData = [];
        if (isset($data['residual_likelihood'], $data['residual_consequence'])) {
            $residual = HsRiskAssessment::calculateScore(
                (int) $data['residual_likelihood'],
                (int) $data['residual_consequence'],
            );
            $residualData = [
                'residual_likelihood' => (int) $data['residual_likelihood'],
                'residual_consequence' => (int) $data['residual_consequence'],
                'residual_risk_score' => $residual['score'],
                'residual_risk_level' => $residual['level'],
            ];
        }

        return DB::transaction(function () use ($data, $likelihood, $consequence, $inherent, $residualData) {
            $assessment = HsRiskAssessment::create(array_merge([
                'reference_number' => HsRiskAssessment::generateReferenceNumber(),
                'assessable_type' => $data['assessable_type'] ?? null,
                'assessable_id' => $data['assessable_id'] ?? null,
                'hs_event_id' => $data['hs_event_id'] ?? null,
                'title' => $data['title'],
                'risk_description' => $data['risk_description'] ?? null,
                'status' => HsRiskAssessment::STATUS_DRAFT,
                'likelihood' => $likelihood,
                'consequence' => $consequence,
                'risk_score' => $inherent['score'],
                'risk_level' => $inherent['level'],
                'existing_controls' => $data['existing_controls'] ?? null,
                'additional_controls' => $data['additional_controls'] ?? null,
                'risk_acceptable' => $data['risk_acceptable'] ?? null,
                'assessed_by_user_id' => $data['assessed_by_user_id'] ?? auth()->id(),
                'assessed_at' => $data['assessed_at'] ?? now(),
                'review_due_at' => $data['review_due_at'] ?? null,
                'review_frequency_days' => $data['review_frequency_days'] ?? null,
                'created_by' => $data['created_by'] ?? auth()->id(),
            ], $residualData));

            Log::info('HsRiskAssessmentService: assessment created', [
                'id' => $assessment->id,
                'reference' => $assessment->reference_number,
                'risk_level' => $assessment->risk_level,
                'assessable' => ($assessment->assessable_type ? class_basename($assessment->assessable_type) . ':' . $assessment->assessable_id : 'standalone'),
            ]);

            return $assessment;
        });
    }

    /* ------------------------------------------------------------------ */
    /*  Lifecycle                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Activate a draft assessment (typically after approval).
     */
    public function activate(HsRiskAssessment $assessment, array $approval = []): HsRiskAssessment
    {
        if ($assessment->status !== HsRiskAssessment::STATUS_DRAFT) {
            throw new \InvalidArgumentException(
                "Can only activate assessments in draft status (current: {$assessment->status})."
            );
        }

        $assessment->update([
            'status' => HsRiskAssessment::STATUS_ACTIVE,
            'approved_by_user_id' => $approval['approved_by_user_id'] ?? auth()->id(),
            'approved_at' => now(),
            'review_due_at' => $assessment->review_due_at
                ?? ($assessment->review_frequency_days
                    ? now()->addDays($assessment->review_frequency_days)->toDateString()
                    : null),
            'updated_by' => auth()->id(),
        ]);

        return $assessment;
    }

    /**
     * Mark an active assessment as needing review.
     */
    public function markForReview(HsRiskAssessment $assessment): HsRiskAssessment
    {
        if ($assessment->status !== HsRiskAssessment::STATUS_ACTIVE) {
            throw new \InvalidArgumentException(
                "Can only mark active assessments for review (current: {$assessment->status})."
            );
        }

        $assessment->update([
            'status' => HsRiskAssessment::STATUS_UNDER_REVIEW,
            'updated_by' => auth()->id(),
        ]);

        return $assessment;
    }

    /**
     * Supersede an assessment with a new version.
     *
     * Creates a new draft from the existing assessment's context,
     * marks the old one as superseded.
     */
    public function supersede(HsRiskAssessment $assessment, array $newData): HsRiskAssessment
    {
        if (! in_array($assessment->status, [HsRiskAssessment::STATUS_ACTIVE, HsRiskAssessment::STATUS_UNDER_REVIEW], true)) {
            throw new \InvalidArgumentException(
                "Can only supersede active or under_review assessments (current: {$assessment->status})."
            );
        }

        return DB::transaction(function () use ($assessment, $newData) {
            $newAssessment = $this->create(array_merge([
                'assessable_type' => $assessment->assessable_type,
                'assessable_id' => $assessment->assessable_id,
                'hs_event_id' => $assessment->hs_event_id,
                'review_frequency_days' => $assessment->review_frequency_days,
            ], $newData));

            $assessment->update([
                'status' => HsRiskAssessment::STATUS_SUPERSEDED,
                'superseded_by_id' => $newAssessment->id,
                'updated_by' => auth()->id(),
            ]);

            Log::info('HsRiskAssessmentService: assessment superseded', [
                'old_id' => $assessment->id,
                'new_id' => $newAssessment->id,
            ]);

            return $newAssessment;
        });
    }

    /**
     * Archive an assessment (no longer relevant).
     */
    public function archive(HsRiskAssessment $assessment): HsRiskAssessment
    {
        if ($assessment->status === HsRiskAssessment::STATUS_ARCHIVED) {
            return $assessment;
        }

        $assessment->update([
            'status' => HsRiskAssessment::STATUS_ARCHIVED,
            'updated_by' => auth()->id(),
        ]);

        return $assessment;
    }

    /* ------------------------------------------------------------------ */
    /*  Residual risk update                                               */
    /* ------------------------------------------------------------------ */

    /**
     * Update the residual risk after controls are applied.
     */
    public function updateResidualRisk(HsRiskAssessment $assessment, int $likelihood, int $consequence, ?bool $acceptable = null): HsRiskAssessment
    {
        $residual = HsRiskAssessment::calculateScore($likelihood, $consequence);

        $assessment->update([
            'residual_likelihood' => $likelihood,
            'residual_consequence' => $consequence,
            'residual_risk_score' => $residual['score'],
            'residual_risk_level' => $residual['level'],
            'risk_acceptable' => $acceptable,
            'updated_by' => auth()->id(),
        ]);

        return $assessment;
    }
}
