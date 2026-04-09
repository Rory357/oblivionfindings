<?php

namespace App\Services\Eligibility\Rules;

use App\Models\Shift;
use App\Models\User;

interface EligibilityRuleInterface
{
    /**
     * Evaluate whether a staff member satisfies this rule for a given shift.
     *
     * @return array{rule: string, passed: bool, severity: 'block'|'warning'|'info', overrideable: bool, message: ?string}
     */
    public function evaluate(Shift $shift, User $user): array;
}
