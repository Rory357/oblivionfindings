<?php

namespace App\Services\Eligibility\Rules;

use App\Models\Shift;
use App\Models\User;
use App\Services\HealthSafety\HsTrainingComplianceService;

/**
 * Shift eligibility rule: checks H&S training compliance.
 *
 * This rule is injected into ShiftStaffEligibilityService alongside
 * existing rules. It uses the same return format and respects the
 * existing override mechanism (ShiftEligibilityOverride).
 *
 * Design choices:
 *  - Defaults to 'warning' severity for most requirements (non-blocking)
 *  - Only uses 'block' severity when requirement.enforcement_mode = 'block'
 *  - All blocks are overrideable = true so managers can override in emergencies
 *  - Grace period prevents sudden blocks when training just expired
 */
class HsTrainingRule implements EligibilityRuleInterface
{
    public function __construct(
        private readonly HsTrainingComplianceService $complianceService,
    ) {}

    public function evaluate(Shift $shift, User $user): array
    {
        $results = $this->evaluateAll($shift, $user);

        // Return first failure, or first warning, or pass
        foreach ($results as $result) {
            if (! $result['passed']) {
                return $result;
            }
        }

        return [
            'rule' => 'hs_training',
            'passed' => true,
            'severity' => 'info',
            'overrideable' => true,
            'message' => null,
        ];
    }

    /**
     * Evaluate all H&S training requirements for the shift/user context.
     *
     * Returns one result per non-compliant requirement, plus a pass
     * if everything is compliant.
     *
     * @return array<array{rule: string, passed: bool, severity: string, overrideable: bool, message: ?string}>
     */
    public function evaluateAll(Shift $shift, User $user): array
    {
        $check = $this->complianceService->checkForShift($user, $shift);

        $results = [];

        // Hard blocks (enforcement_mode = 'block')
        foreach ($check['failures'] as $failure) {
            $results[] = [
                'rule' => 'hs_training',
                'passed' => false,
                'severity' => 'block',
                'overrideable' => true, // Managers can still override in emergencies
                'message' => "H&S training requirement not met: {$failure['requirement_name']} ({$failure['status']})",
                'requirement_code' => $failure['requirement_code'],
                'hs_training_status' => $failure['status'],
            ];
        }

        // Soft warnings (enforcement_mode = 'warn')
        foreach ($check['warnings'] as $warning) {
            $results[] = [
                'rule' => 'hs_training',
                'passed' => false,
                'severity' => 'warning',
                'overrideable' => true,
                'message' => "H&S training advisory: {$warning['requirement_name']} ({$warning['status']})",
                'requirement_code' => $warning['requirement_code'],
                'hs_training_status' => $warning['status'],
            ];
        }

        if (empty($results)) {
            $results[] = [
                'rule' => 'hs_training',
                'passed' => true,
                'severity' => 'info',
                'overrideable' => true,
                'message' => null,
            ];
        }

        return $results;
    }
}
