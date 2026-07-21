<?php

namespace App\Domain\It\Services;

use App\Domain\It\Data\ItTransitionInput;
use App\Domain\It\Enums\ItWorkflowState;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\ControlRoomAlert;
use App\Models\ItChange;
use App\Models\ItService;
use App\Models\ItTicket;
use App\Models\ItTicketEvent;
use App\Models\Site;
use App\Models\User;
use App\Support\LegacyStorageContext;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ItChangeService
{
    private const PROFILE_FIELDS = [
        'change_type', 'risk_level', 'is_restricted', 'impact_summary',
        'implementation_plan', 'validation_plan', 'backout_plan',
        'maintenance_starts_at', 'maintenance_ends_at', 'actual_outcome',
        'validation_result', 'validation_summary', 'backout_summary', 'pir_summary',
    ];

    public function __construct(
        private readonly ItWorkTransitionService $transitionService,
        private readonly ItTicketLinkService $linkService,
        private readonly ItWorkAccessService $workAccess,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data): ItChange
    {
        return DB::transaction(function () use ($actor, $data): ItChange {
            if (! $this->workAccess->canAssignScope(
                $actor,
                $data['site_id'] ?? null,
                (bool) ($data['is_organisation_wide'] ?? false),
            )) {
                throw new DomainException('Choose an approved Site or authorised organisation-wide scope.');
            }

            $storageContextId = LegacyStorageContext::id();
            $requiresApproval = $this->dataNeedsApproval($data);
            $ticket = ItTicket::createWithReference([
                'tenant_id' => $storageContextId,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'requester_user_id' => $actor->id,
                'requested_for_user_id' => $actor->id,
                'category' => $data['category'],
                'priority' => $data['priority'],
                'site_id' => $data['site_id'],
                'is_organisation_wide' => $data['is_organisation_wide'],
                'impact' => 'organization',
                'urgency' => $data['priority'] === 'urgent' ? 'critical' : $data['priority'],
                'work_type' => 'change',
                'workflow_state' => 'draft',
                'source' => 'agent',
                'status' => 'open',
                'requires_approval' => $requiresApproval,
            ]);
            $ticket->stampSlaDueDates();
            $ticket->save();
            ItTicketEvent::record($ticket, 'created', $actor->id, [
                'source' => 'change_management',
                'change_type' => $data['change_type'],
            ]);

            $change = ItChange::query()->create([
                'tenant_id' => $storageContextId,
                'ticket_id' => $ticket->id,
                ...Arr::only($data, self::PROFILE_FIELDS),
                'created_by_user_id' => $actor->id,
                'updated_by_user_id' => $actor->id,
            ]);
            $this->syncLinks($change, $actor, $data);

            return $change->fresh('ticket');
        });
    }

    /** @param array<string, mixed> $data */
    public function update(ItChange $change, User $actor, array $data): ItChange
    {
        return DB::transaction(function () use ($change, $actor, $data): ItChange {
            $change = $this->lockedChange($change, $actor);
            $ticket = $change->ticket()->lockForUpdate()->firstOrFail();

            $governanceFields = array_intersect(array_keys($data), ['change_type', 'risk_level', 'is_restricted']);
            if ($governanceFields !== []
                && ! in_array($ticket->workflow_state, ['draft', 'assessment'], true)) {
                throw new DomainException('Change type, risk, and restriction can only be edited during draft or assessment.');
            }

            $ticket->fill(Arr::only($data, ['title', 'description', 'category', 'priority', 'next_action']));
            $ticketChanged = array_keys($ticket->getDirty());
            $priorityChanged = $ticket->isDirty('priority');
            $ticket->save();
            if ($priorityChanged) {
                $ticket->stampSlaDueDates();
                $ticket->save();
            }

            $change->fill(Arr::only($data, self::PROFILE_FIELDS));
            $profileChanged = array_keys($change->getDirty());
            $change->updated_by_user_id = $actor->id;
            $change->save();

            $ticket->requires_approval = $change->needsApproval();
            $ticket->save();
            $this->syncLinks($change, $actor, $data);

            ItTicketEvent::record($ticket, 'change_updated', $actor->id, [
                'ticket_fields' => $ticketChanged,
                'change_fields' => $profileChanged,
                'links_updated' => $this->linksWereSupplied($data),
            ]);

            return $change->fresh('ticket');
        });
    }

    public function transition(
        ItChange $change,
        User $actor,
        ItWorkflowState $state,
        string $reason,
        ?string $resolutionCode = null,
        ?string $resolutionSummary = null,
    ): ItChange {
        return DB::transaction(function () use (
            $change,
            $actor,
            $state,
            $reason,
            $resolutionCode,
            $resolutionSummary,
        ): ItChange {
            $change = $this->lockedChange($change, $actor);
            $ticket = $change->ticket;
            $this->guardTransition($change, $actor, $state);

            $this->transitionService->transition($ticket, new ItTransitionInput(
                actor: $actor,
                to: $state,
                reason: $reason,
                resolutionCode: $resolutionCode,
                resolutionSummary: $resolutionSummary,
                source: 'change_management',
            ));

            if ($state === ItWorkflowState::Implementing && $change->implemented_at === null) {
                $change->implemented_at = now();
                $change->implemented_by_user_id = $actor->id;
            }
            if ($state === ItWorkflowState::Completed) {
                $change->validated_at = now();
                $change->validated_by_user_id = $actor->id;
            }
            if ($state === ItWorkflowState::BackedOut) {
                $change->backed_out_at = now();
            }
            if ($state === ItWorkflowState::Review) {
                $change->reviewed_at = now();
                $change->reviewed_by_user_id = $actor->id;
            }
            $change->updated_by_user_id = $actor->id;
            $change->save();

            return $change->fresh('ticket');
        });
    }

    private function guardTransition(ItChange $change, User $actor, ItWorkflowState $state): void
    {
        if (in_array($state, [
            ItWorkflowState::ApprovalPending,
            ItWorkflowState::Approved,
            ItWorkflowState::Scheduled,
            ItWorkflowState::Implementing,
        ], true)) {
            $this->guardPlans($change);
        }

        if ($state === ItWorkflowState::ApprovalPending && ! $change->needsApproval()) {
            throw new DomainException('This pre-authorized standard change does not require an approval request.');
        }

        if (in_array($state, [ItWorkflowState::Approved, ItWorkflowState::Scheduled, ItWorkflowState::Implementing], true)
            && $change->needsApproval()
            && $change->ticket->approvalState() !== 'approved') {
            throw new DomainException('An independent approved decision is required before this change can proceed.');
        }

        if (in_array($state, [ItWorkflowState::Scheduled, ItWorkflowState::Implementing], true)
            && $change->change_type !== 'emergency') {
            if ($change->maintenance_starts_at === null || $change->maintenance_ends_at === null) {
                throw new DomainException('A complete maintenance window is required before scheduling or implementation.');
            }
            if ($change->maintenance_ends_at->lte($change->maintenance_starts_at)) {
                throw new DomainException('The maintenance window must end after it starts.');
            }
        }

        if (in_array($state, [ItWorkflowState::Validation, ItWorkflowState::Failed, ItWorkflowState::BackedOut], true)
            && blank($change->actual_outcome)) {
            throw new DomainException('The actual implementation outcome must be recorded first.');
        }
        if ($state === ItWorkflowState::BackedOut && blank($change->backout_summary)) {
            throw new DomainException('The backout outcome must be recorded before marking the change backed out.');
        }
        if ($state === ItWorkflowState::Completed) {
            if ($change->validation_result !== 'successful' || blank($change->validation_summary)) {
                throw new DomainException('Successful validation evidence is required before completing the change.');
            }
            if ($change->needsIndependentValidation()) {
                $approval = $change->ticket->approvals()->where('status', 'approved')->latest('id')->first();
                if ((int) $change->implemented_by_user_id === (int) $actor->id
                    || (int) $approval?->approver_id === (int) $actor->id) {
                    throw new DomainException('High-risk or restricted changes require validation by someone other than the implementer and approver.');
                }
            }
        }
        if ($state === ItWorkflowState::Review && blank($change->pir_summary)) {
            throw new DomainException('A post-implementation review summary is required before review.');
        }
    }

    private function guardPlans(ItChange $change): void
    {
        if (blank($change->impact_summary)
            || blank($change->implementation_plan)
            || blank($change->validation_plan)
            || blank($change->backout_plan)) {
            throw new DomainException('Impact, implementation, validation, and backout plans are required before approval or execution.');
        }
    }

    private function lockedChange(ItChange $change, User $actor): ItChange
    {
        $locked = ItChange::query()
            ->whereKey($change->id)
            ->with('ticket')
            ->lockForUpdate()
            ->firstOrFail();

        if (! $locked->ticket || ! $this->workAccess->canWork($actor, $locked->ticket)) {
            throw new DomainException('You are not allowed to manage IT changes.');
        }

        return $locked;
    }

    /** @param array<string, mixed> $data */
    private function syncLinks(ItChange $change, User $actor, array $data): void
    {
        $ticket = $change->ticket;
        $this->syncExternalLinks($ticket, $actor, $data, 'service_ids', ItService::class, 'affected_service');
        $this->syncExternalLinks($ticket, $actor, $data, 'site_ids', Site::class, 'affected_site');
        $this->syncExternalLinks($ticket, $actor, $data, 'device_ids', Device::class, 'affected_device');
        $this->syncExternalLinks($ticket, $actor, $data, 'alert_ids', ControlRoomAlert::class, 'source_alert');
        $this->syncTicketLinks($ticket, $actor, $data, 'incident_ids', ['incident', 'major_incident'], 'related_incident');
        $this->syncTicketLinks($ticket, $actor, $data, 'problem_ids', ['problem'], 'related_problem');
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  array<string, mixed>  $data
     */
    private function syncExternalLinks(
        ItTicket $ticket,
        User $actor,
        array $data,
        string $key,
        string $modelClass,
        string $relationship,
    ): void {
        if (! array_key_exists($key, $data)) {
            return;
        }

        $ids = array_map('intval', (array) $data[$key]);
        $targets = $modelClass::query()->whereIn('id', $ids)->get()->keyBy('id');
        if ($targets->count() !== count(array_unique($ids))) {
            throw new DomainException('One or more linked operational records no longer exist.');
        }

        $existing = $ticket->links()
            ->where('relationship', $relationship)
            ->where('linkable_type', (new $modelClass)->getMorphClass())
            ->whereNotIn('linkable_id', $ids === [] ? [-1] : $ids)
            ->get();
        foreach ($existing as $link) {
            $target = $modelClass::query()->find($link->linkable_id);
            if (! $target) {
                throw new DomainException('A linked operational record no longer exists.');
            }
            $this->linkService->unlink($ticket, $target, $relationship, $actor->id);
        }

        foreach ($targets as $target) {
            $this->linkService->link($ticket, $target, $relationship, [], $actor->id);
        }
    }

    /** @param array<string, mixed> $data @param array<int, string> $workTypes */
    private function syncTicketLinks(
        ItTicket $source,
        User $actor,
        array $data,
        string $key,
        array $workTypes,
        string $relationship,
    ): void {
        if (! array_key_exists($key, $data)) {
            return;
        }

        $ids = array_map('intval', (array) $data[$key]);
        $targets = ItTicket::query()
            ->whereIn('id', $ids)
            ->whereIn('work_type', $workTypes)
            ->get()
            ->keyBy('id');
        if ($targets->count() !== count(array_unique($ids))) {
            throw new DomainException('Every related work item must have the expected work type.');
        }

        $existing = $source->links()
            ->where('relationship', $relationship)
            ->where('linkable_type', (new ItTicket)->getMorphClass())
            ->get();
        foreach ($existing as $link) {
            if (! in_array((int) $link->linkable_id, $ids, true)) {
                $target = ItTicket::query()->find($link->linkable_id);
                if ($target) {
                    $this->linkService->unlink($source, $target, $relationship, $actor->id);
                    $this->linkService->unlink($target, $source, 'related_change', $actor->id);
                } else {
                    throw new DomainException('A related work item no longer exists.');
                }
            }
        }
        foreach ($targets as $target) {
            $this->linkService->link($source, $target, $relationship, [], $actor->id);
            $this->linkService->link($target, $source, 'related_change', [], $actor->id);
        }
    }

    /** @param array<string, mixed> $data */
    private function linksWereSupplied(array $data): bool
    {
        return array_intersect(array_keys($data), [
            'service_ids', 'site_ids', 'device_ids', 'alert_ids', 'incident_ids', 'problem_ids',
        ]) !== [];
    }

    /** @param array<string, mixed> $data */
    private function dataNeedsApproval(array $data): bool
    {
        return ($data['change_type'] ?? 'normal') !== 'standard'
            || ($data['risk_level'] ?? 'medium') !== 'low'
            || (bool) ($data['is_restricted'] ?? false);
    }
}
