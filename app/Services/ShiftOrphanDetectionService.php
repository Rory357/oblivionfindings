<?php

namespace App\Services;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\Timesheet;
use App\Services\Operations\TimesheetReconciliationService;
use Illuminate\Support\Collection;

class ShiftOrphanDetectionService
{
    public function __construct(
        protected TimesheetReconciliationService $reconciliationService,
        protected UserSiteAccessService $siteAccess,
    ) {}

    /**
     * @return Collection<int, Timesheet>
     */
    public function timesheetsWithoutValidShiftOrAttendance(): Collection
    {
        return Timesheet::query()
            ->with([
                'shift:id',
                'attendanceSession:id',
            ])
            ->get()
            ->filter(function (Timesheet $timesheet): bool {
                if ($timesheet->shift_id && ! $timesheet->shift) {
                    return true;
                }

                return ! $timesheet->shift_id && ! $timesheet->attendance_session_id;
            })
            ->values();
    }

    /**
     * @return Collection<int, HrAttendanceSession>
     */
    public function attendanceWithoutTimesheet(): Collection
    {
        return $this->reconciliationService->attendanceWithoutTimesheets();
    }

    /**
     * @return Collection<int, Shift>
     */
    public function completedShiftsWithoutTimesheets(): Collection
    {
        return $this->reconciliationService->completedShiftsWithoutTimesheets();
    }

    /**
     * @return Collection<int, ShiftHandover>
     */
    public function handoversWithoutValidShiftLinkage(): Collection
    {
        return ShiftHandover::query()
            ->with([
                'outgoingShift:id,status',
                'incomingShift:id,status',
            ])
            ->get()
            ->filter(function (ShiftHandover $handover): bool {
                if (! $handover->outgoing_shift_id || ! $handover->outgoingShift) {
                    return true;
                }

                if ($handover->incoming_shift_id && ! $handover->incomingShift) {
                    return true;
                }

                return ! $this->siteAccess->handoverHasIntrinsicIntegrity($handover);
            })
            ->values();
    }
}
