<?php

namespace App\Services\Eligibility\Rules;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;

/**
 * Validates that the staff member is assigned to the shift's site
 * (primary or secondary). Always a soft warning — cross-site
 * assignments are common in supported living and can be overridden.
 */
class SiteAssignmentRule implements EligibilityRuleInterface
{
    public function evaluate(Shift $shift, User $user): array
    {
        $siteId = $shift->site_id;

        // No site constraint on the shift — nothing to check.
        if (! $siteId) {
            return self::pass();
        }

        $profile = HrEmployeeProfile::where('user_id', $user->id)
            ->where('is_active', true)
            ->first(['primary_site_id', 'secondary_site_ids']);

        if (! $profile) {
            return [
                'rule' => 'site_assignment',
                'passed' => false,
                'severity' => 'warning',
                'overrideable' => true,
                'message' => 'No active employee profile found — cannot verify site assignment.',
            ];
        }

        // Primary site match.
        if ((int) $profile->primary_site_id === (int) $siteId) {
            return self::pass();
        }

        // Secondary sites match.
        $secondarySites = is_array($profile->secondary_site_ids)
            ? array_map('intval', $profile->secondary_site_ids)
            : [];

        if (in_array((int) $siteId, $secondarySites, true)) {
            return self::pass();
        }

        // Mismatch — build a useful message.
        $staffSiteName = $this->siteName($profile->primary_site_id);
        $shiftSiteName = $this->siteName($siteId);

        return [
            'rule' => 'site_assignment',
            'passed' => false,
            'severity' => 'warning',
            'overrideable' => true,
            'message' => "Staff assigned to {$staffSiteName} but shift is at {$shiftSiteName}.",
        ];
    }

    protected function siteName(?int $siteId): string
    {
        if (! $siteId) {
            return 'no site';
        }

        return Site::where('id', $siteId)->value('name') ?? "Site #{$siteId}";
    }

    /**
     * @return array{rule: string, passed: true, severity: 'warning', overrideable: false, message: null}
     */
    protected static function pass(): array
    {
        return [
            'rule' => 'site_assignment',
            'passed' => true,
            'severity' => 'warning',
            'overrideable' => false,
            'message' => null,
        ];
    }
}
