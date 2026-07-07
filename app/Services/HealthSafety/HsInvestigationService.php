<?php

namespace App\Services\HealthSafety;

use App\Models\ClientIncident;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service for managing the HsInvestigation lifecycle.
 *
 * All investigation state transitions go through this service
 * to guarantee lifecycle integrity, HsEvent status sync, and
 * audit-safe operation.
 */
class HsInvestigationService
{
    /* ------------------------------------------------------------------ */
    /*  Creation                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Create an investigation for an HsEvent.
     *
     * Rules:
     *  - HsEvent must exist and be open (not closed)
     *  - Only one active investigation per HsEvent in this phase
     *  - If a completed investigation already exists, a new one
     *    can be created (re-investigation scenario) — but not in this PR
     *
     * @throws \InvalidArgumentException if preconditions are not met
     */
    public function create(HsEvent $hsEvent, array $data = []): HsInvestigation
    {
        // Guard: event must be open
        if (! $hsEvent->isOpen()) {
            throw new \InvalidArgumentException(
                "Cannot create investigation for closed HsEvent [{$hsEvent->reference_number}]."
            );
        }

        // Guard: no active investigation already exists
        $existingActive = HsInvestigation::where('hs_event_id', $hsEvent->id)
            ->whereNotIn('status', [HsInvestigation::STATUS_COMPLETED])
            ->exists();

        if ($existingActive) {
            throw new \InvalidArgumentException(
                "HsEvent [{$hsEvent->reference_number}] already has an active investigation."
            );
        }

        return DB::transaction(function () use ($hsEvent, $data) {
            $investigation = HsInvestigation::create([
                'hs_event_id' => $hsEvent->id,
                'organization_id' => $hsEvent->organization_id,
                'reference_number' => HsInvestigation::generateReferenceNumber(),
                'investigation_type' => $data['investigation_type'] ?? $this->inferInvestigationType($hsEvent),
                'status' => HsInvestigation::STATUS_DRAFT,
                'methodology' => $data['methodology'] ?? null,
                'lead_investigator_id' => $data['lead_investigator_id'] ?? null,
                'team_member_ids' => $data['team_member_ids'] ?? null,
                'target_completion_date' => $data['target_completion_date'] ?? $this->suggestTargetDate($hsEvent),
                'created_by' => $data['created_by'] ?? auth()->id(),
            ]);

            Log::info('HsInvestigationService: investigation created', [
                'investigation_id' => $investigation->id,
                'reference' => $investigation->reference_number,
                'hs_event_id' => $hsEvent->id,
                'hs_event_reference' => $hsEvent->reference_number,
                'type' => $investigation->investigation_type,
            ]);

            $this->syncSourceIncidentStatus($investigation, 'pending');

            return $investigation;
        });
    }

    /* ------------------------------------------------------------------ */
    /*  Lifecycle transitions                                              */
    /* ------------------------------------------------------------------ */

    /**
     * Start an investigation — move from draft to in_progress.
     *
     * Requires a lead investigator to be assigned.
     * Syncs HsEvent status to 'investigating'.
     */
    public function start(HsInvestigation $investigation, ?int $leadInvestigatorId = null): HsInvestigation
    {
        $this->assertTransition($investigation, HsInvestigation::STATUS_IN_PROGRESS);

        // A lead investigator must be set (either already on record or provided now)
        $investigatorId = $leadInvestigatorId ?? $investigation->lead_investigator_id;

        if (! $investigatorId) {
            throw new \InvalidArgumentException(
                'Cannot start investigation without a lead investigator.'
            );
        }

        return DB::transaction(function () use ($investigation, $investigatorId) {
            $investigation->update([
                'status' => HsInvestigation::STATUS_IN_PROGRESS,
                'lead_investigator_id' => $investigatorId,
                'started_at' => $investigation->started_at ?? now(),
                'updated_by' => auth()->id(),
            ]);

            // Sync parent HsEvent status
            $this->syncEventStatus($investigation, HsEvent::STATUS_INVESTIGATING);

            Log::info('HsInvestigationService: investigation started', [
                'investigation_id' => $investigation->id,
                'lead_investigator_id' => $investigatorId,
            ]);

            $this->syncSourceIncidentStatus($investigation, 'in_progress');

            return $investigation;
        });
    }

