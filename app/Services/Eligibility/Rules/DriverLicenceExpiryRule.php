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
 * before the shift starts, and that their driver eligibility has not been
 * suspended.
 *
 * Complements CoverageRoleService (which checks status + can_drive_clients)
 * by adding the temporal licence-expiry dimension and an explicit,
 * human-readable suspension block (belt-and-braces: CoverageRoleService
 * already withholds the 'driver' role from non-eligible staff, but that
 * surfaces only as a generic missing-role message).
 *
 * DEFERRED (audit round 2, item 5): licence CLASS / endorsement matching at
 * the roster gate. Neither shifts nor coverage roles declare a required
 * licence class today — `shifts.coverage_roles` is a plain list of role keys
 * and CoverageRoleService::supportedRoles() carries no class metadata — so
 * there is nothing to match hr_driver_eligibility.licence_class /
 * licence_endorsements against. If a `required_licence_class` (or similar)
 * field is ever added to shifts/coverage requirements, extend evaluate() to
 * block when the assigned driver's class/endorsements don't satisfy it.
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

        $eligibility = $user->relationLoaded('hrDriverEligibility')
            ? $user->hrDriverEligibility
            : HrDriverEligibility::where('user_id', $user->id)->first();

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

        // Suspended drivers are hard-blocked regardless of licence dates
        // (status values in use: eligible | pending_review | review_required |
        // suspended — there is no separate stood-down value; suspension_reason
        // carries the why).
        if ($eligibility->status === 'suspended') {
            return [
                'rule' => 'driver_licence',
                'passed' => false,
                'severity' => 'block',
                'overrideable' => false,
                'message' => 'Driver eligibility is suspended'
                    . ($eligibility->suspension_reason ? " ({$eligibility->suspension_reason})" : '')
                    . '.',
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

        // Expiring within warning window. Compute whole days from the shift to
        // the (not-yet-passed) expiry off the day boundaries so the sign is
        // unambiguous — `$expiry->diffInDays($shiftStart, false)` is negative
        // for any future expiry and would warn on every valid licence.
        $daysUntilExpiry = $shiftStart->copy()->startOfDay()->diffInDays($licenceExpiry->copy()->startOfDay());

        if ($daysUntilExpiry <= self::EXPIRY_WARNING_DAYS) {
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
