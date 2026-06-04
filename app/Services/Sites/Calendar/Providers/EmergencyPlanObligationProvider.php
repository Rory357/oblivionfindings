<?php

namespace App\Services\Sites\Calendar\Providers;

use App\Models\SiteEmergencyPlan;
use App\Services\Sites\Calendar\CalendarItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Emergency / evacuation plan review obligations — a core supported-living
 * requirement. Surfaces each active plan's next review date (explicit
 * `next_review_at`, else `last_reviewed_at + review_interval_months`).
 */
class EmergencyPlanObligationProvider extends ObligationProvider
{
    public function sourceKey(): string
    {
        return 'emergency';
    }

    public function obligations(array $siteIds, Carbon $start, Carbon $end): array
    {
        if ($siteIds === []) {
            return [];
        }

        $items = [];

        $plans = SiteEmergencyPlan::query()
            ->whereIn('site_id', $siteIds)
            ->where('status', 'active')
            ->with('site:id,name,type')
            ->get();

        foreach ($plans as $plan) {
            $due = $plan->dueDate();
            if (! $this->inRange($due, $start, $end)) {
                continue;
            }

            $name = $plan->title ?: Str::headline($plan->plan_type ?: 'Emergency plan');

            $items[] = new CalendarItem(
                id: "emergency-{$plan->id}",
                source: 'emergency',
                group: 'auto',
                title: $name.' — review due',
                start: $this->isoDate($due),
                allDay: true,
                status: $this->dueStatus($due, false),
                ref: 'EMG-'.$plan->id,
                site: $this->siteArray($plan->site),
                link: "/sites/{$plan->site_id}?tab=compliance",
            );
        }

        return $items;
    }
}
