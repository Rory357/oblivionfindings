<?php

namespace App\Services\Sites\Calendar\Providers;

use App\Models\SiteCertification;
use App\Models\SiteComplianceCheck;
use App\Services\Sites\Calendar\CalendarItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ComplianceObligationProvider extends ObligationProvider
{
    public function sourceKey(): string
    {
        return 'compliance';
    }

    public function obligations(array $siteIds, Carbon $start, Carbon $end): array
    {
        if ($siteIds === []) {
            return [];
        }

        $items = [];

        // Certificates expiring in range
        $certs = SiteCertification::query()
            ->whereIn('site_id', $siteIds)
            ->whereNotNull('expiry_date')
            ->whereBetween('expiry_date', [$start->toDateString(), $end->toDateString()])
            ->with('site:id,name,type')
            ->get();

        foreach ($certs as $cert) {
            $expired = $cert->status === 'expired' || $cert->expiry_date->lt(Carbon::today());
            $items[] = new CalendarItem(
                id: "compliance-cert-{$cert->id}",
                source: 'compliance',
                group: 'auto',
                title: ($cert->name ?: 'Certificate').' expires',
                start: $this->isoDate($cert->expiry_date),
                allDay: true,
                status: $expired ? 'overdue' : 'scheduled',
                ref: $cert->reference_number,
                site: $this->siteArray($cert->site),
                link: "/sites/{$cert->site_id}?tab=compliance",
            );
        }

        // Compliance checks scheduled in range
        $checks = SiteComplianceCheck::query()
            ->whereIn('site_id', $siteIds)
            ->whereNotNull('scheduled_date')
            ->whereBetween('scheduled_date', [$start->toDateString(), $end->toDateString()])
            ->with('site:id,name,type')
            ->get();

        foreach ($checks as $check) {
            $items[] = new CalendarItem(
                id: "compliance-check-{$check->id}",
                source: 'compliance',
                group: 'auto',
                title: Str::headline($check->check_type ?: 'Compliance check'),
                start: $this->isoDate($check->scheduled_date),
                allDay: true,
                status: $this->dueStatus($check->scheduled_date, $check->status === 'completed'),
                site: $this->siteArray($check->site),
                link: "/sites/{$check->site_id}?tab=compliance",
            );
        }

        return $items;
    }
}
