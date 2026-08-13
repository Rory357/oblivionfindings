<?php

namespace App\Policies;

use App\Models\SafeguardingConcern;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;

class SafeguardingConcernPolicy
{
    private const SITE_BYPASS_PERMISSIONS = ['reports.viewAny'];

    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    /**
     * Determine if the user can view any safeguarding concerns.
     */
    public function viewAny(User $user): bool
    {
        return $user->canDo('safeguarding.viewAny');
    }

    /**
     * Determine if the user can view the safeguarding concern.
     */
    public function view(User $user, SafeguardingConcern $concern): bool
    {
        if (! $this->canAccessConcernSite($user, $concern)) {
            return false;
        }

        // Can view if has viewAny permission
        if ($user->canDo('safeguarding.viewAny')) {
            return true;
        }

        // Can view if assigned to the concern
        if ($concern->assigned_to_user_id === $user->id) {
            return true;
        }

        // Can view if reported the concern
        if ($concern->reported_by_user_id === $user->id) {
            return true;
        }

        return false;
    }

    /**
     * Determine if the user can create safeguarding concerns.
     */
    public function create(User $user): bool
    {
        return $user->canDo('safeguarding.create');
    }

    /**
     * Determine if the user can update the safeguarding concern.
     */
    public function update(User $user, SafeguardingConcern $concern): bool
    {
        if (! $this->canAccessConcernSite($user, $concern)
            || ! $this->canAccessSensitiveConcern($user, $concern)) {
            return false;
        }

        // Can update if has general update permission
        if ($user->canDo('safeguarding.update')) {
            return true;
        }

        // Can update if assigned to the concern
        if ($concern->assigned_to_user_id === $user->id) {
            return true;
        }

        return false;
    }

    /**
     * Determine if the user can investigate the safeguarding concern.
     */
    public function investigate(User $user, SafeguardingConcern $concern): bool
    {
        return $user->canDo('safeguarding.investigate')
            && $this->canAccessConcernSite($user, $concern)
            && $this->canAccessSensitiveConcern($user, $concern);
    }

    /**
     * Determine if the user can report to external authorities.
     */
    public function reportExternal(User $user, SafeguardingConcern $concern): bool
    {
        return $user->canDo('safeguarding.report.external')
            && $this->canAccessConcernSite($user, $concern)
            && $this->canAccessSensitiveConcern($user, $concern);
    }

    /**
     * Determine if the user can view sensitive allegations.
     */
    public function viewSensitive(User $user): bool
    {
        return $user->canDo('safeguarding.viewSensitive');
    }

    /**
     * Canonical SQL counterpart to view(): Site-less organisation records and
     * the explicit reports permission remain global; every Site-owned concern
     * is constrained before it is loaded.
     */
    public function applyVisibleScope(Builder $query, User $user): Builder
    {
        if ($this->siteAccess->canBypass($user, self::SITE_BYPASS_PERMISSIONS)) {
            return $query;
        }

        $siteIds = $this->siteAccess->accessibleSiteIds($user);

        return $query->where(function (Builder $sites) use ($siteIds): void {
            $sites->whereNull('site_id');
            if ($siteIds !== []) {
                $sites->orWhereIn('site_id', $siteIds);
            }
        });
    }

    private function canAccessConcernSite(User $user, SafeguardingConcern $concern): bool
    {
        $siteId = is_numeric($concern->site_id) && (int) $concern->site_id > 0
            ? (int) $concern->site_id
            : null;

        if ($siteId === null || $this->siteAccess->canBypass($user, self::SITE_BYPASS_PERMISSIONS)) {
            return true;
        }

        return in_array($siteId, $this->siteAccess->accessibleSiteIds($user), true);
    }

    private function canAccessSensitiveConcern(User $user, SafeguardingConcern $concern): bool
    {
        return ! $concern->is_sensitive
            || $user->canDo('safeguarding.viewSensitive')
            || (int) $concern->assigned_to_user_id === (int) $user->id
            || (int) $concern->reported_by_user_id === (int) $user->id;
    }
}
