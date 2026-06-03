<?php

namespace App\Services\Sites\Calendar\Providers;

use App\Models\SiteInspectionRecord;
use App\Services\Sites\Calendar\CalendarItem;
use Illuminate\Support\Carbon;

class InspectionObligationProvider extends ObligationProvider
{
    public function sourceKey(): string
    {
        return 'inspection';
    }

    public function obligations(array $siteIds, Carbon $start, Carbon $end): array
    {
        if ($siteIds === []) {
            return [];
        }

        return SiteInspectionRecord::query()
            ->whereIn('site_id', $siteIds)
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [$start->toDateString(), $end->toDateString()])
            ->with([
                'site:id,name,type',
                'schedule:id,title,inspection_type,assigned_to_user_id',
                'schedule.assignedTo:id,name',
            ])
            ->get()
            ->map(fn (SiteInspectionRecord $r) => new CalendarItem(
                id: "inspection-{$r->id}",
                source: 'inspection',
                group: 'auto',
                title: $r->schedule?->title ?: 'Inspection due',
                start: $this->isoDate($r->due_date),
                allDay: true,
                status: $this->dueStatus($r->due_date, $r->completed_at !== null),
                owner: $this->ownerArray($r->schedule?->assignedTo),
                ref: 'INS-'.$r->id,
                site: $this->siteArray($r->site),
                link: "/sites/{$r->site_id}/inspections",
            ))
            ->all();
    }
}
