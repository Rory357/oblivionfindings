<?php

namespace App\Services\HealthSafety;

use App\Models\ClientIncident;
use App\Models\ControlRoom\AlertTask;
use App\Models\ControlRoomAlert;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\HsRecommendationDisposition;
use App\Models\User;
use App\Notifications\AppEventNotification;
use App\Services\AuditLogger;
use App\Services\UserSiteAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

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
    public const RESPONSIBILITY_TRANSFER_TASK = 'transfer_task';

    public const RESPONSIBILITY_NEW = 'new_responsibility';

    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Creation */
    /* ------------------------------------------------------------------ */

    /**
     * Create a corrective action from an investigation recommendation.
     *
     * @param  HsInvestigation  $investigation  Source investigation
     * @param  int  $recommendationIndex  Zero-based index into recommendations JSON array
     * @param  array  $data  Explicit ownership, due date and responsibility source
     *
     * @throws InvalidArgumentException
     */
    public function createFromRecommendation(
        HsInvestigation $investigation,
        int $recommendationIndex,
        array $data,
        User $actor,
    ): HsCorrectiveAction {
        return DB::transaction(function () use ($investigation, $recommendationIndex, $data, $actor): HsCorrectiveAction {
            $locked = HsInvestigation::query()
                ->whereKey($investigation->id)
                ->lockForUpdate()
                ->firstOrFail();

            $recommendations = $locked->recommendations ?? [];
            if (! array_key_exists($recommendationIndex, $recommendations)) {
                throw new InvalidArgumentException(
                    "Recommendation index [{$recommendationIndex}] does not exist on investigation [{$locked->reference_number}].",
                );
            }

            $event = HsEvent::query()
                ->whereKey($locked->hs_event_id)
                ->lockForUpdate()
                ->firstOrFail();

            $ownerId = $this->requiredPositiveInteger(
                $data,
                'assigned_to_user_id',
                'An eligible owner is required.',
            );
            $dueDate = $this->requiredDueDate($data);
            $priority = $this->requiredPriority($data);
            $responsibilityChoice = $data['responsibility_choice'] ?? null;
            if (! in_array($responsibilityChoice, [
                self::RESPONSIBILITY_TRANSFER_TASK,
                self::RESPONSIBILITY_NEW,
            ], true)) {
                throw new InvalidArgumentException(
                    'A responsibility choice is required: transfer task or new responsibility.',
                );
            }

            $disposition = HsRecommendationDisposition::query()
                ->where('hs_investigation_id', $locked->id)
                ->where('recommendation_index', $recommendationIndex)
                ->lockForUpdate()
                ->first();
            if ($disposition
                && $disposition->disposition !== HsRecommendationDisposition::DISPOSITION_CORRECTIVE_ACTION) {
                throw new InvalidArgumentException(
                    'This recommendation already has a non-action outcome and cannot also create corrective work.',
                );
            }

            $existing = HsCorrectiveAction::query()
                ->where('hs_investigation_id', $locked->id)
                ->where('recommendation_index', $recommendationIndex)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                $this->assertExactRecommendationRetry(
                    $existing,
                    $event,
                    $data,
                    $ownerId,
                    $dueDate,
                    $priority,
                    (string) $responsibilityChoice,
                );
                $this->persistCorrectiveActionDisposition(
                    $disposition,
                    $locked,
                    $recommendationIndex,
                    $existing,
                    $actor,
                );

                return $existing->fresh([
                    'assignedTo',
                    'sourceControlRoomTask',
                ]);
            }

            if (! $locked->isCompleted()) {
                throw new InvalidArgumentException(
                    'Complete the investigation before handing over recommendation work.',
                );
            }
            if (! $event->isOpen()) {
                throw new InvalidArgumentException(
                    "Cannot create corrective action on closed event [{$event->reference_number}].",
                );
            }
            $this->assertAcceptedHandoverIfIncidentBacked($event);
            $owner = $this->eligibleOwner($event, $ownerId, $actor);

            $task = null;
            $newResponsibilityReason = null;
            if ($responsibilityChoice === self::RESPONSIBILITY_TRANSFER_TASK) {
                $task = $this->lockTransferableTask($event, $data);
            } else {
                $newResponsibilityReason = trim((string) ($data['new_responsibility_reason'] ?? ''));
                if (mb_strlen($newResponsibilityReason) < 10) {
                    throw new InvalidArgumentException(
                        'A reason of at least 10 characters is required for a new responsibility.',
                    );
                }
            }

            $recommendation = $recommendations[$recommendationIndex];
            $action = HsCorrectiveAction::create([
                'hs_event_id' => $event->id,
                'hs_investigation_id' => $locked->id,
                'source_control_room_task_id' => $task?->id,
                'reference_number' => HsCorrectiveAction::generateReferenceNumber(),
                'recommendation_index' => $recommendationIndex,
                'action_type' => HsCorrectiveAction::TYPE_CORRECTIVE,
                'priority' => $priority,
                'title' => $data['title'] ?? $recommendation['description'] ?? 'Corrective action',
                'description' => $responsibilityChoice === self::RESPONSIBILITY_NEW
                    ? $newResponsibilityReason
                    : ($data['description'] ?? $task?->description),
                'root_cause_link' => $data['root_cause_link'] ?? null,
                'status' => HsCorrectiveAction::STATUS_OPEN,
                'assigned_to_user_id' => $owner->id,
                'assigned_by_user_id' => $actor->id,
                'assigned_at' => now(),
                'due_date' => $dueDate,
                'created_by' => $actor->id,
            ]);

            $transferredAt = null;
            if ($task) {
                $transferredAt = now();
                $task->forceFill([
                    'status' => AlertTask::STATUS_TRANSFERRED,
                    'completed_at' => null,
                    'transferred_to_hs_corrective_action_id' => $action->id,
                    'transferred_at' => $transferredAt,
                    'transferred_by_user_id' => $actor->id,
                ])->save();
            }

            $this->persistCorrectiveActionDisposition(
                $disposition,
                $locked,
                $recommendationIndex,
                $action,
                $actor,
            );
            $this->syncEventToCorrectiveActionStatus($event);

            AuditLogger::logOrFail('healthSafety.investigation.recommendationDispositioned', $locked, [
                'actor_id' => $actor->id,
                'recommendation_index' => $recommendationIndex,
                'disposition' => HsRecommendationDisposition::DISPOSITION_CORRECTIVE_ACTION,
                'reason' => null,
                'hs_corrective_action_id' => $action->id,
                'previous' => null,
            ]);
            AuditLogger::logOrFail('healthSafety.correctiveAction.handedOver', $action, [
                'actor_id' => $actor->id,
                'hs_investigation_id' => $locked->id,
                'hs_event_id' => $event->id,
                'hs_corrective_action_id' => $action->id,
                'recommendation_index' => $recommendationIndex,
                'assigned_to_user_id' => $owner->id,
                'due_date' => $dueDate,
                'priority' => $priority,
                'responsibility_choice' => $responsibilityChoice,
                'source_control_room_task_id' => $task?->id,
                'new_responsibility_reason' => $newResponsibilityReason,
            ]);

            if ($task) {
                $alert = ControlRoomAlert::query()->findOrFail($task->alert_id);
                AuditLogger::logOrFail('controlRoom.task.transferredToHealthSafety', $alert, [
                    'actor_id' => $actor->id,
                    'task_id' => $task->id,
                    'hs_event_id' => $event->id,
                    'hs_corrective_action_id' => $action->id,
                    'transferred_at' => $transferredAt?->toIso8601String(),
                    'transfer_source' => 'investigation_recommendation',
                ]);
            }

            $this->notifyOwner($action, $owner);

            Log::info('HsCorrectiveActionService: action created from recommendation', [
                'action_id' => $action->id,
                'reference' => $action->reference_number,
                'investigation_id' => $locked->id,
                'recommendation_index' => $recommendationIndex,
                'priority' => $action->priority,
            ]);

            return $action->fresh([
                'assignedTo',
                'sourceControlRoomTask',
            ]);
        }, 3);
    }

    /**
     * Create a standalone corrective action on an HsEvent (not from investigation).
     *
     * Used for ad-hoc actions, or events that don't require formal investigation.
     */
    public function createStandalone(HsEvent $hsEvent, array $data, User $actor): HsCorrectiveAction
    {
        if (! $hsEvent->isOpen()) {
            throw new InvalidArgumentException(
                "Cannot create corrective action on closed event [{$hsEvent->reference_number}]."
            );
        }

        return DB::transaction(function () use ($hsEvent, $data, $actor): HsCorrectiveAction {
            $event = HsEvent::query()
                ->whereKey($hsEvent->id)
                ->lockForUpdate()
                ->firstOrFail();
            if (! $event->isOpen()) {
                throw new InvalidArgumentException(
                    "Cannot create corrective action on closed event [{$event->reference_number}].",
                );
            }

            $owner = $this->eligibleOwner(
                $event,
                $this->requiredPositiveInteger($data, 'assigned_to_user_id', 'An eligible owner is required.'),
                $actor,
            );
            $dueDate = $this->requiredDueDate($data);
            $priority = $this->requiredPriority($data);

            $action = HsCorrectiveAction::create([
                'hs_event_id' => $event->id,
                'hs_investigation_id' => $data['hs_investigation_id'] ?? null,
                'source_control_room_task_id' => $data['source_control_room_task_id'] ?? null,
                'reference_number' => HsCorrectiveAction::generateReferenceNumber(),
                'action_type' => $data['action_type'] ?? HsCorrectiveAction::TYPE_CORRECTIVE,
                'priority' => $priority,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'root_cause_link' => $data['root_cause_link'] ?? null,
                'status' => HsCorrectiveAction::STATUS_OPEN,
                'assigned_to_user_id' => $owner->id,
                'assigned_by_user_id' => $actor->id,
                'assigned_at' => now(),
                'due_date' => $dueDate,
                'created_by' => $actor->id,
            ]);

            $this->syncEventToCorrectiveActionStatus($event);
            AuditLogger::logOrFail('healthSafety.correctiveAction.created', $action, [
                'actor_id' => $actor->id,
                'hs_event_id' => $event->id,
                'assigned_to_user_id' => $owner->id,
                'due_date' => $dueDate,
                'priority' => $priority,
                'source_control_room_task_id' => $action->source_control_room_task_id,
            ]);
            $this->notifyOwner($action, $owner);

            Log::info('HsCorrectiveActionService: standalone action created', [
                'action_id' => $action->id,
                'reference' => $action->reference_number,
                'hs_event_id' => $event->id,
            ]);

            return $action->fresh('assignedTo');
        }, 3);
    }

    /**
     * Bulk-create corrective actions from all investigation recommendations.
     *
     * Skips recommendations that already have an action (safe to call repeatedly).
     *
     * @return array<HsCorrectiveAction> Created actions
     */
    public function createFromAllRecommendations(
        HsInvestigation $investigation,
        array $assignments,
        User $actor,
    ): array {
        $recommendations = $investigation->recommendations ?? [];

        foreach ($recommendations as $index => $recommendation) {
            $exists = HsCorrectiveAction::where('hs_investigation_id', $investigation->id)
                ->where('recommendation_index', $index)
                ->exists();

            if ($exists) {
                continue;
            }

            if (! array_key_exists($index, $assignments) || ! is_array($assignments[$index])) {
                throw new InvalidArgumentException(
                    "An explicit ownership assignment is required for recommendation [{$index}].",
                );
            }
        }

        return DB::transaction(function () use ($investigation, $recommendations, $assignments, $actor): array {
            $created = [];
            foreach ($recommendations as $index => $recommendation) {
                if (HsCorrectiveAction::query()
                    ->where('hs_investigation_id', $investigation->id)
                    ->where('recommendation_index', $index)
                    ->exists()) {
                    continue;
                }

                $created[] = $this->createFromRecommendation(
                    $investigation,
                    $index,
                    $assignments[$index],
                    $actor,
                );
            }

            return $created;
        }, 3);
    }

    /* ------------------------------------------------------------------ */
    /*  Lifecycle transitions */
    /* ------------------------------------------------------------------ */

    /**
     * Start working on an action — open → in_progress.
     */
    public function start(HsCorrectiveAction $action, ?int $assigneeId = null): HsCorrectiveAction
    {
        return DB::transaction(function () use ($action, $assigneeId): HsCorrectiveAction {
            $action = HsCorrectiveAction::query()
                ->whereKey($action->id)
                ->lockForUpdate()
                ->firstOrFail();
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
        });
    }

    /**
     * Complete an action — in_progress → completed.
     *
     * Completion requires notes or evidence.
     * This does NOT mean the action is verified — verification is a separate step.
     */
    public function complete(HsCorrectiveAction $action, array $data): HsCorrectiveAction
    {
        return DB::transaction(function () use ($action, $data): HsCorrectiveAction {
            $action = HsCorrectiveAction::query()
                ->whereKey($action->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertTransition($action, HsCorrectiveAction::STATUS_COMPLETED);

            $completionNotes = $data['completion_notes'] ?? $action->completion_notes;
            $completionEvidencePaths = $data['completion_evidence_paths']
                ?? $action->completion_evidence_paths;
            $hasEvidence = filled($completionNotes)
                || ! empty($completionEvidencePaths)
                || $action->attachments()->exists();

            if (! $hasEvidence) {
                throw new InvalidArgumentException(
                    'Cannot complete action without completion notes or evidence.'
                );
            }

            $action->update([
                'status' => HsCorrectiveAction::STATUS_COMPLETED,
                'completed_at' => now(),
                'completed_by_user_id' => $data['completed_by_user_id'] ?? auth()->id(),
                'completion_notes' => $completionNotes,
                'completion_evidence_paths' => $completionEvidencePaths,
                'updated_by' => auth()->id(),
            ]);

            Log::info('HsCorrectiveActionService: action completed', [
                'action_id' => $action->id,
            ]);

            return $action;
        });
    }

    /**
     * Return to in_progress from completed (verifier rejected).
     */
    public function returnForRework(HsCorrectiveAction $action, string $reason): HsCorrectiveAction
    {
        return DB::transaction(function () use ($action, $reason): HsCorrectiveAction {
            $action = HsCorrectiveAction::query()
                ->whereKey($action->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertTransition($action, HsCorrectiveAction::STATUS_IN_PROGRESS);

            $action->update([
                'status' => HsCorrectiveAction::STATUS_IN_PROGRESS,
                'verification_notes' => $reason,
                'updated_by' => auth()->id(),
            ]);

            Log::info('HsCorrectiveActionService: action returned for rework', [
                'action_id' => $action->id,
            ]);

            return $action;
        });
    }

    /**
     * Verify an action — completed → verified.
     *
     * Verifier must be a different person than the completer (separation of duties).
     * effectiveness_confirmed indicates whether the action actually resolved the issue.
     */
    public function verify(HsCorrectiveAction $action, array $data): HsCorrectiveAction
    {
        return DB::transaction(function () use ($action, $data): HsCorrectiveAction {
            $action = HsCorrectiveAction::query()
                ->whereKey($action->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertTransition($action, HsCorrectiveAction::STATUS_VERIFIED);

            if (! ($data['evidence_reviewed'] ?? false)) {
                throw ValidationException::withMessages([
                    'evidence_reviewed' => 'Review the owner submission before verifying this action.',
                ]);
            }

            $verifierId = $data['verified_by_user_id'] ?? auth()->id();

            // Separation of duties: the action owner, completer, action creator, and event creator must not verify.
            $actionCreatorId = $action->created_by;
            $eventCreatorId = $action->hsEvent?->created_by;

            if ($verifierId && (
                $verifierId === $action->assigned_to_user_id
                || $verifierId === $action->completed_by_user_id
                || ($actionCreatorId && $verifierId === $actionCreatorId)
                || ($eventCreatorId && $verifierId === $eventCreatorId)
            )) {
                throw new InvalidArgumentException(
                    'Verifier must be a different person than the action owner, completer, and reporter (separation of duties).'
                );
            }

            if (! isset($data['effectiveness_confirmed'])) {
                throw new InvalidArgumentException(
                    'Verification must include an effectiveness assessment (effectiveness_confirmed).'
                );
            }

            $hasEvidence = filled($action->completion_notes)
                || ! empty($action->completion_evidence_paths)
                || $action->attachments()->exists();
            if (! $hasEvidence) {
                throw ValidationException::withMessages([
                    'evidence_reviewed' => 'Completion evidence is no longer available. Return the action for rework.',
                ]);
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
        });
    }

    /**
     * Close a verified action — verified → closed.
     *
     * Also checks if all actions on the parent HsEvent are now closed/verified,
     * and if so moves the event to monitoring status.
     */
    public function close(HsCorrectiveAction $action, ?int $closedBy = null): HsCorrectiveAction
    {
        return DB::transaction(function () use ($action, $closedBy): HsCorrectiveAction {
            $action = HsCorrectiveAction::query()
                ->whereKey($action->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertTransition($action, HsCorrectiveAction::STATUS_CLOSED);
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
    /*  Query helpers */
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
    /*  Internal helpers */
    /* ------------------------------------------------------------------ */

    private function assertAcceptedHandoverIfIncidentBacked(HsEvent $event): void
    {
        $incidentBacked = $event->control_room_alert_id !== null
            || $event->source_type === ClientIncident::class;
        if (! $incidentBacked) {
            return;
        }

        if ($event->handover_status !== HsEvent::HANDOVER_ACCEPTED
            || $event->owner_user_id === null
            || $event->accepted_by_user_id === null
            || $event->accepted_at === null) {
            throw new InvalidArgumentException(
                'Health & Safety must accept the incident handover with an approved owner before recommendation work is created.',
            );
        }
    }

    private function eligibleOwner(HsEvent $event, int $ownerId, User $actor): User
    {
        $query = User::query()->whereKey($ownerId);
        $this->siteAccess->applyHsEventStaffScope(
            $query,
            $event,
            $actor,
            UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS,
        );
        $owner = $query->first();

        if (! $owner || ! $owner->canDo('hazards.manage')) {
            throw new InvalidArgumentException(
                'Choose an eligible approved H&S owner for this event site.',
            );
        }

        return $owner;
    }

    /** @param array<string, mixed> $data */
    private function requiredPositiveInteger(array $data, string $field, string $message): int
    {
        $value = $data[$field] ?? null;
        if (! is_numeric($value) || (int) $value <= 0) {
            throw new InvalidArgumentException($message);
        }

        return (int) $value;
    }

    /** @param array<string, mixed> $data */
    private function requiredDueDate(array $data): string
    {
        $value = $data['due_date'] ?? null;
        $date = is_string($value)
            ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value)
            : false;

        if (! $date || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('A due date in YYYY-MM-DD format is required.');
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private function requiredPriority(array $data): string
    {
        $priority = $data['priority'] ?? null;
        if (! in_array($priority, [
            HsCorrectiveAction::PRIORITY_LOW,
            HsCorrectiveAction::PRIORITY_MEDIUM,
            HsCorrectiveAction::PRIORITY_HIGH,
            HsCorrectiveAction::PRIORITY_CRITICAL,
        ], true)) {
            throw new InvalidArgumentException('A valid corrective action priority is required.');
        }

        return (string) $priority;
    }

    /** @param array<string, mixed> $data */
    private function lockTransferableTask(HsEvent $event, array $data): AlertTask
    {
        $taskId = $this->requiredPositiveInteger(
            $data,
            'source_control_room_task_id',
            'A source Control Room task is required when transferring responsibility.',
        );
        if ($event->control_room_alert_id === null) {
            throw new InvalidArgumentException(
                'This H&S event has no Control Room journey from which a task can be transferred.',
            );
        }

        $task = AlertTask::query()
            ->whereKey($taskId)
            ->lockForUpdate()
            ->first();
        if (! $task || (int) $task->alert_id !== (int) $event->control_room_alert_id) {
            throw new InvalidArgumentException(
                'Choose an unresolved Control Room task from this incident journey.',
            );
        }

        $hasReciprocalAction = HsCorrectiveAction::query()
            ->where('source_control_room_task_id', $task->id)
            ->exists();
        if (in_array($task->status, AlertTask::TERMINAL_STATUSES, true)
            || $task->transferred_to_hs_corrective_action_id !== null
            || $task->transferred_at !== null
            || $task->transferred_by_user_id !== null
            || $hasReciprocalAction) {
            throw new InvalidArgumentException(
                'Choose an active Control Room task that has not already been transferred.',
            );
        }

        return $task;
    }

    /** @param array<string, mixed> $data */
    private function assertExactRecommendationRetry(
        HsCorrectiveAction $action,
        HsEvent $event,
        array $data,
        int $ownerId,
        string $dueDate,
        string $priority,
        string $responsibilityChoice,
    ): void {
        $matches = (int) $action->assigned_to_user_id === $ownerId
            && $action->due_date?->toDateString() === $dueDate
            && $action->priority === $priority;

        if ($responsibilityChoice === self::RESPONSIBILITY_TRANSFER_TASK) {
            $taskId = $this->requiredPositiveInteger(
                $data,
                'source_control_room_task_id',
                'A source Control Room task is required when transferring responsibility.',
            );
            $task = AlertTask::query()->whereKey($taskId)->lockForUpdate()->first();
            $matches = $matches
                && $task !== null
                && (int) $task->alert_id === (int) $event->control_room_alert_id
                && (int) $action->source_control_room_task_id === $taskId
                && $task->status === AlertTask::STATUS_TRANSFERRED
                && (int) $task->transferred_to_hs_corrective_action_id === (int) $action->id
                && $task->transferred_at !== null
                && $task->transferred_by_user_id !== null;
        } else {
            $reason = trim((string) ($data['new_responsibility_reason'] ?? ''));
            $matches = $matches
                && mb_strlen($reason) >= 10
                && $action->source_control_room_task_id === null
                && $action->description === $reason;
        }

        if (! $matches) {
            throw new InvalidArgumentException(
                'A different corrective action handover already exists for this recommendation.',
            );
        }
    }

    private function persistCorrectiveActionDisposition(
        ?HsRecommendationDisposition $disposition,
        HsInvestigation $investigation,
        int $recommendationIndex,
        HsCorrectiveAction $action,
        User $actor,
    ): HsRecommendationDisposition {
        if ($disposition) {
            if ($disposition->disposition !== HsRecommendationDisposition::DISPOSITION_CORRECTIVE_ACTION
                || (int) $disposition->hs_corrective_action_id !== (int) $action->id) {
                throw new InvalidArgumentException(
                    'The existing recommendation outcome does not match this corrective action handover.',
                );
            }

            return $disposition;
        }

        $disposition = new HsRecommendationDisposition([
            'hs_investigation_id' => $investigation->id,
            'recommendation_index' => $recommendationIndex,
        ]);
        $disposition->fill([
            'disposition' => HsRecommendationDisposition::DISPOSITION_CORRECTIVE_ACTION,
            'reason' => null,
            'hs_corrective_action_id' => $action->id,
            'decided_by_user_id' => $actor->id,
            'decided_at' => now(),
        ]);
        $disposition->save();

        return $disposition;
    }

    private function notifyOwner(HsCorrectiveAction $action, User $owner): void
    {
        $owner->notify(new AppEventNotification([
            'title' => 'Corrective action assigned: '.$action->reference_number,
            'message' => "{$action->title} is assigned to you and due {$action->due_date?->toDateString()}.",
            'type' => 'health_safety_corrective_action_assigned',
            'hs_corrective_action_id' => $action->id,
            'hs_event_id' => $action->hs_event_id,
            'due_date' => $action->due_date?->toDateString(),
            'url' => "/health-safety/corrective-actions?event={$action->hs_event_id}&action={$action->id}",
        ]));
    }

    private function assertTransition(HsCorrectiveAction $action, string $targetStatus): void
    {
        if (! $action->canTransitionTo($targetStatus)) {
            throw new InvalidArgumentException(
                "Cannot transition corrective action from '{$action->status}' to '{$targetStatus}'."
            );
        }
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
