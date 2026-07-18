<?php

namespace App\Domain\It\Enums;

enum ItWorkflowState: string
{
    case Submitted = 'submitted';
    case Triaged = 'triaged';
    case InProgress = 'in_progress';
    case Waiting = 'waiting';
    case Resolved = 'resolved';
    case Closed = 'closed';
    case Fulfilling = 'fulfilling';
    case Fulfilled = 'fulfilled';
    case ApprovalPending = 'approval_pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Investigating = 'investigating';
    case KnownError = 'known_error';
    case Draft = 'draft';
    case Assessment = 'assessment';
    case Scheduled = 'scheduled';
    case Implementing = 'implementing';
    case Validation = 'validation';
    case Completed = 'completed';
    case Failed = 'failed';
    case BackedOut = 'backed_out';
    case Cancelled = 'cancelled';
    case Declared = 'declared';
    case Responding = 'responding';
    case Monitoring = 'monitoring';
    case Restored = 'restored';
    case Review = 'review';
}
