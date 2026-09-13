<?php

namespace App\Domain\Governance\Data;

use App\Domain\Governance\Enums\GovernanceArea;
use App\Domain\Governance\Enums\GovernanceWorkKind;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

class GovernanceWorkItem implements Arrayable, JsonSerializable
{
    /**
     * @param  array{type: string, id: int, reference: string, href: string}  $source
     * @param  array{key: string, label: string, href: string, allowed: bool, blocked_reason: ?string}  $requiredAction
     */
    public function __construct(
        public string $id,
        public GovernanceWorkKind|string $kind,
        public array $source,
        public string $title,
        public string $reason,
        public string $priority,
        public string $status,
        public ?string $dueAt = null,
        public ?string $dueDate = null,
        public ?int $assigneeUserId = null,
        public ?int $boardMemberId = null,
        public array $requiredAction = [],
        public ?int $sourceVersion = null,
        public ?string $availableAsOf = null,
        public GovernanceArea|string|null $area = null,
        public ?string $ownerName = null,
        public ?array $receipt = null,
    ) {
        $this->availableAsOf ??= now()->toIso8601String();
    }

    public function toArray(): array
    {
        $kindStr = $this->kind instanceof GovernanceWorkKind ? $this->kind->value : (string) $this->kind;
        $areaStr = $this->area instanceof GovernanceArea ? $this->area->label() : ($this->area ?? $this->source['type'] ?? 'Governance');
        $areaKey = $this->area instanceof GovernanceArea ? $this->area->value : strtolower(str_replace(' ', '_', (string) ($this->area ?? $this->source['type'] ?? 'governance')));

        return [
            'id' => $this->id,
            'kind' => $kindStr,
            'source' => $this->source,
            'title' => $this->title,
            'reason' => $this->reason,
            'priority' => $this->priority,
            'status' => $this->status,
            'due_at' => $this->dueAt,
            'due_date' => $this->dueDate,
            'assignee_user_id' => $this->assigneeUserId,
            'board_member_id' => $this->boardMemberId,
            'required_action' => $this->requiredAction,
            'source_version' => $this->sourceVersion,
            'available_as_of' => $this->availableAsOf,
            'receipt' => $this->receipt,

            // Backward compatibility for existing WorkflowAction / BoardPriorityCard consumers
            'area' => $areaStr,
            'area_key' => $areaKey,
            'detail' => $this->reason,
            'action_label' => $this->requiredAction['label'] ?? 'View',
            'action_url' => $this->requiredAction['href'] ?? '#',
            'owner' => $this->ownerName,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
