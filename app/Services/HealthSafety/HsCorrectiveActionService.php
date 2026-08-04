<?php

namespace App\Services\HealthSafety;

use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service for managing the HsCorrectiveAction lifecycle.
 *
 * Corrective actions are formal compliance records — distinct from
 * Control Room AlertTasks (which are operational response tasks).
 *
 * AlertTask = "respond to this alert right now" (minutes/hours)
 * HsCorrectiveAction = "fix the underlying cause and prove it" (days/weeks/months)
 */
class HsCorrectiveActionService
{
    /* ------------------------------------------------------------------ */
    /*  Creation                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Create a corrective action from an investigation recommendation.
     *
     * @param  HsInvestigation  $investigation  Source investigation (must be completed or have findings)
     * @param  int              $recommendationIndex  Zero-based index into recommendations JSON array
     * @param  array            $overrides  Optional field overrides (assigned_to, due_date, etc.)
     *
     * @throws \InvalidArgumentException if recommendation doesn't exist or action already exists
     */
    public function createFromRecommendation(
        HsInvestigation $investigation,
        int $recommendationIndex,
        array $overrides = [],
    ): HsCorrectiveAction {
        // Guard: investigation must have recommendations
        $recommendations = $investigation->recommendations ?? [];

        if (! isset($recommendations[$recommendationIndex])) {
            throw new \InvalidArgumentException(
                "Recommendation index [{$recommendationIndex}] does not exist on investigation [{$investigation->reference_number}]."
            );
        }

        // Guard: no duplicate action for same investigation + recommendation index
        $exists = HsCorrectiveAction::where('hs_investigation_id', $investigation->id)
            ->where('recommendation_index', $recommendationIndex)
            ->exists();

        if ($exists) {
            throw new \InvalidArgumentException(
                "A corrective action already exists for recommendation [{$recommendationIndex}] on investigation [{$investigation->reference_number}]."
            );
        }

        $recommendation = $recommendations[$recommendationIndex];

        return DB::transaction(function () use ($investigation, $recommendation, $recommendationIndex, $overrides) {
            $action = HsCorrectiveAction::create([
                'hs_event_id' => $investigation->hs_event_id,
                'hs_investigation_id' => $investigation->id,
                'reference_number' => HsCorrectiveAction::generateReferenceNumber(),
                'recommendation_index' => $recommendationIndex,
                'action_type' => $overrides['action_type'] ?? HsCorrectiveAction::TYPE_CORRECTIVE,
                'priority' => $recommendation['priority'] ?? $overrides['priority'] ?? HsCorrectiveAction::PRIORITY_MEDIUM,
                'title' => $overrides['title'] ?? $recommendation['description'] ?? 'Corrective action',
                'description' => $overrides['description'] ?? null,
                'root_cause_link' => $overrides['root_cause_link'] ?? null,
                'status' => HsCorrectiveAction::STATUS_OPEN,
                'assigned_to_user_id' => $overrides['assigned_to_user_id'] ?? null,
                'assigned_by_user_id' => $overrides['assigned_by_user_id'] ?? auth()->id(),
                'assigned_at' => isset($overrides['assigned_to_user_id']) ? now() : null,
                'due_date' => $overrides['due_date'] ?? $this->suggestDueDate($recommendation['priority'] ?? 'medium'),
                'created_by' => $overrides['created_by'] ?? auth()->id(),
            ]);

            Log::info('HsCorrectiveActionService: action created from recommendation', [
                'action_id' => $action->id,
                'reference' => $action->reference_number,
                'investigation_id' => $investigation->id,
                'recommendation_index' => $recommendationIndex,
                'priority' => $action->priority,
            ]);

            return $action;
        });
    }

    /**
     * Create a standalone corrective action on an HsEvent (not from investigation).
     *
     * Used for ad-hoc actions, or events that don't require formal investigation.
     */
    public function createStandalone(HsEvent $hsEvent, array $data): HsCorrectiveAction
    {
        if (! $hsEvent->isOpen()) {
            throw new \InvalidArgumentException(
                "Cannot create corrective action on closed event [{$hsEvent->reference_number}]."
            );
        }

        return DB::transaction(function () use ($hsEvent, $data) {
            $action = HsCorrectiveAction::create([
                'hs_event_id' => $hsEvent->id,
                'hs_investigation_id' => $data['hs_investigation_id'] ?? null,
                'reference_number' => HsCorrectiveAction::generateReferenceNumber(),
                'action_type' => $data['action_type'] ?? HsCorrectiveAction::TYPE_CORRECTIVE,
                'priority' => $data['priority'] ?? HsCorrectiveAction::PRIORITY_MEDIUM,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'root_cause_link' => $data['root_cause_link'] ?? null,
                'status' => HsCorrectiveAction::STATUS_OPEN,
                'assigned_to_user_id' => $data['assigned_to_user_id'] ?? null,
                'assigned_by_user_id' => $data['assigned_by_user_id'] ?? auth()->id(),
                'assigned_at' => isset($data['assigned_to_user_id']) ? now() : null,
                'due_date' => $data['due_date'] ?? $this->suggestDueDate($data['priority'] ?? 'medium'),
                'created_by' => $data['created_by'] ?? auth()->id(),
            ]);

            // If event is at 'open' or 'investigating', move it to corrective_action
            $this->syncEventToCorrectiveActionStatus($hsEvent);

            Log::info('HsCorrectiveActionService: standalone action created', [
                'action_id' => $action->id,
                'reference' => $action->reference_number,
                'hs_event_id' => $hsEvent->id,
            ]);

            return $action;
        });
    }

