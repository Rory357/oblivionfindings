<?php

namespace App\Services\Sites\Calendar\Providers;

use App\Models\SiteVendor;
use App\Services\Sites\Calendar\CalendarItem;
use Illuminate\Support\Carbon;

/**
 * The vendor schema has no scheduled-visit date, so this surfaces the dated
 * obligation it does carry: vendor insurance expiry (active vendors only).
 */
class VendorReminderProvider extends ObligationProvider
{
    public function sourceKey(): string
    {
        return 'vendor';
    }

    public function obligations(array $siteIds, Carbon $start, Carbon $end): array
    {
        if ($siteIds === []) {
            return [];
        }

        return SiteVendor::query()
            ->whereIn('site_id', $siteIds)
            ->where('is_active', true)
            ->whereNotNull('insurance_expiry')
            ->whereBetween('insurance_expiry', [$start->toDateString(), $end->toDateString()])
            ->with('site:id,name,type')
            ->get()
            ->map(fn (SiteVendor $vendor) => new CalendarItem(
                id: "vendor-{$vendor->id}",
                source: 'vendor',
                group: 'auto',
                title: $vendor->company_name.' — insurance expiry',
                start: $this->isoDate($vendor->insurance_expiry),
                allDay: true,
                status: $vendor->insurance_expiry->lt(Carbon::today()) ? 'overdue' : 'scheduled',
                ref: 'VEN-'.$vendor->id,
                site: $this->siteArray($vendor->site),
                // Unified Vendor Directory & Access Vault (sites.vendors.global),
                // pre-filtered to this site and opened on the Vendors tab —
                // not the legacy per-site /sites/{id}/vendors index.
                link: "/vendors?site_id={$vendor->site_id}&tab=vendors",
            ))
            ->all();
    }
}
