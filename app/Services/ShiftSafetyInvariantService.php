<?php

namespace App\Services;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\Site;
use App\Models\Timesheet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class ShiftSafetyInvariantService
{
    public function assertShift(Shift $shift): void
    {
        if ($shift->status === 'completed' && ! $this->shiftHasStartEvidence($shift)) {
            throw ValidationException::withMessages([
                'shift' => 'Completed shifts require actual start or attendance evidence.',
            ]);
        }

        if ($shift->status === 'cancelled' && ($shift->actual_ends_at || $shift->completed_by)) {
            throw ValidationException::withMessages([
                'shift' => 'A completed shift cannot also be marked as cancelled.',
            ]);
        }
    }

    public function assertTimesheet(Timesheet $timesheet): void
    {
        if ($timesheet->starts_at && $timesheet->ends_at && (int) $timesheet->total_minutes <= 0) {
            throw ValidationException::withMessages([
                'timesheet' => 'Timesheets must have a positive payable duration.',
            ]);
        }

        if (! $timesheet->shift_id) {
            return;
        }

        $timesheet->loadMissing('shift.client:id,site_id');

        if (! $timesheet->shift) {
            throw ValidationException::withMessages([
                'timesheet' => 'This timesheet is linked to a shift that no longer exists.',
            ]);
        }

        if ($timesheet->shift->user_id && (int) $timesheet->shift->user_id !== (int) $timesheet->user_id) {
            throw ValidationException::withMessages([
                'timesheet' => 'This timesheet no longer matches the linked shift staff assignment.',
            ]);
        }

        if ($timesheet->shift->client_id && (int) $timesheet->shift->client_id !== (int) $timesheet->client_id) {
            throw ValidationException::withMessages([
                'timesheet' => 'This timesheet no longer matches the linked shift client.',
            ]);
        }

        $shiftSiteId = $timesheet->shift->site_id ?: $timesheet->shift->client?->site_id;
        if ($shiftSiteId && $timesheet->shift_site_id && (int) $timesheet->shift_site_id !== (int) $shiftSiteId) {
            throw ValidationException::withMessages([
                'timesheet' => 'This timesheet no longer matches the linked shift site.',
            ]);
        }

        if ($timesheet->status === 'approved' && $timesheet->reconciliation_status === 'blocked') {
            throw ValidationException::withMessages([
                'timesheet' => 'Approved timesheets must pass reconciliation checks.',
            ]);
        }
    }

    public function assertHandover(ShiftHandover $handover): void
    {
        // A caller may change one of the foreign keys on a model whose old
        // relation was already eager loaded. Always validate the current keys,
        // never a stale in-memory relation snapshot.
        foreach (['client', 'outgoingStaff', 'incomingStaff', 'outgoingShift', 'incomingShift'] as $relation) {
            $handover->unsetRelation($relation);
        }

        $handover->loadMissing([
            'client:id,site_id',
            'outgoingStaff:id',
            'incomingStaff:id',
            'outgoingShift:id,status,user_id,client_id,site_id',
            'outgoingShift.client:id,site_id',
            'incomingShift:id,status,user_id,client_id,site_id',
            'incomingShift.client:id,site_id',
        ]);

        if (! $handover->outgoing_shift_id || ! $handover->outgoingShift) {
            throw ValidationException::withMessages([
                'handover' => 'This handover is no longer linked to a valid outgoing shift.',
            ]);
        }

        if (! $handover->client_id || ! $handover->client || ! $handover->client->site_id) {
            throw ValidationException::withMessages([
                'handover' => 'Shift handovers require a Client with one canonical Site.',
            ]);
        }

        if (! Site::query()->whereKey($handover->client->site_id)->exists()) {
            throw ValidationException::withMessages([
                'handover' => 'The handover Client Site is no longer available.',
            ]);
        }

        if (! $handover->outgoing_staff_id || ! $handover->outgoingStaff) {
            throw ValidationException::withMessages([
                'handover' => 'Shift handovers require a valid outgoing staff member.',
            ]);
        }

        $outgoingSiteId = $this->handoverShiftSiteId($handover->outgoingShift, 'outgoing');
        if (
            (int) $handover->outgoingShift->client_id !== (int) $handover->client_id
            || (int) $handover->outgoingShift->user_id !== (int) $handover->outgoing_staff_id
            || $outgoingSiteId !== (int) $handover->client->site_id
        ) {
            throw ValidationException::withMessages([
                'handover' => 'The outgoing Shift, staff member, Client, and Site do not match.',
            ]);
        }

        if ($handover->incoming_shift_id && ! $handover->incomingShift) {
            throw ValidationException::withMessages([
                'handover' => 'This handover is no longer linked to a valid incoming shift.',
            ]);
        }

        if ($handover->incoming_shift_id) {
            $incomingSiteId = $this->handoverShiftSiteId($handover->incomingShift, 'incoming');
            if (
                (int) $handover->incomingShift->client_id !== (int) $handover->client_id
                || $incomingSiteId !== $outgoingSiteId
                || ! $handover->incomingShift->user_id
                || ! $handover->incoming_staff_id
                || ! $handover->incomingStaff
            ) {
                throw ValidationException::withMessages([
                    'handover' => 'The incoming Shift, staff member, Client, and Site do not match the outgoing handover.',
                ]);
            }
        } elseif ($handover->incoming_staff_id && ! $handover->incomingStaff) {
            throw ValidationException::withMessages([
                'handover' => 'The selected incoming staff member is no longer available.',
            ]);
        }

        if (
            in_array((string) $handover->status, [
                ShiftHandoverService::STATUS_DRAFT,
                ShiftHandoverService::STATUS_SUBMITTED,
                ShiftHandoverService::STATUS_ACKNOWLEDGED,
            ], true)
            && (
                $handover->outgoingShift?->status === 'cancelled'
                || $handover->incomingShift?->status === 'cancelled'
            )
        ) {
            throw ValidationException::withMessages([
                'handover' => 'Handovers linked to cancelled shifts require review and cannot remain active.',
            ]);
        }

        if ($handover->status !== ShiftHandoverService::STATUS_ACKNOWLEDGED) {
            return;
        }

        if (! $handover->incoming_shift_id) {
            throw ValidationException::withMessages([
                'handover' => 'Acknowledged handovers must belong to an exact incoming shift.',
            ]);
        }

        $incomingUserId = $handover->incomingShift?->user_id;
        if (! $incomingUserId) {
            throw ValidationException::withMessages([
                'handover' => 'Acknowledged handovers must belong to an assigned incoming shift.',
            ]);
        }

        // incoming_staff_id is immutable submit-time provenance. Authority to
        // acknowledge follows the currently assigned incoming Shift worker and
        // is captured independently in acknowledged_by.
        if ((int) $handover->acknowledged_by !== (int) $incomingUserId) {
            throw ValidationException::withMessages([
                'handover' => 'Acknowledged handovers must be accepted by the current incoming shift assignee.',
            ]);
        }
    }

    private function handoverShiftSiteId(Shift $shift, string $direction): int
    {
        if (! $shift->client_id || ! $shift->client || ! $shift->client->site_id) {
            throw ValidationException::withMessages([
                'handover' => "The {$direction} Shift has no valid Client Site.",
            ]);
        }

        if ($shift->site_id && (int) $shift->site_id !== (int) $shift->client->site_id) {
            throw ValidationException::withMessages([
                'handover' => "The {$direction} Shift Site does not match its Client Site.",
            ]);
        }

        return (int) ($shift->site_id ?: $shift->client->site_id);
    }

    public function assertAttendanceSession(HrAttendanceSession $session): void
    {
        if (! $session->clock_in_at) {
            throw ValidationException::withMessages([
                'attendance' => 'Attendance sessions require a clock-in time.',
            ]);
        }

        if ($session->clock_out_at && $session->clock_out_at->lessThanOrEqualTo($session->clock_in_at)) {
            throw ValidationException::withMessages([
                'attendance' => 'Closed attendance sessions must end after they start.',
            ]);
        }

        if ($session->status === 'closed' && ! $session->clock_out_at) {
            throw ValidationException::withMessages([
                'attendance' => 'Closed attendance sessions must include both clock-in and clock-out times.',
            ]);
        }

        if ($session->clock_in_at && $session->clock_out_at && (int) $session->break_minutes > 0) {
            $elapsedMinutes = (int) $session->clock_in_at->diffInMinutes($session->clock_out_at);

            if ((int) $session->break_minutes >= $elapsedMinutes) {
                throw ValidationException::withMessages([
                    'break_minutes' => sprintf(
                        'Break duration (%d min) must be less than the session duration (%d min).',
                        (int) $session->break_minutes,
                        $elapsedMinutes,
                    ),
                ]);
            }
        }

        if ($this->sessionOverlapsAnother($session)) {
            throw ValidationException::withMessages([
                'attendance' => 'Attendance sessions for the same staff member cannot overlap.',
            ]);
        }
    }

    protected function shiftHasStartEvidence(Shift $shift): bool
    {
        if ($shift->actual_starts_at) {
            return true;
        }

        if ($shift->relationLoaded('attendanceSessions')) {
            return $shift->attendanceSessions->contains(fn (HrAttendanceSession $session) => $session->clock_in_at !== null);
        }

        return $shift->attendanceSessions()
            ->whereNotNull('clock_in_at')
            ->exists();
    }

    protected function sessionOverlapsAnother(HrAttendanceSession $session): bool
    {
        if (! $session->user_id || ! $session->clock_in_at) {
            return false;
        }

        $candidateEnd = $session->clock_out_at ?? now()->addYears(10);

        return HrAttendanceSession::query()
            ->where('user_id', $session->user_id)
            ->when($session->exists, fn (Builder $query) => $query->whereKeyNot($session->getKey()))
            ->whereNotNull('clock_in_at')
            ->where('clock_in_at', '<', $candidateEnd)
            ->where(function (Builder $query) use ($session) {
                $query->whereNull('clock_out_at')
                    ->orWhere('clock_out_at', '>', $session->clock_in_at);
            })
            ->exists();
    }
}
