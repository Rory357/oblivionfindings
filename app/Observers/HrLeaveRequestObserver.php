<?php

namespace App\Observers;

use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Services\LeaveService;

/**
 * Keeps the roster-side StaffTimeOff projection faithful when an already-approved
 * leave request is edited (dates / type / period). The approval transition itself is
 * handled inside LeaveService::approveRequest, so this only fires for genuine edits.
 */
class HrLeaveRequestObserver
{
    public function __construct(private readonly LeaveService $leaveService) {}

    public function updated(HrLeaveRequest $request): void
    {
        // Only re-sync edits to a request that was ALREADY approved before this save —
        // not the pending→approved transition (which creates the projection itself).
        if ($request->getOriginal('status') !== 'approved') {
            return;
        }

        if (! $request->time_off_id) {
            return;
        }

        if (! $request->wasChanged(['starts_at', 'ends_at', 'leave_type', 'period'])) {
            return;
        }

        $this->leaveService->syncApprovedProjection($request);
    }
}
