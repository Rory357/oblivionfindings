<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Staff records in the staff module (/staff, /staff/{user} and its
 * credentials and assignments) are limited to the viewer's own Sites
 * (Stephan, 24 Oct 2026). A staff member is in the viewer's Sites when their
 * HR profile, current or former, has its primary or a secondary Site among
 * the viewer's Sites (UserSiteAccessService::applyHistoricalStaffSiteScope).
 *
 * - sites.viewAll sees every staff member. hr.employees.viewAllSites is
 *   deliberately not a bypass here.
 * - Everyone can always open their own record.
 * - A staff member with no HR profile (legacy staff table only) has no Site,
 *   so only sites.viewAll opens them.
 *
 * Outside the viewer's Sites a record reads as not found, so it isn't
 * confirmed to exist. The list and the record use the same rule.
 */
trait LimitsStaffRecordsToViewerSites
{
    /** @var list<string> */
    private const STAFF_RECORD_SITE_BYPASS_PERMISSIONS = ['sites.viewAll'];

    protected function abortUnlessStaffInViewerSites(User $viewer, User $staff): void
    {
        if ((int) $viewer->id === (int) $staff->id) {
            return;
        }

        $siteAccess = app(UserSiteAccessService::class);
        if ($siteAccess->canBypass($viewer, self::STAFF_RECORD_SITE_BYPASS_PERMISSIONS)) {
            return;
        }

        $inViewerSites = $siteAccess
            ->applyHistoricalStaffSiteScope(
                User::query()->whereKey($staff->id),
                $viewer,
                self::STAFF_RECORD_SITE_BYPASS_PERMISSIONS,
            )
            ->exists();

        abort_unless($inViewerSites, 404);
    }

    /**
     * Narrow a list of users to the staff whose records the viewer can open:
     * everyone with sites.viewAll, otherwise staff in the viewer's Sites plus
     * the viewer's own row.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    protected function limitStaffListToViewerSites(Builder $query, User $viewer): Builder
    {
        $siteAccess = app(UserSiteAccessService::class);
        if ($siteAccess->canBypass($viewer, self::STAFF_RECORD_SITE_BYPASS_PERMISSIONS)) {
            return $query;
        }

        $inViewerSites = $siteAccess->applyHistoricalStaffSiteScope(
            User::query()->select('users.id'),
            $viewer,
            self::STAFF_RECORD_SITE_BYPASS_PERMISSIONS,
        );
        $idColumn = $query->qualifyColumn('id');

        return $query->where(fn (Builder $visible) => $visible
            ->whereIn($idColumn, $inViewerSites)
            ->orWhere($idColumn, $viewer->id));
    }
}
