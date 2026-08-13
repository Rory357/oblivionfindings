<?php

namespace App\Services\Eligibility;

use App\Models\Shift;
use App\Models\User;
use App\Services\ShiftStaffEligibilityService;
use Illuminate\Support\Facades\Log;

/**
 * Fail-closed boundary around the complete assignment eligibility rule stack.
 */
final class AssignmentEligibilityGateway
{
    public function __construct(
        private readonly ShiftStaffEligibilityService $eligibility,
    ) {}

    public function decide(Shift $shift, User $user): AssignmentEligibilityDecision
    {
        try {
            return AssignmentEligibilityDecision::fromResult(
                $shift,
                $user,
                $this->eligibility->evaluate($shift, $user),
            );
        } catch (\Throwable $exception) {
            Log::error('Assignment eligibility decision unavailable', [
                'shift_id' => $shift->getKey(),
                'user_id' => $user->getKey(),
                'exception_class' => $exception::class,
            ]);

            return AssignmentEligibilityDecision::unavailable($shift, $user);
        }
    }
}
