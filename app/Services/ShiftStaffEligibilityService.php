<?php

namespace App\Services;

use App\Domain\Hr\Services\ComplianceMatrixService;
use App\Models\Shift;
use App\Models\StaffTimeOff;
use App\Models\User;

class ShiftStaffEligibilityService
{
    public function __construct(
        protected ComplianceMatrixService $complianceMatrix,
        protected ShiftConflictService $conflicts,
        protected CoverageRoleService $coverageRoles,
        protected ShiftCoverageService $coverage,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function evaluate(Shift $shift, User $user): array
    {
        $blockingConflicts = $this->conflicts->findBlockingStaffConflicts(
            $user->id,
            $shift->starts_at ?? now(),
            $shift->ends_at ?? now(),
            $shift->id,
        );

        $timeOff = StaffTimeOff::query()
            ->where('user_id', $user->id)
            ->where('starts_at', '<', $shift->ends_at)
            ->where('ends_at', '>', $shift->starts_at)
            ->orderBy('starts_at')
            ->first();

        $turnaroundWarnings = $this->conflicts->findTightTurnaroundWarnings(
            $user->id,
            $shift->starts_at ?? now(),
            $shift->ends_at ?? now(),
            $shift->id,
        );

        $compliance = $this->complianceMatrix->canAssignToShift($user, $shift);

        $blockedReasons = [];
        if ($blockingConflicts->isNotEmpty()) {
            $blockedReasons[] = $this->conflicts->blockingMessage($blockingConflicts);
        }
        if ($timeOff) {
            $blockedReasons[] = 'This staff member is already marked unavailable during this time.';
        }
        if ($compliance['blocked']) {
            $blockedReasons[] = 'Hard-stop compliance requirements are not met.';
        }

        $requiredRoles = $this->coverageRoles->requirementsForShift($shift);
        $matchedRoles = collect($requiredRoles)
            ->filter(fn (array $role) => $this->coverageRoles->userHasRole($user, $role['key']))
            ->values()
            ->all();
        $missingRoles = collect($requiredRoles)
            ->reject(fn (array $role) => $this->coverageRoles->userHasRole($user, $role['key']))
            ->map(fn (array $role) => $role['label'])
            ->values()
            ->all();

        if ($missingRoles !== []) {
            $blockedReasons[] = 'This staff member does not meet the required coverage role(s): '.implode(', ', $missingRoles).'.';
        }

        $coverageStatus = $this->coverage->coverageStatusForShift($shift);
        $wouldOverfill = $coverageStatus
            && ! ($coverageStatus['allow_overstaffing'] ?? true)
            && ($coverageStatus['coverage_state'] ?? null) !== 'under'
            && ($coverageStatus['unfilled_after_open_shifts'] ?? 0) <= 0;

        if ($wouldOverfill) {
            $blockedReasons[] = 'This coverage window is already filled and overstaffing is disabled for the linked demand rule.';
        }

        $warningReasons = array_values(array_map(
            fn ($warning) => ($warning['requirement'] ?? 'Requirement').' is '.$warning['status'].'.',
            $compliance['warnings'] ?? [],
        ));

        if ($turnaroundWarnings->isNotEmpty()) {
            $warningReasons[] = $this->conflicts->tightTurnaroundMessage($turnaroundWarnings);
        }

        return [
            'is_eligible' => $blockedReasons === [],
            'blocked_reasons' => $blockedReasons,
            'warning_reasons' => array_values(array_unique($warningReasons)),
            'has_time_off' => (bool) $timeOff,
            'has_staff_conflict' => $blockingConflicts->isNotEmpty(),
            'has_compliance_block' => (bool) ($compliance['blocked'] ?? false),
            'has_tight_turnaround' => $turnaroundWarnings->isNotEmpty(),
            'required_roles' => $requiredRoles,
            'matched_roles' => $matchedRoles,
            'missing_roles' => $missingRoles,
            'would_overfill_coverage' => $wouldOverfill,
            'compliance_warnings' => $compliance['warnings'] ?? [],
        ];
    }
}
