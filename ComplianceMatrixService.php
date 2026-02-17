<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrDriverEligibility;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Models\Shift;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;

class ComplianceMatrixService implements HrRosteringContract
{
    /**
     * Check if user can be assigned to a shift based on compliance.
     * Returns a result object or boolean/string reason.
     *
     * @param int $userId
     * @param int|null $siteId
     * @return mixed
     */
    public function checkEligibility(int $userId, ?int $siteId = null): mixed
    {
        // 1. Check Hard Stops in Compliance Matrix
        // We look for any requirement that is mandatory (hard_stop) and where the user's status is NOT compliant.
        // Note: 'exempt' status is considered compliant for rostering purposes.
        $hardStopFailures = HrStaffComplianceStatus::query()
            ->where('user_id', $userId)
            ->where(function ($q) {
                $q->where('status', 'expired')
                  ->orWhere('status', 'not_started')
                  ->orWhere('status', 'suspended');
            })
            ->whereHas('requirement', function ($q) {
                $q->where('hard_stop', true);
            })
            ->with('requirement')
            ->get();

        if ($hardStopFailures->isNotEmpty()) {
            $reasons = $hardStopFailures->map(function ($status) {
                return $status->requirement->name . ' (' . $status->status . ')';
            })->implode(', ');

            return [
                'allowed' => false,
                'reason' => 'Compliance Hard Stop: ' . $reasons,
                'failures' => $hardStopFailures
            ];
        }

        return [
            'allowed' => true,
            'warnings' => $this->getComplianceWarnings($userId)
        ];
    }

    /**
     * Get compliance warnings (soft stops) for a user.
     */
    public function getComplianceWarnings(int $userId): Collection
    {
        return HrStaffComplianceStatus::query()
            ->where('user_id', $userId)
            ->where('status', 'expiring_soon')
            ->with('requirement')
            ->get()
            ->map(function ($status) {
                $date = $status->expires_at ? $status->expires_at->format('d/m/Y') : 'N/A';
                return "Warning: {$status->requirement->name} expires on {$date}";
            });
    }

    /**
     * Check if user has valid driver eligibility.
     */
    public function canDriveClients(int $userId): bool
    {
        $eligibility = HrDriverEligibility::where('user_id', $userId)->first();

        if (!$eligibility) {
            return false;
        }

        if ($eligibility->status !== 'eligible') {
            return false;
        }

        if (!$eligibility->can_drive_clients) {
            return false;
        }

        if ($eligibility->licence_expires_at && $eligibility->licence_expires_at->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * Get approved leave blocks that would conflict with rostering.
     */
    public function getApprovedLeave(int $userId, Carbon $from, Carbon $to): Collection
    {
        return HrLeaveRequest::query()
            ->where('user_id', $userId)
            ->where('status', 'approved')
            ->where(function ($query) use ($from, $to) {
                // Check for overlap
                $query->where(function ($q) use ($from, $to) {
                    $q->where('starts_at', '<=', $to)
                      ->where('ends_at', '>=', $from);
                });
            })
            ->get();
    }

    /**
     * Get fatigue status based on recent shifts.
     */
    public function getFatigueStatus(int $userId, Carbon $date): mixed
    {
        $maxHoursPerWeek = Config::get('hr.fatigue.max_hours_per_week', 50);
        $warningThreshold = Config::get('hr.fatigue.warning_threshold_weekly', 40);

        // Calculate hours worked in the last 7 days ending on $date
        $startOfWeek = $date->copy()->subDays(6)->startOfDay();
        $endOfWeek = $date->copy()->endOfDay();

        $shifts = Shift::query()
            ->where('user_id', $userId)
            ->where('starts_at', '>=', $startOfWeek)
            ->where('starts_at', '<=', $endOfWeek)
            ->get();

        $totalHours = $shifts->sum(function ($shift) {
            if ($shift->starts_at && $shift->ends_at) {
                return $shift->starts_at->diffInHours($shift->ends_at);
            }
            return 0;
        });

        if ($totalHours >= $maxHoursPerWeek) {
            return [
                'status' => 'blocked',
                'reason' => "Fatigue: Worked {$totalHours}h in last 7 days (Limit: {$maxHoursPerWeek}h)",
                'hours' => $totalHours
            ];
        }

        if ($totalHours >= $warningThreshold) {
            return [
                'status' => 'warning',
                'reason' => "Fatigue Warning: Worked {$totalHours}h in last 7 days",
                'hours' => $totalHours
            ];
        }

        return [
            'status' => 'ok',
            'hours' => $totalHours
        ];
    }
}