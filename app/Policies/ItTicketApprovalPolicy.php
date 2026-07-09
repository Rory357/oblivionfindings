<?php

namespace App\Policies;

use App\Models\ItTicketApproval;
use App\Models\User;

/**
 * Approval-decision authorisation (§P-S3). A manager records the verdict on a
 * pending request — never the agent who asked (separation of duties). Whether
 * a request may be RAISED lives on ItTicketPolicy@requestApproval (that action
 * is authorised against the ticket, not the approval row).
 */
class ItTicketApprovalPolicy
{
    /** Approve or reject a pending request — agent work, never your own. */
    public function decide(User $user, ItTicketApproval $approval): bool
    {
        return $user->canDo('it.manage')
            && $approval->status === 'pending'
            && (int) $approval->requested_by !== (int) $user->id;
    }
}
