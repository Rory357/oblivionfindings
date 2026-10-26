<?php

namespace App\Domain\Referrals\Services;

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralFundingCase;
use App\Domain\Referrals\ReferralPermissions;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;

final class ReferralAccess
{
    public function __construct(private readonly UserSiteAccessService $sites) {}

    public function can(User $user, string $action): bool
    {
        if (! array_key_exists($action, ReferralPermissions::DEFINITIONS) || ! $user->canDo('referrals.'.$action)) {
            return false;
        }

        return match ($action) {
            'assessment.manage' => $user->canDo('referrals.assessment.view'),
            'funding.manage', 'funding.verify', 'proposals.manage', 'proposals.approve' => $user->canDo('referrals.funding.view'),
            'settings.manage', 'settings.publish', 'settings.governance' => $user->canDo('referrals.settings.view'),
            'reports.export' => $user->canDo('referrals.reports.view'),
            default => true,
        };
    }

    /** Site access and ownership are independent of the action permission. */
    public function query(User $user, ?CurrentAuthorizationReads $reads = null): Builder
    {
        $query = CareReferral::query();
        if (! $this->can($user, 'viewAny') && ! $this->can($user, 'viewAssigned')) {
            return $query->whereRaw('1 = 0');
        }

        // Cross-site and central-intake grants expand scope, never staff eligibility.
        $staff = $this->sites->applyStaffScope(User::query()->whereKey($user->id), $user, ['referrals.viewAllSites', 'referrals.centralIntake']);
        if (! ($reads ? $reads->query($staff) : $staff)->exists()) {
            return $query->whereRaw('1 = 0');
        }

        $ids = $this->sites->accessibleSiteIds($user, ['referrals.viewAllSites'], $reads);
        $query->where(function (Builder $siteQuery) use ($ids, $user): void {
            $siteQuery->whereIn('site_id', $ids);
            if ($this->can($user, 'centralIntake')) {
                $siteQuery->orWhereNull('site_id');
            }
        });
        if (! $this->can($user, 'restricted')) {
            $query->where('restricted', false);
        }
        if (! $this->can($user, 'viewAny')) {
            $query->where(function (Builder $owned) use ($user): void {
                $owned->where('owner_id', $user->id)->orWhereIn('id', ReferralFundingCase::query()
                    ->select('referral_id')->where('navigator_id', $user->id));
            });
        }

        return $reads ? $reads->query($query) : $query;
    }

    public function authorize(User $user, CareReferral $referral, string $action, ?CurrentAuthorizationReads $reads = null): void
    {
        // Resolve visibility before reporting action denial; do not disclose an inaccessible object.
        abort_unless($this->query($user, $reads)->whereKey($referral->id)->exists(), 404);
        abort_unless($this->can($user, $action), 403);
    }

    public function permissions(User $user): array
    {
        $result = [];
        foreach (array_keys(ReferralPermissions::DEFINITIONS) as $action) {
            $result[$action] = $this->can($user, $action);
        }

        return $result;
    }

    public function eligibleForSite(User $user, ?int $siteId, ?CurrentAuthorizationReads $reads = null): bool
    {
        $siteIds = $this->sites->accessibleSiteIds($user, ['referrals.viewAllSites'], $reads);
        // A broad site grant never makes an ended, unapproved or portal account assignable.
        $staff = $this->sites->applyStaffScope(User::query()->whereKey($user->id), $user, ['referrals.viewAllSites', 'referrals.centralIntake']);
        if (! ($reads ? $reads->query($staff) : $staff)->exists()) {
            return false;
        }

        if (! $this->can($user, 'viewAny') && ! $this->can($user, 'viewAssigned')) {
            return false;
        }

        return $siteId === null
            ? $this->can($user, 'centralIntake')
            : in_array($siteId, $siteIds, true);
    }

    public function audience(User $user, string $audience, bool $write = false): bool
    {
        return match ($audience) {
            'intake' => true,
            'assessment' => $this->can($user, $write ? 'assessment.manage' : 'assessment.view'),
            'funding' => $this->can($user, $write ? 'funding.manage' : 'funding.view'),
            default => false,
        };
    }

    public function site(User $user, ?int $siteId, ?CurrentAuthorizationReads $reads = null): bool
    {
        return $siteId === null ? $this->can($user, 'centralIntake')
            : in_array($siteId, $this->sites->accessibleSiteIds($user, ['referrals.viewAllSites'], $reads), true);
    }
}
