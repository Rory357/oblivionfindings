<?php

namespace App\Support\Incidents;

use App\Models\ControlRoom\AlertTask;
use App\Models\ControlRoom\EvidenceItem;
use App\Models\ControlRoom\OperatorNote;
use App\Models\ControlRoomAlert;
use App\Models\User;
use App\Services\ControlRoom\ControlRoomAlertAccessService;

final class LinkedOperationalEvidencePresenter
{
    public function __construct(
        private readonly ControlRoomAlertAccessService $access,
    ) {}

    /**
     * Present canonical Control Room records through an already-authorised
     * parent surface. The callback keeps file access inside that parent
     * module's authenticated route instead of copying files or weakening
     * Control Room permissions.
     *
     * @param  callable(EvidenceItem): (?string)  $downloadUrl
     * @return array<string, mixed>
     */
    public function present(
        ControlRoomAlert $sourceAlert,
        User $viewer,
        callable $downloadUrl,
    ): array {
        $alert = ControlRoomAlert::query()
            ->with([
                'site:id,name',
                'client:id,first_name,last_name',
            ])
            ->findOrFail($sourceAlert->getKey());

        $notes = $alert->operatorNotes()
            ->with('user:id,name')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $tasks = $alert->tasks()
            ->with([
                'assignedTo:id,name',
                'transferredCorrectiveAction:id,hs_event_id,source_control_room_task_id,reference_number',
                'legacyTransferredCorrectiveAction:id,hs_event_id,reference_number',
            ])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $evidencePacks = $alert->evidencePacks()
            ->with([
                'evidenceItems' => fn ($query) => $query
                    ->with('capturedBy:id,name')
                    ->orderBy('created_at')
                    ->orderBy('id'),
            ])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $communications = $alert->communications()
            ->conversational()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->reverse()
            ->values();

        return [
            'label' => 'Linked Control Room evidence',
            'read_only' => true,
            'source' => [
                'id' => $alert->id,
                'reference' => $alert->reference_number,
                'alert_type' => $alert->alert_type,
                'severity' => $alert->severity,
                'status' => $alert->status,
                'href' => $this->access->canView($alert, $viewer)
                    ? "/control-room/alerts/{$alert->id}"
                    : null,
                'site' => $alert->site ? [
                    'id' => $alert->site->id,
                    'name' => $alert->site->name,
                ] : null,
                'client' => $alert->client ? [
                    'id' => $alert->client->id,
                    'name' => trim($alert->client->first_name.' '.$alert->client->last_name),
                ] : null,
                'triggered_at' => $alert->triggered_at?->toIso8601String(),
                'acknowledged_at' => $alert->acknowledged_at?->toIso8601String(),
                'resolved_at' => $alert->resolved_at?->toIso8601String(),
                'closed_at' => $alert->closed_at?->toIso8601String(),
                'created_at' => $alert->created_at?->toIso8601String(),
                'updated_at' => $alert->updated_at?->toIso8601String(),
            ],
            'notes' => $notes->map(fn (OperatorNote $note): array => [
                'id' => $note->id,
                'type' => $note->type,
                'purpose' => $note->purpose,
                'purpose_label' => $this->purposeLabel($note->purpose),
                'content' => $note->content,
                'author' => $note->user ? [
                    'id' => $note->user->id,
                    'name' => $note->user->name,
                ] : null,
                'created_at' => $note->created_at?->toIso8601String(),
            ])->values()->all(),
            'tasks' => $tasks->map(function (AlertTask $task): array {
                $correctiveAction = $task->transferredCorrectiveAction;
                $transferred = $correctiveAction !== null
                    || $task->transferred_to_hs_corrective_action_id !== null
                    || $task->transferred_at !== null
                    || $task->transferred_by_user_id !== null
                    || $task->status === AlertTask::STATUS_TRANSFERRED;
                $active = ! in_array($task->status, AlertTask::TERMINAL_STATUSES, true);

                return [
                    'id' => $task->id,
                    'title' => $task->title,
                    'description' => $task->description,
                    'status' => $task->status,
                    'priority' => $task->priority,
                    'owner' => $task->assignedTo ? [
                        'id' => $task->assignedTo->id,
                        'name' => $task->assignedTo->name,
                    ] : null,
                    'assignee' => $task->assignedTo?->name,
                    'due_at' => $task->due_at?->toIso8601String(),
                    'overdue' => $active
                        && $task->due_at !== null
                        && $task->due_at->isPast(),
                    'transfer' => [
                        'state' => $transferred
                            ? 'transferred'
                            : ($active ? 'open' : 'retained'),
                        'corrective_action_reference' => $correctiveAction?->reference_number,
                        'transferred_at' => $task->transferred_at?->toIso8601String(),
                    ],
                ];
            })->values()->all(),
            'evidence_packs' => $evidencePacks->map(fn ($pack): array => [
                'id' => $pack->id,
                'title' => $pack->title,
                'status' => $pack->status,
                'item_count' => $pack->evidenceItems->count(),
                'items' => $pack->evidenceItems->map(fn (EvidenceItem $item): array => [
                    'id' => $item->id,
                    'type' => $item->type,
                    'title' => $item->title,
                    'description' => $item->description,
                    'mime_type' => $item->mime_type,
                    'file_size' => $item->file_size,
                    'captured_at' => $item->captured_at?->toIso8601String(),
                    'captured_by' => $item->capturedBy ? [
                        'id' => $item->capturedBy->id,
                        'name' => $item->capturedBy->name,
                    ] : null,
                    'download_url' => filled($item->storage_path)
                        ? $downloadUrl($item)
                        : null,
                    'created_at' => $item->created_at?->toIso8601String(),
                ])->values()->all(),
            ])->values()->all(),
            'communications' => $communications->map(fn ($communication): array => [
                'id' => $communication->id,
                'channel' => $communication->channel,
                'direction' => $communication->direction,
                'purpose' => $communication->purpose,
                'subject' => $communication->subject,
                'content' => $communication->content,
                'status' => $communication->status,
                'sent_at' => $communication->sent_at?->toIso8601String(),
                'delivered_at' => $communication->delivered_at?->toIso8601String(),
                'created_at' => $communication->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    private function purposeLabel(?string $purpose): string
    {
        return match ($purpose) {
            OperatorNote::PURPOSE_IMMEDIATE_CONTROLS => 'Immediate controls',
            OperatorNote::PURPOSE_ESCALATION_HANDOVER => 'Escalation or handover',
            default => 'General update',
        };
    }
}
