<?php

namespace App\Domain\Hr\Services;

use Illuminate\Support\Collection;
use Carbon\Carbon;

interface HrRosteringContract
{
    /**
     * Check if user can be assigned to a shift based on compliance.
     * Returns a result object or boolean/string reason.
     *
     * @param int $userId
     * @param int|null $siteId
     * @return mixed
     */
    public function checkEligibility(int $userId, ?int $siteId = null): mixed;

    /**
     * Get compliance warnings (soft stops) for a user.
     */
    public function getComplianceWarnings(int $userId): Collection;

    /**
     * Check if user has valid driver eligibility.
     */
    public function canDriveClients(int $userId): bool;

    /**
     * Get approved leave blocks that would conflict with rostering.
     */
    public function getApprovedLeave(int $userId, Carbon $from, Carbon $to): Collection;

    /**
     * Get fatigue status based on recent shifts.
     */
    public function getFatigueStatus(int $userId, Carbon $date): mixed;
}