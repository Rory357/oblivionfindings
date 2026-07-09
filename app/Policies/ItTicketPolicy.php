<?php

namespace App\Policies;

use App\Models\ItTicket;
use App\Models\User;

/**
 * Helpdesk ticket authorisation. Agents (it.view / it.manage) work the queue;
 * requesters (it.request) may raise tickets and interact with their OWN
 * tickets only. Ownership is the requester_user_id column — never UI hiding.
 */
class ItTicketPolicy
{
    /** Raise a ticket (self-service or agent log-and-triage). */
    public function create(User $user): bool
    {
        return $user->canDo('it.request') || $user->canDo('it.manage');
    }

    /** Agents see every ticket; requesters see their own. */
    public function view(User $user, ItTicket $ticket): bool
    {
        return $user->canDo('it.view') || $this->owns($user, $ticket);
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
        if ($user->canDo('it.manage')) {
            return true;
        }

        return $this->owns($user, $ticket)
            && $ticket->resolved_at !== null
            && $ticket->resolved_at->gt(now()->subDays(7));
    }

    /** Triage mutations (status, priority, assignee, …) are agent work. */
    public function update(User $user, ItTicket $ticket): bool
    {
        return $user->canDo('it.manage');
    }

    public function resolve(User $user, ItTicket $ticket): bool
    {
        return $user->canDo('it.manage');
    }

    public function close(User $user, ItTicket $ticket): bool
    {
        return $user->canDo('it.manage');
    }

    /**
     * Merge a duplicate SOURCE ticket into a TARGET survivor. Agent work; a
     * ticket can't merge into itself, an already-merged source can't be merged
     * again, and both ends must be live (not closed, not already merged) in the
     * same tenant.
     */
    public function merge(User $user, ItTicket $ticket, ItTicket $target): bool
    {
        return $user->canDo('it.manage')
            && $ticket->id !== $target->id
            && (int) $ticket->tenant_id === (int) $target->tenant_id
            && $ticket->merged_into_ticket_id === null
            && $target->merged_into_ticket_id === null
            && $ticket->status !== 'closed'
            && $target->status !== 'closed';
    }

    /**
     * Rate the resolution (CSAT). The requester's own satisfaction — agents
     * never rate. Allowed only while the ticket is `resolved`: nothing to rate
     * before, and a close locks it in (editable until closed, §K).
     */
    public function csat(User $user, ItTicket $ticket): bool
    {
        return $this->owns($user, $ticket) && $ticket->status === 'resolved';
    }

    /** Destructive — admins only. */
    public function delete(User $user, ItTicket $ticket): bool
    {
        return $user->canDo('it.manage') && $user->hasRole('admin');
    }

    private function owns(User $user, ItTicket $ticket): bool
    {
        return (int) $ticket->requester_user_id === (int) $user->id;
    }
}
