<?php

namespace App\Services\Sites\Calendar\Providers;

use App\Models\SiteChecklistRun;
use App\Services\Sites\Calendar\CalendarItem;
use Illuminate\Support\Carbon;

class ChecklistObligationProvider extends ObligationProvider
{
    public function sourceKey(): string
    {
        return 'checklist';
    }

    public function obligations(array $siteIds, Carbon $start, Carbon $end): array
    {
        if ($siteIds === []) {
            return [];
        }

        return SiteChecklistRun::query()
            ->whereIn('site_id', $siteIds)
            ->whereNotNull('scheduled_date')
            ->whereBetween('scheduled_date', [$start->toDateString(), $end->toDateString()])
            ->with(['site:id,name,type', 'template:id,name'])
            ->get()
            ->map(fn (SiteChecklistRun $run) => new CalendarItem(
                id: "checklist-{$run->id}",
                source: 'checklist',
                group: 'auto',
                title: $run->template?->name ?: 'Checklist run',
                start: $this->isoDate($run->scheduled_date),
                allDay: true,
                status: $run->status === 'completed'
                    ? 'completed'
                    : ($run->isOverdue() ? 'overdue' : 'scheduled'),
                ref: 'CHK-'.$run->id,
                site: $this->siteArray($run->site),
                link: "/checklists/runs/{$run->id}",
            ))
            ->all();
    }
}