    /**
     * Bulk-create corrective actions from all investigation recommendations.
     *
     * Skips recommendations that already have an action (safe to call repeatedly).
     *
     * @return array<HsCorrectiveAction> Created actions
     */
    public function createFromAllRecommendations(HsInvestigation $investigation, array $defaults = []): array
    {
        $recommendations = $investigation->recommendations ?? [];
        $created = [];

        foreach ($recommendations as $index => $recommendation) {
            $exists = HsCorrectiveAction::where('hs_investigation_id', $investigation->id)
                ->where('recommendation_index', $index)
                ->exists();

            if ($exists) {
                continue;
            }

            $created[] = $this->createFromRecommendation($investigation, $index, $defaults);
        }

        return $created;
    }

    /* ------------------------------------------------------------------ */
    /*  Lifecycle transitions                                              */
    /* ------------------------------------------------------------------ */

    /**
     * Start working on an action — open → in_progress.
     */
    public function start(HsCorrectiveAction $action, ?int $assigneeId = null): HsCorrectiveAction
    {
        $this->assertTransition($action, HsCorrectiveAction::STATUS_IN_PROGRESS);

        $updates = [
            'status' => HsCorrectiveAction::STATUS_IN_PROGRESS,
            'updated_by' => auth()->id(),
        ];

        if ($assigneeId && ! $action->assigned_to_user_id) {
            $updates['assigned_to_user_id'] = $assigneeId;
            $updates['assigned_by_user_id'] = auth()->id();
            $updates['assigned_at'] = now();
        }

        $action->update($updates);

        Log::info('HsCorrectiveActionService: action started', [
            'action_id' => $action->id,
        ]);

        return $action;
    }

    /**
     * Complete an action — in_progress → completed.
     *
     * Completion requires notes or evidence.
     * This does NOT mean the action is verified — verification is a separate step.
     */
    public function complete(HsCorrectiveAction $action, array $data): HsCorrectiveAction
    {
        $this->assertTransition($action, HsCorrectiveAction::STATUS_COMPLETED);

        $hasEvidence = ! empty(trim($data['completion_notes'] ?? ''))
            || ! empty($data['completion_evidence_paths']);

        if (! $hasEvidence) {
            throw new \InvalidArgumentException(
                'Cannot complete action without completion notes or evidence.'
            );
        }

        $action->update([
            'status' => HsCorrectiveAction::STATUS_COMPLETED,
            'completed_at' => now(),
            'completed_by_user_id' => $data['completed_by_user_id'] ?? auth()->id(),
            'completion_notes' => $data['completion_notes'] ?? $action->completion_notes,
            'completion_evidence_paths' => $data['completion_evidence_paths'] ?? $action->completion_evidence_paths,
            'updated_by' => auth()->id(),
        ]);

        Log::info('HsCorrectiveActionService: action completed', [
            'action_id' => $action->id,
        ]);

        return $action;
    }

    /**
     * Return to in_progress from completed (verifier rejected).
     */
    public function returnForRework(HsCorrectiveAction $action, string $reason): HsCorrectiveAction
    {
        $this->assertTransition($action, HsCorrectiveAction::STATUS_IN_PROGRESS);

        $action->update([
            'status' => HsCorrectiveAction::STATUS_IN_PROGRESS,
            'verification_notes' => $reason,
            'completed_at' => null,
            'completed_by_user_id' => null,
            'updated_by' => auth()->id(),
        ]);

        Log::info('HsCorrectiveActionService: action returned for rework', [
            'action_id' => $action->id,
        ]);

        return $action;
    }

    /**
     * Verify an action — completed → verified.
     *
     * Verifier must be a different person than the completer (separation of duties).
     * effectiveness_confirmed indicates whether the action actually resolved the issue.
     */
    public function verify(HsCorrectiveAction $action, array $data): HsCorrectiveAction
    {
        $this->assertTransition($action, HsCorrectiveAction::STATUS_VERIFIED);

        $verifierId = $data['verified_by_user_id'] ?? auth()->id();

        // Separation of duties: verifier should not be the completer
        if ($verifierId && $verifierId === $action->completed_by_user_id) {
            throw new \InvalidArgumentException(
                'Verifier must be a different person than the action completer (separation of duties).'
            );
        }

        if (! isset($data['effectiveness_confirmed'])) {
            throw new \InvalidArgumentException(
                'Verification must include an effectiveness assessment (effectiveness_confirmed).'
            );
        }

        $action->update([
            'status' => HsCorrectiveAction::STATUS_VERIFIED,
            'verified_at' => now(),
            'verified_by_user_id' => $verifierId,
            'verification_notes' => $data['verification_notes'] ?? null,
            'effectiveness_confirmed' => $data['effectiveness_confirmed'],
            'updated_by' => auth()->id(),
        ]);

        Log::info('HsCorrectiveActionService: action verified', [
            'action_id' => $action->id,
            'effectiveness_confirmed' => $data['effectiveness_confirmed'],
        ]);

        return $action;
    }