    /**
     * Record findings — move from in_progress to findings_recorded.
     *
     * At least one of immediate_causes, root_causes, or findings_summary
     * must be provided. Recommendations are optional at this stage.
     */
    public function recordFindings(HsInvestigation $investigation, array $findings): HsInvestigation
    {
        $this->assertTransition($investigation, HsInvestigation::STATUS_FINDINGS_RECORDED);

        // At minimum, some findings must be recorded
        $hasFindings = ! empty($findings['immediate_causes'])
            || ! empty($findings['root_causes'])
            || ! empty(trim($findings['findings_summary'] ?? ''));

        if (! $hasFindings) {
            throw new \InvalidArgumentException(
                'Cannot record findings without at least one cause or finding summary.'
            );
        }

        return DB::transaction(function () use ($investigation, $findings) {
            $investigation->update([
                'status' => HsInvestigation::STATUS_FINDINGS_RECORDED,
                'immediate_causes' => $findings['immediate_causes'] ?? $investigation->immediate_causes,
                'root_causes' => $findings['root_causes'] ?? $investigation->root_causes,
                'contributing_factors' => $findings['contributing_factors'] ?? $investigation->contributing_factors,
                'findings_summary' => $findings['findings_summary'] ?? $investigation->findings_summary,
                'recommendations' => $findings['recommendations'] ?? $investigation->recommendations,
                'lessons_learned' => $findings['lessons_learned'] ?? $investigation->lessons_learned,
                'updated_by' => auth()->id(),
            ]);

            Log::info('HsInvestigationService: findings recorded', [
                'investigation_id' => $investigation->id,
                'has_immediate_causes' => ! empty($findings['immediate_causes']),
                'has_root_causes' => ! empty($findings['root_causes']),
                'has_recommendations' => ! empty($findings['recommendations']),
            ]);

            return $investigation;
        });
    }

    /**
     * Submit for review — move from findings_recorded to under_review.
     */
    public function submitForReview(HsInvestigation $investigation): HsInvestigation
    {
        $this->assertTransition($investigation, HsInvestigation::STATUS_UNDER_REVIEW);

        $investigation->update([
            'status' => HsInvestigation::STATUS_UNDER_REVIEW,
            'updated_by' => auth()->id(),
        ]);

        Log::info('HsInvestigationService: submitted for review', [
            'investigation_id' => $investigation->id,
        ]);

        return $investigation;
    }

    /**
     * Return to investigator — move from under_review back to in_progress.
     *
     * Used when the reviewer identifies issues requiring rework.
     */
    public function returnForRework(HsInvestigation $investigation, string $reviewNotes): HsInvestigation
    {
        $this->assertTransition($investigation, HsInvestigation::STATUS_IN_PROGRESS);

        $investigation->update([
            'status' => HsInvestigation::STATUS_IN_PROGRESS,
            'review_notes' => $reviewNotes,
            'updated_by' => auth()->id(),
        ]);

        Log::info('HsInvestigationService: returned for rework', [
            'investigation_id' => $investigation->id,
        ]);

        $this->syncSourceIncidentStatus($investigation, 'in_progress');

        return $investigation;
    }

    /**
     * Complete the investigation — move from under_review to completed.
     *
     * Requires reviewer and approver details.
     * Recommendations must exist before completion.
     * Does NOT close the parent HsEvent — that happens when
     * corrective actions are verified (PR3).
     */
    public function complete(HsInvestigation $investigation, array $approval): HsInvestigation
    {
        $this->assertTransition($investigation, HsInvestigation::STATUS_COMPLETED);

        if (! $investigation->hasRecommendations()) {
            throw new \InvalidArgumentException(
                'Cannot complete investigation without recommendations.'
            );
        }

        return DB::transaction(function () use ($investigation, $approval) {
            $investigation->update([
                'status' => HsInvestigation::STATUS_COMPLETED,
                'completed_at' => now(),
                'reviewed_by_id' => $approval['reviewed_by_id'] ?? $investigation->reviewed_by_id,
                'reviewed_at' => $approval['reviewed_at'] ?? now(),
                'approved_by_id' => $approval['approved_by_id'] ?? auth()->id(),
                'approved_at' => now(),
                'updated_by' => auth()->id(),
            ]);

            // Move HsEvent to corrective_action status.
            // PR3 will manage this status further when corrective actions are tracked.
            $this->syncEventStatus($investigation, HsEvent::STATUS_CORRECTIVE_ACTION);

            Log::info('HsInvestigationService: investigation completed', [
                'investigation_id' => $investigation->id,
                'approved_by' => $approval['approved_by_id'] ?? auth()->id(),
                'recommendation_count' => count($investigation->recommendations ?? []),
            ]);

            $this->syncSourceIncidentStatus($investigation, 'completed');

            return $investigation;
        });
    }

    /* ------------------------------------------------------------------ */
    /*  Findings update (while in_progress — before formal recording)     */
    /* ------------------------------------------------------------------ */

