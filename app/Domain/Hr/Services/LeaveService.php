<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveBalance;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrPublicHoliday;
use App\Models\StaffTimeOff;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LeaveService
{
    /**
     * Leave types supported by the system.
     */
    public const LEAVE_TYPES = [
        'annual',
        'sick',
        'bereavement',
        'parental',
        'public_holiday',
        'unpaid',
        'toil',
        'other',
    ];

    /**
     * Submit a leave request with balance validation.
     *
     * Checks the employee's HrLeaveBalance for the requested leave type and year.
     * If insufficient balance, the request is still created but flagged for
     * escalation. Pending hours are incremented on the balance record.
     *
     * @param  User   $user
     * @param  array  $data  Request attributes: leave_type, starts_at, ends_at, hours_requested, reason, supporting_doc_path
     * @return HrLeaveRequest
     *
     * @throws \InvalidArgumentException If leave_type is invalid or dates are malformed
     */
    public function submitRequest(User $user, array $data): HrLeaveRequest
    {
        // TODO: Validate leave_type is in LEAVE_TYPES
        // TODO: Validate starts_at <= ends_at
        // TODO: Calculate hours_requested if not explicitly provided (based on work schedule)
        // TODO: Check for overlapping approved/pending leave requests
        // TODO: Look up HrLeaveBalance for user + leave_type + year
        // TODO: If balance_hours - used_hours - pending_hours < hours_requested, flag for escalation
        // TODO: Increment pending_hours on the balance record
        // TODO: Create HrLeaveRequest with status 'pending'
        // TODO: Fire LeaveRequestSubmitted event (notifies manager)
        // TODO: Log audit trail entry

        $request = DB::transaction(function () use ($user, $data) {
            $leaveType = $data['leave_type'];
            $hoursRequested = (float) $data['hours_requested'];
            $year = now()->year;

            $balance = $this->calculateBalance($user->id, $leaveType, $year);
            $needsEscalation = $balance['available'] < $hoursRequested;

            $request = HrLeaveRequest::create([
                'tenant_id' => $user->tenant_id,
                'user_id' => $user->id,
                'leave_type' => $leaveType,
                'period' => $data['period'] ?? 'full_day',
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'],
                'hours_requested' => $hoursRequested,
                'reason' => $data['reason'] ?? null,
                'supporting_doc_path' => $data['supporting_doc_path'] ?? null,
                'status' => 'pending',
                'submitted_at' => now(),
                'escalated_to' => $needsEscalation ? $this->getEscalationTarget($user) : null,
                'created_by' => $user->id,
            ]);

            HrLeaveBalance::where('user_id', $user->id)
                ->where('leave_type', $leaveType)
                ->where('year', $year)
                ->increment('pending_hours', $hoursRequested);

            return $request;
        });

        app(HrNotificationService::class)->notifyLeaveRequest($request);

        return $request;
    }

    /**
     * Approve a pending leave request.
     *
     * Converts pending hours to used hours on the balance, creates a
     * StaffTimeOff record for roster integration, and notifies the employee.
     *
     * @param  HrLeaveRequest  $request
     * @param  User            $reviewer
     * @param  string|null     $reviewNotes
     * @return HrLeaveRequest
     *
     * @throws \LogicException If request is not in 'pending' status
     */
    public function approveRequest(HrLeaveRequest $request, User $reviewer, ?string $reviewNotes = null): HrLeaveRequest
    {
        // TODO: Verify request is in 'pending' status
        // TODO: Update request status to 'approved', set reviewed_by and reviewed_at
        // TODO: Decrement pending_hours and increment used_hours on the HrLeaveBalance
        // TODO: Create a StaffTimeOff record so the rostering system excludes these dates
        // TODO: Fire LeaveRequestApproved event (notifies employee, updates calendar)
        // TODO: Log audit trail entry

        if ($request->status !== 'pending') {
            throw new \LogicException("Cannot approve a '{$request->status}' leave request.");
        }

        $result = DB::transaction(function () use ($request, $reviewer, $reviewNotes) {
            $timeOff = StaffTimeOff::create([
                'tenant_id' => $request->tenant_id,
                'user_id' => $request->user_id,
                'type' => $request->leave_type,
                'starts_at' => $request->starts_at,
                'ends_at' => $request->ends_at,
                'hours' => $request->hours_requested,
                'approved_by' => $reviewer->id,
                'notes' => $reviewNotes,
            ]);

            $request->update([
                'status' => 'approved',
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_notes' => $reviewNotes,
                'time_off_id' => $timeOff->id,
            ]);

            $year = $request->starts_at->year;
            HrLeaveBalance::where('user_id', $request->user_id)
                ->where('leave_type', $request->leave_type)
                ->where('year', $year)
                ->update([
                    'pending_hours' => DB::raw("GREATEST(pending_hours - {$request->hours_requested}, 0)"),
                    'used_hours' => DB::raw("used_hours + {$request->hours_requested}"),
                ]);

            return $request->fresh();
        });

        app(HrNotificationService::class)->notifyLeaveApproved($result);

        return $result;
    }

    /**
     * Decline a pending leave request with notification.
     *
     * Releases pending hours back to available balance and notifies
     * the employee with the decline reason.
     *
     * @param  HrLeaveRequest  $request
     * @param  User            $reviewer
     * @param  string          $reason
     * @return HrLeaveRequest
     *
     * @throws \LogicException If request is not in 'pending' status
     */
    public function declineRequest(HrLeaveRequest $request, User $reviewer, string $reason): HrLeaveRequest
    {
        // TODO: Verify request is in 'pending' status
        // TODO: Update request status to 'declined'
        // TODO: Decrement pending_hours on the HrLeaveBalance (release reserved hours)
        // TODO: Fire LeaveRequestDeclined event (notifies employee with reason)
        // TODO: Log audit trail entry

        if ($request->status !== 'pending') {
            throw new \LogicException("Cannot decline a '{$request->status}' leave request.");
        }

        $result = DB::transaction(function () use ($request, $reviewer, $reason) {
            $request->update([
                'status' => 'declined',
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_notes' => $reason,
            ]);

            $year = $request->starts_at->year;
            HrLeaveBalance::where('user_id', $request->user_id)
                ->where('leave_type', $request->leave_type)
                ->where('year', $year)
                ->update([
                    'pending_hours' => DB::raw("GREATEST(pending_hours - {$request->hours_requested}, 0)"),
                ]);

            return $request->fresh();
        });

        app(HrNotificationService::class)->notifyLeaveDeclined($result);

        return $result;
    }

    /**
     * Calculate the current leave balance for a user, type, and year.
     *
     * Returns accrued, used, pending, and available hours. If no balance record
     * exists, returns zeroes.
     *
     * @param  int     $userId
     * @param  string  $leaveType
     * @param  int     $year
     * @return array{accrued: float, used: float, pending: float, available: float}
     */
    public function calculateBalance(int $userId, string $leaveType, int $year): array
    {
        // TODO: Look up the HrLeaveBalance record for user + type + year
        // TODO: If no record exists, check if the leave type accrues automatically
        //       and calculate based on employment start date and hours_per_week
        // TODO: For 'sick' leave, check the Holidays Act 2003 minimum (NZ context)
        // TODO: For 'annual' leave, check minimum entitlement (4 weeks per year)
        // TODO: Factor in any carry-over from previous year if applicable
        // TODO: Return breakdown of accrued, used, pending, and available

        $balance = HrLeaveBalance::where('user_id', $userId)
            ->where('leave_type', $leaveType)
            ->where('year', $year)
            ->first();

        if (! $balance) {
            return [
                'accrued' => 0,
                'used' => 0,
                'pending' => 0,
                'available' => 0,
            ];
        }

        $available = (float) $balance->balance_hours
            - (float) $balance->used_hours
            - (float) $balance->pending_hours;

        return [
            'accrued' => (float) $balance->accrued_hours,
            'used' => (float) $balance->used_hours,
            'pending' => (float) $balance->pending_hours,
            'available' => max(0, $available),
        ];
    }

    /**
     * Cancel a pending or approved leave request.
     *
     * @param  HrLeaveRequest  $request
     * @param  int             $cancelledBy  User ID
     * @return HrLeaveRequest
     *
     * @throws \LogicException If request is already cancelled or declined
     */
    public function cancelRequest(HrLeaveRequest $request, int $cancelledBy): HrLeaveRequest
    {
        // TODO: Verify request is in 'pending' or 'approved' status
        // TODO: If approved, delete the associated StaffTimeOff record
        // TODO: Adjust balance: decrement pending_hours (if pending) or used_hours (if approved)
        // TODO: Update request status to 'cancelled'
        // TODO: Fire LeaveRequestCancelled event
        // TODO: Log audit trail entry

        if (in_array($request->status, ['cancelled', 'declined'], true)) {
            throw new \LogicException("Cannot cancel a '{$request->status}' leave request.");
        }

        return DB::transaction(function () use ($request, $cancelledBy) {
            $year = $request->starts_at->year;
            $hours = $request->hours_requested;

            if ($request->status === 'approved' && $request->time_off_id) {
                StaffTimeOff::where('id', $request->time_off_id)->delete();
                HrLeaveBalance::where('user_id', $request->user_id)
                    ->where('leave_type', $request->leave_type)
                    ->where('year', $year)
                    ->update([
                        'used_hours' => DB::raw("GREATEST(used_hours - {$hours}, 0)"),
                    ]);
            } elseif ($request->status === 'pending') {
                HrLeaveBalance::where('user_id', $request->user_id)
                    ->where('leave_type', $request->leave_type)
                    ->where('year', $year)
                    ->update([
                        'pending_hours' => DB::raw("GREATEST(pending_hours - {$hours}, 0)"),
                    ]);
            }

            $request->update([
                'status' => 'cancelled',
                'reviewed_by' => $cancelledBy,
                'reviewed_at' => now(),
                'review_notes' => 'Cancelled by user.',
            ]);

            return $request->fresh();
        });
    }

    /**
     * Determine the escalation target for a leave request that exceeds balance.
     *
     * @param  User  $user
     * @return int|null  User ID of the escalation target, or null
     */
    protected function getEscalationTarget(User $user): ?int
    {
        // TODO: Look up the user's direct manager or HR administrator
        // TODO: Fall back to tenant-level HR manager if no direct manager
        // TODO: Return null if no escalation target can be determined

        return null;
    }

    /* ------------------------------------------------------------------ */
    /*  Leave Auto-Accrual (NZ Holidays Act 2003)                          */
    /* ------------------------------------------------------------------ */

    /**
     * Calculate annual leave accrual for an employee based on NZ Holidays Act 2003.
     * Minimum entitlement: 4 weeks (160 hours for full-time) after 12 months continuous employment.
     * Accrual is proportional for part-time based on hours_per_week.
     */
    public function calculateAnnualAccrual(HrEmployeeProfile $profile): float
    {
        $startDate = $profile->start_date;

        if (! $startDate) {
            return 0;
        }

        $monthsEmployed = $startDate->diffInMonths(now());
        $minMonths = config('hr.leave_policies.annual.min_months_for_entitlement', 12);

        if ($monthsEmployed < $minMonths) {
            // Not yet entitled — calculate 8% accrual for pay-as-you-go (casuals)
            return 0;
        }

        $weeklyHours = $profile->hours_per_week ?? 40;
        $entitlementWeeks = config('hr.leave_policies.annual.entitlement_weeks', 4);
        $annualEntitlementHours = $weeklyHours * $entitlementWeeks;

        return (float) $annualEntitlementHours;
    }

    /**
     * Calculate sick leave entitlement based on NZ Holidays Act.
     * After 6 months: 10 days per year. Max carry-over: 20 days unused.
     */
    public function calculateSickLeaveEntitlement(HrEmployeeProfile $profile): float
    {
        $startDate = $profile->start_date;

        if (! $startDate) {
            return 0;
        }

        $monthsEmployed = $startDate->diffInMonths(now());
        $minMonths = config('hr.leave_policies.sick.min_months_for_entitlement', 6);

        if ($monthsEmployed < $minMonths) {
            return 0;
        }

        $daysPerYear = config('hr.leave_policies.sick.days_per_year', 10);
        $dailyHours = ($profile->hours_per_week ?? 40) / 5;

        return (float) ($daysPerYear * $dailyHours);
    }

    /**
     * Run accrual for all employees — called by ProcessLeaveBalanceAccrualJob.
     */
    public function processAccruals(?int $tenantId): int
    {
        $processed = 0;
        $year = now()->year;

        HrEmployeeProfile::forTenant($tenantId)->active()->chunk(100, function ($profiles) use ($year, &$processed) {
            foreach ($profiles as $profile) {
                $annualHours = $this->calculateAnnualAccrual($profile);
                $sickHours = $this->calculateSickLeaveEntitlement($profile);

                // Upsert annual leave balance
                if ($annualHours > 0) {
                    HrLeaveBalance::updateOrCreate(
                        ['user_id' => $profile->user_id, 'leave_type' => 'annual', 'year' => $year],
                        ['tenant_id' => $profile->tenant_id, 'accrued_hours' => $annualHours, 'balance_hours' => $annualHours]
                    );
                }

                // Upsert sick leave balance
                if ($sickHours > 0) {
                    HrLeaveBalance::updateOrCreate(
                        ['user_id' => $profile->user_id, 'leave_type' => 'sick', 'year' => $year],
                        ['tenant_id' => $profile->tenant_id, 'accrued_hours' => $sickHours, 'balance_hours' => $sickHours]
                    );
                }

                $processed++;
            }
        });

        return $processed;
    }

    /**
     * Check if a date is a public holiday.
     */
    public function isPublicHoliday(string $date, ?string $region = null): bool
    {
        return HrPublicHoliday::where('date', $date)
            ->where(fn ($q) => $q->where('is_national', true)->when($region, fn ($q2) => $q2->orWhere('region', $region)))
            ->exists();
    }
}
