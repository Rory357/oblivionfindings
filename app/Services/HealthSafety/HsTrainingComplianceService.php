<?php

namespace App\Services\HealthSafety;

use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Models\HsTrainingRequirement;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Checks staff compliance against H&S training requirements.
 *
 * This service is a READ-ONLY consumer of HR compliance data.
 * It does NOT modify HR records, courses, enrollments, or compliance statuses.
 *
 * HR owns: training courses, enrollments, compliance status records.
 * H&S owns: which of those requirements are safety-critical and how to enforce them.
 */
class HsTrainingComplianceService
{
    /**
     * Check a user's compliance against all H&S training requirements
     * applicable to the given shift context.
     *
     * @return array{compliant: bool, failures: array, warnings: array}
     */
    public function checkForShift(User $user, Shift $shift): array
    {
        $requirements = $this->getApplicableRequirements($user, $shift);

        if ($requirements->isEmpty()) {
            return ['compliant' => true, 'failures' => [], 'warnings' => []];
        }

        $failures = [];
        $warnings = [];

        foreach ($requirements as $requirement) {
            $status = $this->checkRequirement($user, $requirement);

            if ($status === 'compliant') {
                continue;
            }

            $entry = [
                'requirement_code' => $requirement->code,
                'requirement_name' => $requirement->name,
                'enforcement_mode' => $requirement->enforcement_mode,
                'status' => $status,
                'regulatory_reference' => $requirement->regulatory_reference,
            ];

            // A status within its grace window ('expiring_soon') is treated as a
            // soft warning even for blocking requirements: the grace period exists
            // to prevent sudden hard blocks the moment training expires.
            if ($requirement->isBlocking() && $status !== 'expiring_soon') {
                $failures[] = $entry;
            } else {
                $warnings[] = $entry;
            }
        }

        return [
            'compliant' => empty($failures),
            'failures' => $failures,
            'warnings' => $warnings,
        ];
    }

    /**
     * Get all active H&S training requirements that apply to the shift context.
     */
    public function getApplicableRequirements(User $user, Shift $shift): Collection
    {
        $userRole = $user->role ?? null;
        $siteId = $shift->site_id;
        $clientId = $shift->client_id;

        return HsTrainingRequirement::active()
            ->get()
            ->filter(fn (HsTrainingRequirement $req) => $req->appliesTo($userRole, $siteId, $clientId));
    }

    /**
     * Check a single requirement for a user.
     *
     * Reads from HrStaffComplianceStatus (owned by HR).
     *
     * @return string compliant|expired|expiring_soon|not_started|unknown
     */
    private function checkRequirement(User $user, HsTrainingRequirement $requirement): string
    {
        // If we have a direct HR compliance requirement link, check that
        if ($requirement->hr_compliance_requirement_id) {
            $status = HrStaffComplianceStatus::where('user_id', $user->id)
                ->where('requirement_id', $requirement->hr_compliance_requirement_id)
                ->first();

            if (! $status) {
                return 'not_started';
            }

            // Respect grace period
            if ($status->status === 'expired' && $requirement->grace_period_days > 0) {
                if ($status->expires_at && $status->expires_at->addDays($requirement->grace_period_days)->isFuture()) {
                    return 'expiring_soon'; // Within grace period — downgrade to warning
                }
            }

            return $status->status; // compliant, expired, expiring_soon, not_started
        }

        // No HR link — cannot check. Default to compliant to avoid false blocks.
        return 'compliant';
    }
}