    /**
     * Update investigation working data while still in draft or in_progress.
     *
     * This is for incremental note-taking during the investigation,
     * before the formal recordFindings() transition.
     */
    public function updateWorkingData(HsInvestigation $investigation, array $data): HsInvestigation
    {
        if (! in_array($investigation->status, [HsInvestigation::STATUS_DRAFT, HsInvestigation::STATUS_IN_PROGRESS], true)) {
            throw new \InvalidArgumentException(
                'Working data can only be updated in draft or in_progress status.'
            );
        }

        $allowedFields = [
            'methodology',
            'lead_investigator_id',
            'team_member_ids',
            'target_completion_date',
            'immediate_causes',
            'root_causes',
            'contributing_factors',
            'findings_summary',
            'recommendations',
            'lessons_learned',
        ];

        $updateData = array_intersect_key($data, array_flip($allowedFields));
        $updateData['updated_by'] = auth()->id();

        $investigation->update($updateData);

        return $investigation;
    }

    /* ------------------------------------------------------------------ */
    /*  Internal helpers                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * Assert that the requested transition is valid.
     *
     * @throws \InvalidArgumentException if the transition is not allowed
     */
    private function assertTransition(HsInvestigation $investigation, string $targetStatus): void
    {
        if (! $investigation->canTransitionTo($targetStatus)) {
            throw new \InvalidArgumentException(
                "Cannot transition investigation from '{$investigation->status}' to '{$targetStatus}'."
            );
        }
    }

    /**
     * Sync the parent HsEvent status when investigation progresses.
     *
     * Only moves HsEvent forward — never backwards.
     * Does not close HsEvent (that requires corrective action verification in PR3).
     */
    private function syncEventStatus(HsInvestigation $investigation, string $eventStatus): void
    {
        $hsEvent = $investigation->hsEvent;

        if (! $hsEvent) {
            return;
        }

        // Only move forward in the HsEvent lifecycle
        $eventOrder = [
            HsEvent::STATUS_OPEN => 0,
            HsEvent::STATUS_INVESTIGATING => 1,
            HsEvent::STATUS_CORRECTIVE_ACTION => 2,
            HsEvent::STATUS_MONITORING => 3,
            HsEvent::STATUS_CLOSED => 4,
        ];

        $currentRank = $eventOrder[$hsEvent->status] ?? 0;
        $targetRank = $eventOrder[$eventStatus] ?? 0;

        if ($targetRank > $currentRank) {
            $hsEvent->update(['status' => $eventStatus]);

            Log::info('HsInvestigationService: HsEvent status synced', [
                'hs_event_id' => $hsEvent->id,
                'from' => $hsEvent->getOriginal('status'),
                'to' => $eventStatus,
                'triggered_by_investigation' => $investigation->id,
            ]);
        }
    }

    /**
     * Mirror the investigation lifecycle onto the originating ClientIncident.
     *
     * The incident close guardrail (and the register's "investigation" filter)
     * read `client_incidents.investigation_status`; without this sync a
     * completed H&S investigation never unlocks closing a high-severity
     * incident.
     */
    private function syncSourceIncidentStatus(HsInvestigation $investigation, string $incidentStatus): void
    {
        $hsEvent = $investigation->hsEvent;

        if (! $hsEvent || ltrim((string) $hsEvent->source_type, '\\') !== ClientIncident::class || ! $hsEvent->source_id) {
            return;
        }

        ClientIncident::query()
            ->whereKey($hsEvent->source_id)
            ->update(['investigation_status' => $incidentStatus]);
    }

    /**
     * Infer investigation type from the HsEvent context.
     */
    private function inferInvestigationType(HsEvent $hsEvent): string
    {
        if ($hsEvent->worksafe_notifiable) {
            return HsInvestigation::TYPE_WORKSAFE_DIRECTED;
        }

        if ($hsEvent->severity === HsEvent::SEVERITY_CRITICAL) {
            return HsInvestigation::TYPE_FULL;
        }

        return HsInvestigation::TYPE_STANDARD;
    }

    /**
     * Suggest a target completion date based on event severity.
     *
     * WorkSafe-notifiable / critical: 7 days
     * High: 14 days
     * Default: 30 days
     */
    private function suggestTargetDate(HsEvent $hsEvent): string
    {
        $days = match (true) {
            $hsEvent->worksafe_notifiable, $hsEvent->severity === HsEvent::SEVERITY_CRITICAL => 7,
            $hsEvent->severity === HsEvent::SEVERITY_HIGH => 14,
            default => 30,
        };

        return now()->addDays($days)->toDateString();
    }
}
