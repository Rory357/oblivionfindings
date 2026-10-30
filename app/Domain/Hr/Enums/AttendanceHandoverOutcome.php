<?php

namespace App\Domain\Hr\Enums;

enum AttendanceHandoverOutcome: string
{
    case DraftSaved = 'draft_saved';
    case ExistingSubmittedOrAcknowledged = 'existing_submitted_or_acknowledged';
    case NoShift = 'no_shift';
    case NoPayload = 'no_payload';
}
