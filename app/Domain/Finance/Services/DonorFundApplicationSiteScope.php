<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinBill;
use App\Models\Site;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Site boundary for the supplier bill used by a donor-fund application.
 *
 * finance.admin authorizes the action at the route. Application-wide Site
 * access remains a separate, explicit permission and never replaces it.
 */
final class DonorFundApplicationSiteScope
{
    public const GLOBAL_PERMISSION = 'finance.donorFunds.manageAllSites';

    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    public function assertCanAccessBill(User $actor, FinBill $bill): void
    {
        abort_unless(
            (int) $bill->organization_id === (int) $actor->organization_id
                && $bill->site_id !== null
                && $this->activeSiteExists((int) $bill->site_id)
                && in_array((int) $bill->site_id, $this->accessibleSiteIds($actor), true),
            404,
        );
    }

    public function applyBillScope(Builder $query, User $actor): Builder
    {
        $siteIds = $this->accessibleSiteIds($actor);

        return $siteIds === []
            ? $query->whereRaw('1 = 0')
            : $query->whereIn($query->qualifyColumn('site_id'), $siteIds);
    }

    /** @return list<int> */
    public function accessibleSiteIdsFor(User $actor): array
    {
        return $this->accessibleSiteIds($actor);
    }

    /** @return list<int> */
    private function accessibleSiteIds(User $actor): array
    {
        return $this->siteAccess->accessibleSiteIds(
            $actor,
            $actor->canDo(self::GLOBAL_PERMISSION) ? [self::GLOBAL_PERMISSION] : [],
        );
    }

    private function activeSiteExists(int $siteId): bool
    {
        return Site::query()
            ->active()
            ->notArchived()
            ->whereNull('archived_at')
            ->whereKey($siteId)
            ->exists();
    }
}
