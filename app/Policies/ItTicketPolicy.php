<?php

namespace App\Policies;

use App\Domain\It\Services\ItTicketApprovalService;
use App\Domain\It\Services\ItTicketMergeService;
use App\Domain\It\Services\ItWorkAccessService;
use App\Models\ItTicket;
use App\Models\User;

/**
 * Helpdesk ticket authorisation. Agents (it.view / it.manage) work the queue;
 * requesters (it.request) may raise tickets and interact with their OWN
 * tickets only. Ownership is the requester_user_id column — never UI hiding.
 */
class ItTicketPolicy
{
    public function __construct(
        private readonly ItWorkAccessService $access,
        private readonly ItTicketMergeService $mergeService,
    ) {}

    /** Raise a ticket (self-service or agent log-and-triage). */
    public function create(User $user): bool
    {
        return $user->canDo('it.request') || $user->canDo('it.manage');
    }

    /** Participants and explicitly scoped staff may view a ticket. */
    public function view(User $user, ItTicket $ticket): bool
    {
        return $this->access->canView($user, $ticket);
    }

    /**
     * Reply on the thread. Same audience as view; posting an INTERNAL note
     * additionally requires it.manage — enforced at the request layer where
     * the is_internal flag is validated.
     */
    public function comment(User $user, ItTicket $ticket): bool
    {
        return $this->view($user, $ticket);
    }

    /** Agents reopen anytime; requesters within 7 days of resolution. */
    public function reopen(User $user, ItTicket $ticket): bool
    {
        if ($ticket->isMerged()) {
            return false;
        }

        if ($user->canDo('it.manage') && $this->access->canWork($user, $ticket)) {
            return true;
        }

        return $this->owns($user, $ticket)
            && $this->access->canView($user, $ticket)
            && $ticket->resolved_at !== null
            && $ticket->resolved_at->gt(now()->subDays(7));
    }

    /** Triage mutations (status, priority, assignee, …) are agent work. */
    public function update(User $user, ItTicket $ticket): bool
    {
        return $this->access->canWork($user, $ticket);
    }

    public function resolve(User $user, ItTicket $ticket): bool
    {
        return $this->access->canWork($user, $ticket);
    }

    public function close(User $user, ItTicket $ticket): bool
    {
        return $this->access->canWork($user, $ticket);
    }

    /** Confirmation is the requester's statement, including when they also work for IT. */
    public function confirmResolution(User $user, ItTicket $ticket): bool
    {
        return $this->owns($user, $ticket)
            && $this->access->canView($user, $ticket)
            && ! $ticket->isMerged()
            && $ticket->status === 'resolved';
    }

    /**
     * Merge a duplicate SOURCE ticket into a TARGET survivor. Agent work; a
     * ticket can't merge into itself, an already-merged source can't be merged
     * again, both ends must be live (not closed, not already merged), and both
     * ends must pass the same canonical work boundary.
     */
    public function merge(User $user, ItTicket $ticket, ItTicket $target): bool
    {
        return $this->access->canWork($user, $ticket)
            && $this->access->canWork($user, $target)
            && $ticket->id !== $target->id
            && $ticket->merged_into_ticket_id === null
            && $target->merged_into_ticket_id === null
            && $ticket->status !== 'closed'
            && $target->status !== 'closed'
            && $this->mergeService->sharesConversationAudience($ticket, $target)
            && $this->mergeService->sharesStaffAccessScope($ticket, $target);
    }

    /**
     * Ask for a manager's sign-off (§P-S3). Agent work, on a ticket whose
     * category needs approval, and only when no request is already live.
     */
    public function requestApproval(User $user, ItTicket $ticket): bool
    {
        return $this->access->canWork($user, $ticket)
            && $user->canDo('it.manage')
            && ! $ticket->isMerged()
            && in_array($ticket->status, ItTicket::OPEN_STATUSES, true)
            && $ticket->requires_approval
            && app(ItTicketApprovalService::class)->storageReady()
            && ! $ticket->approvals()->where(fn ($query) => $query->where('status', 'approved')
                ->orWhere(fn ($pending) => $pending->where('status', 'pending')
                    ->where(fn ($deadline) => $deadline->whereNull('expires_at')->orWhere('expires_at', '>', now()))))->exists();
    }

    /**
     * Rate the resolution (CSAT). The requester's own satisfaction — agents
     * never rate. Allowed only while the ticket is `resolved`: nothing to rate
     * before, and a close locks it in (editable until closed, §K).
     */
    public function csat(User $user, ItTicket $ticket): bool
    {
        return $this->owns($user, $ticket) && $ticket->status === 'resolved'
            && ! $ticket->isMerged() && $this->access->canView($user, $ticket);
    }

    /** Destructive — admins only. */
    public function delete(User $user, ItTicket $ticket): bool
    {
        return $user->hasRole('admin') && $this->access->canWork($user, $ticket);
    }

    private function owns(User $user, ItTicket $ticket): bool
    {
        return (int) $ticket->requester_user_id === (int) $user->id;
    }
}
