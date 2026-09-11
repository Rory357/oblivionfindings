<?php

namespace App\Domain\It\Services;

use App\Domain\It\Data\ItTicketApprovalInput;
use App\Models\ItTicket;
use App\Models\ItTicketApproval;
use App\Models\ItTicketEvent;
use App\Models\User;
use App\Notifications\It\TicketApprovalNotification;
use App\Services\AuditLogger;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class ItTicketApprovalService
{
    public function __construct(
        private readonly ItWorkAccessService $workAccess,
        private readonly ItTicketVersionService $versions,
        private readonly ItEmailDeliveryService $deliveries,
        private readonly ItTicketApprovalResponsibilityService $responsibility,
    ) {}

    public function request(ItTicket $ticket, User $actor, ?string $reason = null, array $responsibility = []): ItTicketApproval
    {
        return DB::transaction(function () use ($ticket, $actor, $reason, $responsibility): ItTicketApproval {
            [$ticket, $actor] = $this->lockContext($ticket, $actor);
            $this->requireEvidenceStorage();
            $payload = ItTicketApprovalInput::normalize('request', ['reason' => $reason, ...Arr::only($responsibility, ItTicketApprovalInput::RESPONSIBILITY_FIELDS)]);
            $reason = $payload['reason'];
            // Receipt replay happens before entering this new-request boundary.
            $assignment = $this->responsibility->proposal($ticket, $actor, $payload);

            if (! $ticket->requires_approval) {
                throw new DomainException('This ticket does not require manager approval.');
            }

            foreach ($ticket->approvals()->where('status', 'pending')->whereNotNull('expires_at')
                ->where('expires_at', '<=', now())->lockForUpdate()->get() as $expired) {
                $this->recordExpiry($expired, $ticket);
            }

            if ($ticket->approvals()->whereIn('status', ['pending', 'approved'])->exists()) {
                throw new DomainException('This ticket already has an active approval decision.');
            }

            $approval = $ticket->approvals()->create([
                'requested_by' => $actor->id,
                'status' => 'pending',
                'reason' => $reason,
                'request_reason' => $reason,
                'request_reason_recorded_at' => now(),
                ...$assignment,
            ]);
            $ticket->forceFill(['lock_version' => (int) $ticket->lock_version + 1])->save();
            $owner = $this->responsibility->effectiveOwner($approval, $ticket)['user'];
            $recipients = $owner ? [$owner] : [];
            $this->deliveries->prepare($recipients, new TicketApprovalNotification($ticket, 'requested', (int) $approval->id));

            ItTicketEvent::record($ticket, 'approval_requested', $actor->id, [
                'approval_id' => $approval->id,
                'primary_approver_user_id' => $approval->primary_approver_user_id,
                'cover_approver_user_id' => $approval->cover_approver_user_id,
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
            [$ticket, $actor] = $this->lockContext($approval->ticket()->firstOrFail(), $actor);
            $this->requireEvidenceStorage();
            $approval = ItTicketApproval::query()
                ->where('it_ticket_id', $ticket->id)
                ->lockForUpdate()
                ->findOrFail($approval->getKey());

            if ((int) $approval->requested_by === (int) $actor->id) {
                throw new AuthorizationException('You cannot decide your own approval request.');
            }
            if ($approval->status !== 'pending') {
                throw new DomainException('This approval has already been decided.');
            }
            if ($approval->isPastDeadline()) {
                throw new DomainException('This approval deadline has passed. Review the expired request and raise a new approval.');
            }
            $authority = $this->responsibility->decisionBasis($approval, $ticket, $actor);
            if ($authority === null) {
                throw new AuthorizationException('Only the currently responsible approver or eligible absence cover may decide this request.');
            }
            if (! in_array($decision, ['approve', 'reject'], true)) {
                throw new DomainException('Choose whether to approve or reject this request.');
            }
            if ($decision === 'reject' && blank($reason)) {
                throw new DomainException('Record a reason so the requester knows what to change.');
            }
            $payload = ItTicketApprovalInput::normalize('decide', ['decision' => $decision, 'reason' => $reason]);
            $reason = $payload['reason'];

            $status = $decision === 'approve' ? 'approved' : 'rejected';
            $decidedAt = now();
            $approval->forceFill([
                'status' => $status,
                'approver_id' => $actor->id,
                'decision_reason' => $reason,
                'decision_reason_recorded_at' => $decidedAt,
                'decided_at' => $decidedAt,
                'decision_authority' => $authority,
            ])->save();
            $ticket->forceFill(['lock_version' => (int) $ticket->lock_version + 1])->save();
            $recipient = User::query()->find($approval->requested_by);
            if ($recipient && $recipient->canDo('it.manage') && $this->workAccess->canWork($recipient, $ticket)) {
                $this->deliveries->prepare($recipient, new TicketApprovalNotification($ticket, $status, (int) $approval->id));
            }

            ItTicketEvent::record($ticket, 'approval_'.$status, $actor->id, [
                'approval_id' => $approval->id,
                'decision_authority' => $authority,
            ]);

            $auditAction = $status === 'approved'
                ? 'it.ticket.approval.approved'
                : 'it.ticket.approval.rejected';
            AuditLogger::logOrFail($auditAction, $ticket, [
                'actor_id' => $actor->id,
                'approval_id' => $approval->id,
                'decision_authority' => $authority,
                'application_scope' => 'single_application',
            ]);

            return $approval->load('ticket');
        });
    }

    public function withdraw(ItTicketApproval $approval, User $actor, string $reason): ItTicketApproval
    {
        return DB::transaction(function () use ($approval, $actor, $reason): ItTicketApproval {
            [$ticket, $actor] = $this->lockContext($approval->ticket()->firstOrFail(), $actor);
            $this->requireEvidenceStorage();
            $approval = $ticket->approvals()->lockForUpdate()->findOrFail($approval->id);
            if ($approval->status !== 'pending' || $approval->isPastDeadline()) {
                throw new DomainException('Only a current pending approval can be cancelled. Its previous outcome is preserved.');
            }
            if ((int) $approval->requested_by !== (int) $actor->id
                && $this->responsibility->decisionBasis($approval, $ticket, $actor) === null) {
                throw new AuthorizationException('Only the requester or currently responsible approver may cancel this request.');
            }
            $reason = ItTicketApprovalInput::normalize('withdraw', ['reason' => $reason])['reason'];
            $approval->forceFill(['status' => 'cancelled', 'cancelled_at' => now(),
                'cancelled_by_user_id' => $actor->id, 'cancellation_reason' => $reason])->save();
            $ticket->forceFill(['lock_version' => (int) $ticket->lock_version + 1])->save();
            $this->prepareRequesterNotification($approval, $ticket, 'cancelled');
            ItTicketEvent::record($ticket, 'approval_cancelled', $actor->id, ['approval_id' => $approval->id]);
            AuditLogger::logOrFail('it.ticket.approval.cancelled', $ticket, ['actor_id' => $actor->id,
                'approval_id' => $approval->id, 'application_scope' => 'single_application']);

            return $approval->load('ticket');
        });
    }

    /** One locked canonical timing check; prepares outbox intentions, never sends mail. */
    public function processTiming(int $approvalId): string
    {
        return DB::transaction(function () use ($approvalId): string {
            $this->requireEvidenceStorage();
            $reference = ItTicketApproval::query()->find($approvalId);
            if (! $reference) {
                return 'skipped';
            }
            $ticket = ItTicket::query()->lockForUpdate()->findOrFail($reference->it_ticket_id);
            $approval = $ticket->approvals()->lockForUpdate()->findOrFail($approvalId);
            if ($approval->status !== 'pending') {
                return 'skipped';
            }
            if ($approval->isPastDeadline()) {
                $this->recordExpiry($approval, $ticket);
                $ticket->forceFill(['lock_version' => (int) $ticket->lock_version + 1])->save();

                return 'expired';
            }
            if ($ticket->isMerged() || ! in_array($ticket->status, ItTicket::OPEN_STATUSES, true)
                || ! $approval->remind_at || $approval->remind_at->isFuture() || $approval->reminder_prepared_at !== null) {
                return 'skipped';
            }
            $owner = $this->responsibility->effectiveOwner($approval, $ticket);
            $approval->forceFill(['reminder_last_checked_at' => now()]);
            if (! $owner['user']) {
                $approval->save();

                return 'deferred';
            }
            $this->deliveries->prepare($owner['user'], new TicketApprovalNotification($ticket, 'reminder', (int) $approval->id));
            $approval->forceFill(['reminder_prepared_at' => now()])->save();
            $ticket->forceFill(['lock_version' => (int) $ticket->lock_version + 1])->save();
            ItTicketEvent::record($ticket, 'approval_reminder_prepared', null, ['approval_id' => $approval->id,
                'recipient_user_id' => $owner['user']->id, 'responsibility_basis' => $owner['basis']]);
            AuditLogger::logOrFail('it.ticket.approval.reminder.prepared', $ticket, ['actor_id' => null,
                'approval_id' => $approval->id, 'recipient_user_id' => $owner['user']->id,
                'responsibility_basis' => $owner['basis'], 'application_scope' => 'single_application'], systemActor: true);

            return 'reminder_prepared';
        });
    }

    /** Caller holds both canonical locks; its transaction advances the ticket version. */
    private function recordExpiry(ItTicketApproval $approval, ItTicket $ticket): void
    {
        $approval->forceFill(['status' => 'expired', 'expired_at' => now()])->save();
        $this->prepareRequesterNotification($approval, $ticket, 'expired');
        ItTicketEvent::record($ticket, 'approval_expired', null, ['approval_id' => $approval->id]);
        AuditLogger::logOrFail('it.ticket.approval.expired', $ticket, ['actor_id' => null,
            'approval_id' => $approval->id, 'application_scope' => 'single_application'], systemActor: true);
    }

    private function prepareRequesterNotification(ItTicketApproval $approval, ItTicket $ticket, string $event): void
    {
        $recipient = User::query()->find($approval->requested_by);
        if ($recipient && $recipient->canDo('it.manage') && $this->workAccess->canWork($recipient, $ticket)) {
            $this->deliveries->prepare($recipient, new TicketApprovalNotification($ticket, $event, (int) $approval->id));
        }
    }

    public function lockContext(ItTicket $ticket, User $actor, bool $mutation = true): array
    {
        $ticket = ItTicket::query()->lockForUpdate()->findOrFail($ticket->id);
        $actor = $this->versions->currentActor($actor);
        $this->guardActor($ticket, $actor);
        if ($mutation && ($ticket->isMerged() || ! in_array($ticket->status, ItTicket::OPEN_STATUSES, true))) {
            throw new DomainException('Reopen this ticket before changing its approvals.');
        }

        return [$ticket, $actor];
    }

    public function requireEvidenceStorage(): void
    {
        if (! $this->storageReady()) {
            throw ValidationException::withMessages(['form' => 'Approval evidence setup is incomplete. Keep your proposal and retry after setup is complete.']);
        }
    }

    public function storageReady(): bool
    {
        return Schema::hasColumns('it_ticket_approvals', ['request_reason', 'request_reason_recorded_at', 'decision_reason', 'decision_reason_recorded_at',
            'primary_approver_user_id', 'cover_approver_user_id', 'assignment_recorded_at', 'expires_at', 'remind_at',
            'reminder_prepared_at', 'reminder_last_checked_at', 'decision_authority', 'expired_at', 'cancelled_at', 'cancelled_by_user_id', 'cancellation_reason']);
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