    /**
     * Close a verified action — verified → closed.
     *
     * Also checks if all actions on the parent HsEvent are now closed/verified,
     * and if so moves the event to monitoring status.
     */
    public function close(HsCorrectiveAction $action, ?int $closedBy = null): HsCorrectiveAction
    {
        $this->assertTransition($action, HsCorrectiveAction::STATUS_CLOSED);

        return DB::transaction(function () use ($action, $closedBy) {
            $action->update([
                'status' => HsCorrectiveAction::STATUS_CLOSED,
                'closed_at' => now(),
                'closed_by_user_id' => $closedBy ?? auth()->id(),
                'updated_by' => auth()->id(),
            ]);

            // Check if all corrective actions for the parent event are resolved
            $this->checkEventActionCompletion($action->hs_event_id);

            Log::info('HsCorrectiveActionService: action closed', [
                'action_id' => $action->id,
            ]);

            return $action;
        });
    }

    /* ------------------------------------------------------------------ */
    /*  Query helpers                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * Check if all corrective actions for an event are verified or closed.
     * If so, move the HsEvent to monitoring status.
     */
    public function checkEventActionCompletion(int $hsEventId): void
    {
        $hsEvent = HsEvent::find($hsEventId);

        if (! $hsEvent || ! $hsEvent->isOpen()) {
            return;
        }

        $totalActions = HsCorrectiveAction::where('hs_event_id', $hsEventId)->count();

        if ($totalActions === 0) {
            return;
        }

        $resolvedActions = HsCorrectiveAction::where('hs_event_id', $hsEventId)
            ->whereIn('status', [HsCorrectiveAction::STATUS_VERIFIED, HsCorrectiveAction::STATUS_CLOSED])
            ->count();

        if ($resolvedActions === $totalActions) {
            // All actions resolved — event can move to monitoring
            $eventOrder = [
                HsEvent::STATUS_OPEN => 0,
                HsEvent::STATUS_INVESTIGATING => 1,
                HsEvent::STATUS_CORRECTIVE_ACTION => 2,
                HsEvent::STATUS_MONITORING => 3,
                HsEvent::STATUS_CLOSED => 4,
            ];

            $currentRank = $eventOrder[$hsEvent->status] ?? 0;
            $monitoringRank = $eventOrder[HsEvent::STATUS_MONITORING] ?? 3;

            if ($monitoringRank > $currentRank) {
                $hsEvent->update(['status' => HsEvent::STATUS_MONITORING]);

                Log::info('HsCorrectiveActionService: all actions resolved, event moved to monitoring', [
                    'hs_event_id' => $hsEventId,
                    'total_actions' => $totalActions,
                ]);
            }
        }
    }

    /**
     * Check if a recommendation already has a corrective action.
     */
    public function recommendationHasAction(int $investigationId, int $recommendationIndex): bool
    {
        return HsCorrectiveAction::where('hs_investigation_id', $investigationId)
            ->where('recommendation_index', $recommendationIndex)
            ->exists();
    }

    /* ------------------------------------------------------------------ */
    /*  Internal helpers                                                    */
    /* ------------------------------------------------------------------ */

    private function assertTransition(HsCorrectiveAction $action, string $targetStatus): void
    {
        if (! $action->canTransitionTo($targetStatus)) {
            throw new \InvalidArgumentException(
                "Cannot transition corrective action from '{$action->status}' to '{$targetStatus}'."
            );
        }
    }

    private function suggestDueDate(string $priority): string
    {
        $days = match ($priority) {
            HsCorrectiveAction::PRIORITY_CRITICAL => 7,
            HsCorrectiveAction::PRIORITY_HIGH => 14,
            HsCorrectiveAction::PRIORITY_MEDIUM => 30,
            default => 60,
        };

        return now()->addDays($days)->toDateString();
    }

    /**
     * Move HsEvent to corrective_action status if it's not already there or beyond.
     */
    private function syncEventToCorrectiveActionStatus(HsEvent $hsEvent): void
    {
        $eventOrder = [
            HsEvent::STATUS_OPEN => 0,
            HsEvent::STATUS_INVESTIGATING => 1,
            HsEvent::STATUS_CORRECTIVE_ACTION => 2,
            HsEvent::STATUS_MONITORING => 3,
            HsEvent::STATUS_CLOSED => 4,
        ];

        $currentRank = $eventOrder[$hsEvent->status] ?? 0;
        $targetRank = $eventOrder[HsEvent::STATUS_CORRECTIVE_ACTION] ?? 2;

        if ($targetRank > $currentRank) {
            $hsEvent->update(['status' => HsEvent::STATUS_CORRECTIVE_ACTION]);
        }
    }
}
