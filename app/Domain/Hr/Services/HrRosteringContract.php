<?php

namespace App\Domain\Hr\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;

interface HrRosteringContract
{
    /**
     * Check whether an employee is eligible to be rostered.
     *
     * Evaluates compliance status, leave, and fatigue rules to determine
     * whether the employee can be assigned shifts. Returns an array with
     * 'eligible' boolean and any 'reasons' for ineligibility.
     *
     * @param  int       $userId
     * @param  int|null  $siteId  Optional site to check site-specific requirements
     * @return array{eligible: bool, reasons: array<string>}
     */
    public function checkEligibility(int $userId, ?int $siteId = null): array;

    /**
     * Get all active compliance warnings for an employee.
     *
     * Returns a collection of warning arrays with requirement details,
     * expiry dates, and severity levels. Used by the roster UI to display
     * warning badges on staff members.
     *
     * @param  int  $userId
     * @return Collection<int, array{requirement: string, code: string, status: string, expires_at: string|null, severity: string}>
     */
    public function getComplianceWarnings(int $userId): Collection;

    /**
     * Determine whether an employee is approved to drive clients.
     *
     * Checks the HrDriverEligibility record for valid licence, approval
     * status, and any active suspensions.
     *
     * @param  int  $userId
     * @return bool
     */
    public function canDriveClients(int $userId): bool;

    /**
     * Get approved leave periods for an employee within a date range.
     *
     * Returns a collection of leave periods so the rostering system can
     * exclude these dates when generating or validating rosters.
     *
     * @param  int     $userId
     * @param  Carbon  $from
     * @param  Carbon  $to
     * @return Collection<int, array{leave_type: string, starts_at: string, ends_at: string, hours: float}>
     */
    public function getApprovedLeave(int $userId, Carbon $from, Carbon $to): Collection;

    /**
     * Get the fatigue/wellbeing status for an employee on a given date.
     *
     * Returns metrics relevant to safe rostering: consecutive days worked,
     * hours in the last 7 days, and the current flag level. Used by the
     * roster engine to enforce rest-day and maximum-hours rules.
     *
     * @param  int     $userId
     * @param  Carbon  $date
     * @return array{consecutive_days: int, hours_last_7d: float, flag_level: string, can_roster: bool}
     */
    public function getFatigueStatus(int $userId, Carbon $date): array;
}
