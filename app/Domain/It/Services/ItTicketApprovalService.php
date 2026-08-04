<?php

namespace App\Domain\It\Services;

use App\Models\ItTicket;
use App\Models\ItTicketApproval;
use App\Models\ItTicketEvent;
use App\Models\User;
use App\Services\AuditLogger;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class ItTicketApprovalService
{
    public function __construct(
        private readonly ItWorkAccessService $workAccess,
    ) {}

    public function request(ItTicket $ticket, User $actor, ?string $reason = null): ItTicketApproval
    {
        return DB::transaction(function () use ($ticket, $actor, $reason): ItTicketApproval {
            $ticket = $this->lockTicket($ticket->getKey());
            $this->guardActor($ticket, $actor);

            if (! $ticket->requires_approval) {
                throw new DomainException('This ticket does not require manager approval.');
            }

            if ($ticket->approvals()->whereIn('status', ['pending', 'approved'])->exists()) {
                throw new DomainException('This ticket already has an active approval decision.');
            }

            $approval = $ticket->approvals()->create([
                'requested_by' => $actor->id,
                'status' => 'pending',
                'reason' => $reason,
            ]);

            ItTicketEvent::record($ticket, 'approval_requested', $actor->id, [
                'approval_id' => $approval->id,
            ]);

            AuditLogger::logOrFail('it.ticket.approval.requested', $ticket, [
                'actor_id' => $actor->id,
                'approval_id' => $approval->id,
                'application_scope' => 'single_application',
            ]);

            return $approval->load('ticket');
        });
    }

    public function decide(
        ItTicketApproval $approval,
        User $actor,
        string $decision,
        ?string $reason = null,
    ): ItTicketApproval {
        return DB::transaction(function () use ($approval, $actor, $decision, $reason): ItTicketApproval {
            // Ticket-first ordering serializes the decision with settlement,
            // while the approval lock prevents contradictory late decisions.
            $ticket = $this->lockTicket($approval->it_ticket_id);
            $approval = ItTicketApproval::query()
                ->where('it_ticket_id', $ticket->id)
                ->lockForUpdate()
                ->findOrFail($approval->getKey());

            $this->guardActor($ticket, $actor);
            if ((int) $approval->requested_by === (int) $actor->id) {
                throw new AuthorizationException('You cannot decide your own approval request.');
            }
            if ($approval->status !== 'pending') {
                throw new DomainException('This approval has already been decided.');
            }
            if (! in_array($decision, ['approve', 'reject'], true)) {
                throw new DomainException('Choose whether to approve or reject this request.');
            }
            if ($decision === 'reject' && blank($reason)) {
                throw new DomainException('Record a reason so the requester knows what to change.');
            }

            $status = $decision === 'approve' ? 'approved' : 'rejected';
            $approval->forceFill([
                'status' => $status,
                'approver_id' => $actor->id,
                'reason' => $reason ?? $approval->reason,
                'decided_at' => now(),
            ])->save();

            ItTicketEvent::record($ticket, 'approval_'.$status, $actor->id, [
                'approval_id' => $approval->id,
            ]);

            $auditAction = $status === 'approved'
                ? 'it.ticket.approval.approved'
                : 'it.ticket.approval.rejected';
            AuditLogger::logOrFail($auditAction, $ticket, [
                'actor_id' => $actor->id,
                'approval_id' => $approval->id,
                'application_scope' => 'single_application',
            ]);

            return $approval->load('ticket');
        });
    }

    private function lockTicket(int|string $ticketId): ItTicket
    {
        return ItTicket::query()->lockForUpdate()->findOrFail($ticketId);
    }

    private function guardActor(ItTicket $ticket, User $actor): void
    {
        if (! $actor->canDo('it.manage')) {
            throw new AuthorizationException('You are not allowed to manage ticket approvals.');
        }

        if (! $this->workAccess->canWork($actor, $ticket)) {
            throw (new ModelNotFoundException)->setModel(ItTicket::class, [$ticket->id]);
        }
    }
}
