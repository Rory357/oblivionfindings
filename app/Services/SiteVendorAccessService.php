<?php

namespace App\Services;

use App\Models\SiteVendor;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/** The canonical Vendor Directory read boundary; no credential metadata is selected. */
final class SiteVendorAccessService
{
    public function query(User $actor): Builder
    {
        $query = SiteVendor::query();
        if ($actor->approved_at === null || ! $actor->canDo('vendors.view')) {
            return $query->whereRaw('1 = 0');
        }

        $ids = app(UserSiteAccessService::class)->accessibleSiteIds($actor, ['sites.viewAll']);
        return $query->where(function ($q) use ($ids) {
            $q->whereIn('site_id', $ids);
            if ($ids !== [] && \Illuminate\Support\Facades\Schema::hasTable('vendor_agreements')) $q->orWhere('visibility', 'all_approved_sites');
        })->whereHas('site', fn ($sites) => $sites->active()->notArchived()->whereNull('archived_at')->whereIn('type', app(SiteTypeAccessService::class)->allowedTypes($actor)));
    }
}
