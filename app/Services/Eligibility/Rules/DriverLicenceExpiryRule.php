<?php

namespace App\Services\Eligibility\Rules;

use App\Domain\Hr\Models\HrDriverEligibility;
use App\Models\Shift;
use App\Models\User;
use App\Services\CoverageRoleService;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * When a shift requires the 'driver' coverage role, validates that the
 * staff member's driving licence has not expired and will not expire
 * before the shift starts.
 *
 * Complements CoverageRoleService (which checks status + can_drive_clients)
 * by adding the temporal licence-expiry dimension.
 */
class DriverLicenceExpiryRule implements EligibilityRuleInterface
{
    protected const EXPIRY_WARNING_DAYS = 30;

    public function __construct(
        protected CoverageRoleService $coverageRoles,
    ) {}

    public function evaluate(Shift $shift, User $user): array
    {
        // Only relevant when the shift requires a driver.
        $requiredRoles = collect($this->coverageRoles->rolesForShift($shift));

        if (! $requiredRoles->contains('driver')) {
            return self::pass();
        }

        $eligibility = HrDriverEligibility::where('user_id', $user->id)->first();

        if (! $eligibility) {
            // CoverageRoleService will already block via missing role.
            // We add an explicit message for clarity.
            return [
                'rule' => 'driver_licence',
                'passed' => false,
                'severity' => 'block',
                'overrideable' => false,
                'message' => 'No driver eligibility record on file.',
            ];
        }

        // Licence expiry not recorded.
        if (! $eligibility->licence_expires_at) {
            return [
                'rule' => 'driver_licence',
                'passed' => false,
                'severity' => 'warning',
                'overrideable' => true,
                'message' => 'Driving licence expiry date not recorded.',
            ];
        }

        $licenceExpiry = $eligibility->licence_expires_at instanceof CarbonInterface
            ? $eligibility->licence_expires_at
            : Carbon::parse($eligibility->licence_expires_at);

        $shiftStart = $shift->starts_at instanceof CarbonInterface
            ? $shift->starts_at
            : Carbon::parse($shift->starts_at);

        // Expired before shift starts.
        if ($licenceExpiry->lt($shiftStart)) {
            return [
                'rule' => 'driver_licence',
                'passed' => false,
                'severity' => 'block',
                'overrideable' => false,
                'message' => "Driving licence expired on {$licenceExpiry->format('j M Y')}.",
            ];
        }

        // Expiring within warning window.
        if ($licenceExpiry->diffInDays($shiftStart, false) <= self::EXPIRY_WARNING_DAYS) {
            return [
                'rule' => 'driver_licence',
                'passed' => false,
                'severity' => 'warning',
                'overrideable' => true,
                'message' => "Driving licence expires on {$licenceExpiry->format('j M Y')} (within " . self::EXPIRY_WARNING_DAYS . " days).",
            ];
        }

        return self::pass();
    }

    /**
     * @return array{rule: string, passed: true, severity: 'block', overrideable: false, message: null}
     */
    protected static function pass(): array
    {
        return [
            'rule' => 'driver_licence',
            'passed' => true,
            'severity' => 'block',
            'overrideable' => false,
            'message' => null,
        ];
    }
}
