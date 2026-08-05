<?php

namespace App\Support\HealthSafety;

use App\Models\HsCorrectiveAction;
use App\Models\User;

class HsCorrectiveActionPresenter
{
    public const EVIDENCE_LOADED = 'loaded';

    public const EVIDENCE_UNAVAILABLE = 'unavailable';

    public function __construct(
        private readonly HsCorrectiveActionActivityLabels $activityLabels,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(
        HsCorrectiveAction $action,
        User $viewer,
        bool $canManage,
        bool $canAccessEvidence,
        string $evidenceLoadState = self::EVIDENCE_LOADED,
    ): array {
        $isOwner = (int) $action->assigned_to_user_id === (int) $viewer->id;
        $canParticipateInEvidence = $canAccessEvidence && ($canManage || $isOwner);
        $evidenceLoaded = $canParticipateInEvidence
            && $evidenceLoadState === self::EVIDENCE_LOADED
            && $action->relationLoaded('attachments');
        $auditLogs = $action->relationLoaded('auditLogs')
            ? $action->auditLogs
            : collect();
        $sourceTask = $action->sourceControlRoomTask ? [
            'id' => $action->sourceControlRoomTask->id,
            'reference' => "CR task #{$action->sourceControlRoomTask->id}",
            'title' => $action->sourceControlRoomTask->title,
        ] : null;
        $owner = $action->assignedTo ? [
            'id' => $action->assignedTo->id,
            'name' => $action->assignedTo->name,
        ] : null;
        $completedBy = $evidenceLoaded && $action->completedBy ? [
            'id' => $action->completedBy->id,
            'name' => $action->completedBy->name,
        ] : null;
        $latestReturnReason = $this->activityLabels->latestReturnReason($auditLogs);
        if ($latestReturnReason === null
            && in_array($action->status, [
                HsCorrectiveAction::STATUS_IN_PROGRESS,
                HsCorrectiveAction::STATUS_COMPLETED,
            ], true)) {
            $latestReturnReason = $action->verification_notes;
        }
        $canRemoveAttachment = $action->acceptsEvidenceChanges()
            && ($action->status !== HsCorrectiveAction::STATUS_COMPLETED
                || filled($action->completion_notes)
                || ! empty($action->completion_evidence_paths)
                || ($evidenceLoaded && $action->attachments->count() > 1));

        return [
            'id' => $action->id,
            'reference_number' => $action->reference_number,
            'title' => $action->title,
            'action_type' => $action->action_type,
            'priority' => $action->priority,
            'status' => $action->status,
            'owner' => $owner,
            'assigned_to_name' => $owner['name'] ?? null,
            'due_date' => $action->due_date?->toDateString(),
            'is_overdue' => $action->isOverdue(),
            'completed_at' => $evidenceLoaded
                ? $action->completed_at?->toIso8601String()
                : null,
            'completed_by_user_id' => $evidenceLoaded
                ? $action->completed_by_user_id
                : null,
            'completed_by_name' => $completedBy['name'] ?? null,
            'can_verify' => $canManage
                && $canAccessEvidence
                && $evidenceLoaded
                && $action->status === HsCorrectiveAction::STATUS_COMPLETED
                && (int) $action->assigned_to_user_id !== (int) $viewer->id
                && (int) $action->completed_by_user_id !== (int) $viewer->id,
            'verified_at' => $action->verified_at?->toIso8601String(),
            'verified_by_name' => $action->verifiedBy?->name,
            'effectiveness_confirmed' => $action->effectiveness_confirmed,
            'hs_investigation_id' => $action->hs_investigation_id,
            'recommendation_index' => $action->recommendation_index,
            'recommendation' => $this->recommendation($action),
            'source' => $this->source($action, $sourceTask),
            'source_task' => $sourceTask,
            'evidence' => [
                'can_upload' => $canParticipateInEvidence
                    && $action->acceptsEvidenceChanges(),
                'completion_notes' => $evidenceLoaded
                    ? $action->completion_notes
                    : null,
                'attachments' => $evidenceLoaded
                    ? $action->attachments
                        ->map(fn ($attachment) => [
                            'id' => $attachment->id,
                            'original_name' => $attachment->original_name,
                            'mime_type' => $attachment->mime_type,
                            'size_bytes' => $attachment->size_bytes,
                            'description' => $attachment->description,
                            'uploaded_by' => $attachment->uploader?->name,
                            'created_at' => $attachment->created_at?->toIso8601String(),
                            'download_url' => "/health-safety/events/{$action->hs_event_id}/corrective-actions/{$action->id}/evidence/{$attachment->id}",
                            'can_remove' => $canRemoveAttachment,
                        ])
                        ->values()
                        ->all()
                    : [],
                'legacy_paths' => $evidenceLoaded
                    ? ($action->completion_evidence_paths ?? [])
                    : [],
                'completed_by' => $completedBy,
                'completed_at' => $evidenceLoaded
                    ? $action->completed_at?->toIso8601String()
                    : null,
                'load_state' => $evidenceLoaded
                    ? self::EVIDENCE_LOADED
                    : self::EVIDENCE_UNAVAILABLE,
            ],
            'rework' => [
                'latest_reason' => $evidenceLoaded ? $latestReturnReason : null,
            ],
            'history' => $evidenceLoaded
                ? $this->activityLabels->history(
                    $auditLogs,
                    $action->assigned_to_user_id,
                )
                : [],
        ];
    }

    private function recommendation(HsCorrectiveAction $action): ?string
    {
        if ($action->recommendation_index === null) {
            return null;
        }

        $recommendation = data_get(
            $action->hsInvestigation?->recommendations,
            "{$action->recommendation_index}.description",
        );

        return is_string($recommendation) && filled($recommendation)
            ? $recommendation
            : null;
    }

    /**
     * @param  array{id: int, reference: string, title: string}|null  $sourceTask
     * @return array{type: string, id?: int, reference?: string, title?: string, reason?: string|null}
     */
    private function source(
        HsCorrectiveAction $action,
        ?array $sourceTask,
    ): array {
        if ($sourceTask !== null) {
            return ['type' => 'control_room_task', ...$sourceTask];
        }

        if ($action->recommendation_index !== null) {
            return [
                'type' => 'new_responsibility',
                'reason' => $action->description,
            ];
        }

        return ['type' => 'standalone'];
    }
}
