<?php

namespace App\Services\Sites\Calendar\Providers;

use App\Models\SiteVendor;
use App\Services\Sites\Calendar\CalendarItem;
use Illuminate\Support\Carbon;

/**
 * Surfaces the dated vendor obligations: insurance expiry, contract renewal and
 * the next scheduled visit (active vendors only). Insurance / contract are
 * overdue-able (a past date is a problem); a next visit is a forward booking.
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

        $vendors = SiteVendor::query()
            ->whereIn('site_id', $siteIds)
            ->where('is_active', true)
            ->with('site:id,name,type')
            ->get();

        $items = [];

        foreach ($vendors as $vendor) {
            // [date, id-suffix, title-suffix, is-a-booking (never "overdue")]
            $obligations = [
                [$vendor->insurance_expiry, '', 'insurance expiry', false],
                [$vendor->contract_renewal_date, '-contract', 'contract renewal', false],
                [$vendor->next_visit_date, '-visit', 'scheduled visit', true],
            ];

            foreach ($obligations as [$date, $idSuffix, $titleSuffix, $isBooking]) {
                if (! $date instanceof Carbon || ! $this->inRange($date, $start, $end)) {
                    continue;
                }

                $items[] = new CalendarItem(
                    id: "vendor-{$vendor->id}{$idSuffix}",
                    source: 'vendor',
                    group: 'auto',
                    title: $vendor->company_name.' — '.$titleSuffix,
                    start: $this->isoDate($date),
                    allDay: true,
                    status: $isBooking ? 'scheduled' : $this->dueStatus($date, false),
                    ref: 'VEN-'.$vendor->id,
                    site: $this->siteArray($vendor->site),
                    // Unified Vendor Directory & Access Vault (sites.vendors.global),
                    // pre-filtered to this site and opened on the Vendors tab —
                    // not the legacy per-site /sites/{id}/vendors index.
                    link: "/vendors?site_id={$vendor->site_id}&tab=vendors",
                );
            }
        }

        return $items;
    }
}
