<?php

namespace App\Domain\It\Presenters;

use App\Domain\It\Services\ItTicketApprovalResponsibilityService;
use App\Domain\It\Services\ItTicketApprovalService;
use App\Domain\It\Services\ItWorkAccessService;
use App\Models\ItTicket;
use App\Models\ItTicketApproval;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Private approval work. Public ticket status does not carry reasons, selection lists or absence information. */
final class ItTicketApprovalPresenter
{
    public function __construct(private readonly ItWorkAccessService $access, private readonly ItTicketApprovalService $approvals,
        private readonly ItTicketApprovalResponsibilityService $responsibility) {}

    public function work(ItTicket $ticket, User $viewer): ?array
    {
        if (! $viewer->canDo('it.manage') || ! $this->access->canWork($viewer, $ticket)) {
            return null;
        }
        $ready = $this->approvals->storageReady();
        $open = ! $ticket->isMerged() && in_array($ticket->status, ItTicket::OPEN_STATUSES, true);
        $current = $ticket->approvals()->with(['requester:id,name', 'approver:id,name', 'primaryApprover:id,name', 'coverApprover:id,name'])->first();
        $canDecide = $ready && $open && $current && $this->responsibility->decisionBasis($current, $ticket, $viewer) !== null;

        return ['storage_ready' => $ready,
            'can_request' => $ready && $viewer->can('requestApproval', $ticket),
            'can_decide' => (bool) $canDecide,
            'can_withdraw' => $ready && $open && $current && $current->effectiveStatus() === 'pending'
                && ((int) $current->requested_by === (int) $viewer->id || $canDecide),
            'candidates' => $ready && $open && $ticket->requires_approval
                ? $this->responsibility->candidates($ticket, $viewer)->map(fn (User $user): array => [
                    'id' => (int) $user->id, 'name' => $user->name,
                    'available' => $this->responsibility->eligible((int) $user->id, $ticket, available: true) !== null,
                ])->all() : [],
            'current' => $current ? $this->record($current, $ticket) : null,
            'total' => $ticket->approvals()->count()];
    }

    /** A bounded page from the canonical approval records, read under the ticket's mutation lock. */
    public function history(ItTicket $ticket, User $viewer, array $input): array
    {
        return DB::transaction(function () use ($ticket, $viewer, $input): array {
            [$ticket, $viewer] = $this->approvals->lockContext($ticket, $viewer, mutation: false);
            abort_unless((int) $input['actor_user_id'] === (int) $viewer->id, 403);
            abort_unless($this->approvals->storageReady(), 503);
            abort_if(isset($input['expected_version']) && (int) $input['expected_version'] !== (int) $ticket->lock_version, 409);
            $page = (int) ($input['page'] ?? 1);
            $targetId = isset($input['approval_id']) ? (int) $input['approval_id'] : null;
            if ($targetId !== null) {
                $ticket->approvals()->whereKey($targetId)->firstOrFail(['id']);
                $newer = $ticket->approvals()->reorder()->where('id', '>', $targetId)->count();
                $page = intdiv($newer, 10) + 1;
            }
            $query = $ticket->approvals()->reorder()->orderByDesc('id');
            $total = $query->count();
            $entries = $query->with(['requester:id,name', 'approver:id,name', 'primaryApprover:id,name', 'coverApprover:id,name'])
                ->offset(($page - 1) * 10)->limit(10)->get();

            return ['viewer_user_id' => (int) $viewer->id, 'ticket_id' => (int) $ticket->id,
                'lock_version' => (int) $ticket->lock_version, 'review_nonce' => $input['review_nonce'],
                ...($targetId !== null ? ['target_approval_id' => $targetId] : []),
                'history' => ['page' => $page, 'per_page' => 10, 'total' => $total,
                    'next_page' => $total > $page * 10 ? $page + 1 : null,
                    'records' => $entries->map(fn (ItTicketApproval $approval): array => $this->record($approval, $ticket))->all()]];
        });
    }

    /** Caller must already hold current work access to this ticket. */
    public function record(ItTicketApproval $approval, ItTicket $ticket): array
    {
        $owner = $this->responsibility->effectiveOwner($approval, $ticket);
        $person = fn (?User $user): ?array => $user ? ['id' => (int) $user->id, 'name' => $user->name] : null;

        return ['id' => (int) $approval->id, 'status' => $approval->effectiveStatus(), 'recorded_status' => $approval->status,
            'requested_by' => $person($approval->requester), 'requested_at' => $approval->created_at?->toIso8601String(),
            'decided_by' => $person($approval->approver), 'decided_at' => $approval->decided_at?->toIso8601String(),
            'reason_evidence' => $approval->reasonEvidence(),
            'primary' => $person($approval->primaryApprover), 'cover' => $person($approval->coverApprover),
            'assignment_recorded_at' => $approval->assignment_recorded_at?->toIso8601String(),
            'current_responsibility' => $approval->effectiveStatus() === 'pending'
                ? ['person' => $person($owner['user']), 'basis' => $owner['basis']] : null,
            'decision_authority' => $approval->decision_authority,
            'expires_at' => $approval->expires_at?->toIso8601String(), 'remind_at' => $approval->remind_at?->toIso8601String(),
            'reminder_prepared_at' => $approval->reminder_prepared_at?->toIso8601String(),
            'reminder_last_checked_at' => $approval->reminder_last_checked_at?->toIso8601String(),
            'expired_at' => $approval->expired_at?->toIso8601String(), 'cancelled_at' => $approval->cancelled_at?->toIso8601String(),
            'cancelled_by_user_id' => $approval->cancelled_by_user_id !== null ? (int) $approval->cancelled_by_user_id : null,
            'cancellation_reason' => $approval->cancellation_reason];
    }
}
