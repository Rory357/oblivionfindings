<?php

namespace App\Services\Eligibility\Rules;

use App\Models\Shift;
use App\Models\User;
use App\Services\Eligibility\HouseQualificationCoverageService;

final class HouseQualificationRule implements EligibilityRuleInterface
{
    public function __construct(private readonly HouseQualificationCoverageService $coverage) {}

    public function evaluate(Shift $shift, User $user): array
    {
        $checks = $this->evaluateAll($shift, $user);
        $failed = collect($checks)->where('passed', false);
        if ($failed->isEmpty()) {
            return ['rule' => 'house_qualification', 'passed' => true, 'severity' => 'warning',
                'overrideable' => false, 'message' => null];
        }
        $blocked = $failed->contains('severity', 'block');

        return ['rule' => 'house_qualification', 'passed' => false, 'severity' => $blocked ? 'block' : 'warning',
            'overrideable' => ! $blocked, 'message' => $failed->pluck('message')->filter()->unique()->implode(' ')];
    }

    public function evaluateAll(Shift $shift, User $user, bool $current = false): array
    {
        return $this->coverage->evaluateAll($shift, $user, $current);
    }
}
