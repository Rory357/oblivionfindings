<?php

namespace App\Services\Sites;

use App\Models\SiteVendor;
use App\Models\User;
use App\Models\VendorAgreement;
use App\Services\SiteTypeAccessService;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

final class VendorCommercialAccess
{
    public const ROLES = ['finance', 'ceo', 'coo', 'cfo', 'provider_manager', 'house_manager', 'site_manager'];

    public function capable(User $actor, string $action = 'view'): bool
    {
        return $actor->approved_at !== null && in_array($action, ['view', 'manage'], true)
            && $actor->hasRole(...self::ROLES) && $actor->canDo('vendors.contracts.'.$action);
    }

    /** Directory access and Finance-master access are not prerequisites for an explicit commercial grant. */
    public function vendors(User $actor, string $action = 'view'): Builder
    {
        $query = SiteVendor::query();
        if (! $this->capable($actor, $action) || ! Schema::hasTable('vendor_agreements')) return $query->whereRaw('1 = 0');
        $ids = app(UserSiteAccessService::class)->accessibleSiteIds($actor, ['sites.viewAll']);
        return $query->whereHas('site', fn ($q) => $q->active()->notArchived()->whereNull('archived_at')
            ->whereIn('type', app(SiteTypeAccessService::class)->allowedTypes($actor)))
            ->where(function ($q) use ($ids, $action) {
                $q->whereIn('site_id', $ids);
                if ($action === 'view' && $ids !== []) $q->orWhere('visibility', 'all_approved_sites');
            });
    }

    public function query(User $actor, string $action = 'view'): Builder
    {
        $query = VendorAgreement::query();
        if (! $this->capable($actor, $action)) return $query->whereRaw('1 = 0');
        $ids = app(UserSiteAccessService::class)->accessibleSiteIds($actor, ['sites.viewAll']);
        return $query->whereIn('vendor_id', $this->vendors($actor, $action)->select('site_vendors.id'))
            ->whereHas('vendor', fn ($q) => $q->whereColumn('site_vendors.site_id', 'vendor_agreements.site_id'))
            ->where(function ($q) use ($ids, $action) {
                $q->whereIn('site_id', $ids);
                if ($action === 'view' && $ids !== []) $q->orWhere('visibility', 'all_approved_sites');
            });
    }

    public function authorize(User $actor, VendorAgreement $agreement, string $action = 'view'): void
    {
        abort_unless($this->query($actor, $action)->whereKey($agreement->id)->exists(), 404);
    }
}
