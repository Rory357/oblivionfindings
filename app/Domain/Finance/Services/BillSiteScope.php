<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinBill;
use App\Models\Site;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;

/** AP uses approved Sites; central access never substitutes for an AP capability. */
final class BillSiteScope
{
    public const READ_ALL = ['finance.payments.viewAllSites', 'finance.payments.manageAllSites'];

    public const WRITE_ALL = ['finance.payments.manageAllSites'];

    public function __construct(private readonly UserSiteAccessService $sites) {}

    public function siteIds(User $actor, bool $manage = false): array
    {
        return $this->sites->accessibleSiteIds($actor, $manage ? self::WRITE_ALL : self::READ_ALL);
    }

    public function central(User $actor, bool $manage = false): bool
    {
        return $this->sites->canBypass($actor, $manage ? self::WRITE_ALL : self::READ_ALL);
    }

    public function apply(Builder $query, User $actor, bool $manage = false): Builder
    {
        $ids = $this->siteIds($actor, $manage);

        return $query->where(function (Builder $scope) use ($query, $ids, $actor, $manage): void {
            $scope->whereIn($query->qualifyColumn('site_id'), $ids);
            // Central Finance may repair unassigned legacy bills; they cannot approve them as-is.
            if ($this->central($actor, $manage)) {
                $scope->orWhereNull($query->qualifyColumn('site_id'));
            }
        });
    }

    public function allows(User $actor, FinBill $bill, bool $manage = false): bool
    {
        return $bill->site_id === null
            ? $this->central($actor, $manage)
            : in_array((int) $bill->site_id, $this->siteIds($actor, $manage), true);
    }

    public function assertSite(User $actor, ?int $siteId): void
    {
        abort_unless($siteId && in_array($siteId, $this->siteIds($actor, true), true), 404);
    }

    public function options(User $actor): array
    {
        return Site::query()->whereIn('id', $this->siteIds($actor, true))->orderBy('name')->get(['id', 'name'])->toArray();
    }
}
