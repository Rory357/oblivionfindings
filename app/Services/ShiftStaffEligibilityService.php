<?php

namespace App\Services;

use App\Domain\Hr\Services\ComplianceMatrixService;
use App\Models\Shift;
use App\Models\StaffTimeOff;
use App\Models\User;
use App\Services\Eligibility\EligibilityResult;
use App\Services\Eligibility\Rules\AvailabilityRule;
use App\Services\Eligibility\Rules\DriverLicenceExpiryRule;
use App\Services\Eligibility\Rules\FatigueRule;
use App\Services\Eligibility\Rules\HsTrainingRule;
use App\Services\Eligibility\Rules\SiteAssignmentRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ShiftStaffEligibilityService
{
    public function __construct(
        protected ComplianceMatrixService $complianceMatrix,
        protected ShiftConflictService $conflicts,
        protected CoverageRoleService $coverageRoles,
        protected ShiftCoverageService $coverage,
        protected AvailabilityRule $availabilityRule,
        protected FatigueRule $fatigueRule,
        protected SiteAssignmentRule $siteAssignmentRule,
        protected DriverLicenceExpiryRule $driverLicenceRule,
        protected HsTrainingRule $hsTrainingRule,
    ) {}

    /**
     * Evaluate all eligibility rules for assigning a user to a shift.
     *
     * Returns an EligibilityResult value object. For backwards compatibility,
     * callers using the old array shape can call ->toArray() on the result.
     */
    public function evaluate(Shift $shift, User $user): EligibilityResult
    {
        $checks = [];

        // ── Existing checks (converted to rule-result format) ──────────

        $checks[] = $this->checkConflicts($shift, $user);
        $checks[] = $this->checkTimeOff($shift, $user);
        $checks[] = $this->checkTurnaround($shift, $user);

        $complianceCheck = $this->checkCompliance($shift, $user);
        $checks[] = $complianceCheck;

        $checks = array_merge($checks, $this->checkCoverageRoles($shift, $user));
        $checks[] = $this->checkOverfill($shift, $user);

        // ── New rule classes ───────────────────────────────────────────

        $checks = array_merge($checks, $this->availabilityRule->evaluateAll($shift, $user));
        $checks = array_merge($checks, $this->fatigueRule->evaluateAll($shift, $user));
        $checks[] = $this->siteAssignmentRule->evaluate($shift, $user);
        $checks[] = $this->driverLicenceRule->evaluate($shift, $user);
        $checks = array_merge($checks, $this->hsTrainingRule->evaluateAll($shift, $user));

        return EligibilityResult::fromChecks($checks);
    }

    /**
     * Evaluate multiple shift/user pairs after eager-loading the relations
     * the rule stack commonly asks for. The result is keyed by shift id, then
     * user id, so callers can format existing cards without re-querying.
     *
     * @param  iterable<Shift>  $shifts
     * @param  iterable<User>   $users
     * @return array<int, array<int, EligibilityResult>>
     */
    public function evaluateMany(iterable $shifts, iterable $users): array
    {
        $shiftModels = collect($shifts)
            ->filter(fn ($shift) => $shift instanceof Shift && $shift->id)
            ->values();
        $userModels = collect($users)
            ->filter(fn ($user) => $user instanceof User && $user->id)
            ->values();

        if ($shiftModels->isEmpty() || $userModels->isEmpty()) {
            return [];
        }

        (new \Illuminate\Database\Eloquent\Collection($shiftModels->all()))
            ->loadMissing([
                'client:id,site_id',
                'site:id,name',
                'serviceContext:id,name,type',
            ]);

        (new \Illuminate\Database\Eloquent\Collection($userModels->all()))
            ->loadMissing([
                'staffAvailability',
                'staffTimeOff',
                'hrLeaveRequests' => fn ($query) => $query->where('status', 'approved'),
                'hrEmployeeProfile',
                'hrComplianceStatuses.requirement:id,code,name,hard_stop,is_active',
                'hrDriverEligibility',
            ]);

        $results = [];
        foreach ($shiftModels as $shift) {
            foreach ($userModels as $user) {
                try {
                    $results[$shift->id][$user->id] = $this->evaluate($shift, $user);
                } catch (\Throwable $e) {
                    Log::warning('Batch eligibility evaluate failed', [
                        'shift_id' => $shift->id,
                        'user_id' => $user->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $results;
    }

    /**
     * Cheap pre-filter for batch suggestion runs. The full 11-rule evaluation
     * still happens before a candidate is persisted or applied.
     *
     * @return Collection<int, User>
     */
    public function candidatesFor(Shift $shift): Collection
    {
        $shift->loadMissing('client:id,site_id');
        $siteId = $shift->site_id ?: $shift->client?->site_id;

        return User::staff()
            ->when($shift->organization_id, fn ($query) => $query->where('organization_id', $shift->organization_id))
            ->where(function (Builder $query) use ($siteId): void {
                $query->whereDoesntHave('hrEmployeeProfile')
                    ->orWhereHas('hrEmployeeProfile', function (Builder $profileQuery) use ($siteId): void {
                        $profileQuery->where('is_active', true);

                        if ($siteId) {
                            $profileQuery->where(function (Builder $siteQuery) use ($siteId): void {
                                $siteQuery->where('primary_site_id', $siteId)
                                    ->orWhereJsonContains('secondary_site_ids', (int) $siteId);
                            });
                        }
                    });
            })
            ->whereDoesntHave('hrComplianceStatuses', function (Builder $query): void {
                $query->whereIn('status', ['expired', 'not_started'])
                    ->whereHas('requirement', function (Builder $requirementQuery): void {
                        $requirementQuery->where('hard_stop', true)
                            ->where('is_active', true);
                    });
            })
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'organization_id']);
    }

    // ── Private rule methods (existing logic, reformatted as rule results) ──

    protected function checkConflicts(Shift $shift, User $user): array
    {
        $blockingConflicts = $this->conflicts->findBlockingStaffConflicts(
            $user->id,
            $shift->starts_at ?? now(),
            $shift->ends_at ?? now(),
            $shift->id,
        );

        if ($blockingConflicts->isNotEmpty()) {
            return [
                'rule' => 'conflict',
                'passed' => false,
                'severity' => 'block',
                'overrideable' => false,
                'message' => $this->conflicts->blockingMessage($blockingConflicts),
            ];
        }

        return self::pass('conflict');
    }

    protected function checkTimeOff(Shift $shift, User $user): array
    {
        $timeOff = StaffTimeOff::query()
            ->where('user_id', $user->id)
            ->where('starts_at', '<', $shift->ends_at)
            ->where('ends_at', '>', $shift->starts_at)
            ->orderBy('starts_at')
            ->first();

        if ($timeOff) {
            return [
                'rule' => 'time_off',
                'passed' => false,
                'severity' => 'block',
                'overrideable' => false,
                'message' => 'This staff member is already marked unavailable during this time.',
            ];
        }

        return self::pass('time_off');
    }

    protected function checkTurnaround(Shift $shift, User $user): array
    {
        $turnaroundWarnings = $this->conflicts->findTightTurnaroundWarnings(
            $user->id,
            $shift->starts_at ?? now(),
            $shift->ends_at ?? now(),
            $shift->id,
        );

        if ($turnaroundWarnings->isNotEmpty()) {
            return [
                'rule' => 'turnaround',
                'passed' => false,
                'severity' => 'warning',
                'overrideable' => true,
                'message' => $this->conflicts->tightTurnaroundMessage($turnaroundWarnings),
            ];
        }

        return self::pass('turnaround', 'warning');
    }

    /**
     * @return array{rule: string, passed: bool, severity: string, overrideable: bool, message: ?string, compliance_warnings?: array}
     */
    protected function checkCompliance(Shift $shift, User $user): array
    {
        $compliance = $this->complianceMatrix->canAssignToShift($user, $shift);

        if ($compliance['blocked']) {
            $failureMessages = collect($compliance['failures'] ?? [])
                ->map(function (array $f): string {
                    // Use the specific reason from live validation when available.
                    if (! empty($f['reason'])) {
                        return $f['reason'];
                    }

                    $name = $f['requirement'] ?? 'Requirement';
                    $status = $f['status'] ?? 'non-compliant';

                    if ($status === 'expired' && ! empty($f['expires_at'])) {
                        return "{$name} expired on {$f['expires_at']}.";
                    }

                    if ($status === 'not_started') {
                        return "{$name} is missing or not completed.";
                    }

                    return "{$name} is {$status}.";
                })
                ->implode(' ');

            return [
                'rule' => 'compliance',
                'passed' => false,
                'severity' => 'block',
                'overrideable' => false,
                'message' => $failureMessages,
                'compliance_warnings' => $compliance['warnings'] ?? [],
            ];
        }

        $warnings = $compliance['warnings'] ?? [];

        if (! empty($warnings)) {
            $warningMessages = collect($warnings)
                ->map(fn ($w) => ($w['requirement'] ?? 'Requirement') . ' is ' . ($w['status'] ?? 'expiring') . '.')
                ->implode(' ');

            return [
                'rule' => 'compliance',
                'passed' => false,
                'severity' => 'warning',
                'overrideable' => false,
                'message' => $warningMessages,
                'compliance_warnings' => $warnings,
            ];
        }

        return array_merge(self::pass('compliance'), ['compliance_warnings' => []]);
    }

    /**
     * @return array<int, array{rule: string, passed: bool, severity: string, overrideable: bool, message: ?string}>
     */
    protected function checkCoverageRoles(Shift $shift, User $user): array
    {
        $requiredRoles = $this->coverageRoles->requirementsForShift($shift);

        if ($requiredRoles === []) {
            return [array_merge(self::pass('coverage_roles'), [
                'required_roles' => [],
                'matched_roles' => [],
                'missing_roles' => [],
            ])];
        }

        $matchedRoles = [];
        $missingLabels = [];

        foreach ($requiredRoles as $role) {
            if ($this->coverageRoles->userHasRole($user, $role['key'])) {
                $matchedRoles[] = $role;
            } else {
                $missingLabels[] = $role['label'];
            }
        }

        $base = [
            'required_roles' => $requiredRoles,
            'matched_roles' => $matchedRoles,
            'missing_roles' => $missingLabels,
        ];

        if ($missingLabels !== []) {
            return [array_merge([
                'rule' => 'coverage_roles',
                'passed' => false,
                'severity' => 'block',
                'overrideable' => false,
                'message' => 'This staff member does not meet the required coverage role(s): ' . implode(', ', $missingLabels) . '.',
            ], $base)];
        }

        return [array_merge(self::pass('coverage_roles'), $base)];
    }

    protected function checkOverfill(Shift $shift, User $user): array
    {
        $coverageStatus = $this->coverage->coverageStatusForShift($shift);

        $wouldOverfill = $coverageStatus
            && ! ($coverageStatus['allow_overstaffing'] ?? true)
            && ($coverageStatus['coverage_state'] ?? null) !== 'under'
            && ($coverageStatus['unfilled_after_open_shifts'] ?? 0) <= 0;

        if ($wouldOverfill) {
            return [
                'rule' => 'overfill',
                'passed' => false,
                'severity' => 'block',
                'overrideable' => false,
                'message' => 'This coverage window is already filled and overstaffing is disabled for the linked demand rule.',
            ];
        }

        return self::pass('overfill');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * @return array{rule: string, passed: true, severity: string, overrideable: false, message: null}
     */
    protected static function pass(string $rule, string $severity = 'block'): array
    {
        return [
            'rule' => $rule,
            'passed' => true,
            'severity' => $severity,
            'overrideable' => false,
            'message' => null,
        ];
    }
}
