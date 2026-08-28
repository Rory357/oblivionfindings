<?php

namespace App\Domain\Hr\Enums;

enum AttendanceTimesheetSyncOutcome: string
{
    case None = 'none';
    case Created = 'created';
    case Updated = 'updated';
    case SkippedFollowUp = 'skipped_follow_up';

    public function wasSynced(): bool
    {
        return in_array($this, [self::Created, self::Updated], true);
    }
}
