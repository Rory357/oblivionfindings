<?php

namespace App\Services\Sites\Calendar\Providers;

use App\Models\EmergencyDrill;
use App\Services\Sites\Calendar\CalendarItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Scheduled emergency drills surfaced on the site calendar — so a site's drill
 * cadence sits alongside its emergency-plan reviews and other obligations. Each
 * still-scheduled drill emits one dated item deep-linking to the drills register's
 * detail modal (?drill=); a scheduled drill whose date has passed reads 'overdue'.
 */
class DrillObligationProvider extends ObligationProvider
{
    public function sourceKey(): string
    {
        return 'drill';
    }

    public function obligations(array $siteIds, Carbon $start, Carbon $end): array
    {
        if ($siteIds === []) {
            return [];
        }

        $items = [];

        $drills = EmergencyDrill::query()
            ->whereIn('site_id', $siteIds)
            ->where('status', 'scheduled')
            ->with('site:id,name,type')
            ->get();

        foreach ($drills as $drill) {
            $due = $drill->scheduled_at;
            if (! $this->inRange($due, $start, $end)) {
                continue;
            }

            $label = $drill->title ?: Str::headline($drill->drill_type ?: 'Emergency drill');

            $items[] = new CalendarItem(
                id: "drill-{$drill->id}",
                source: 'drill',
                group: 'auto',
                title: $label.' — drill',
                start: $this->isoDate($due),
                allDay: false,
                status: $this->dueStatus($due, false),
                ref: 'DR-'.$drill->id,
                site: $this->siteArray($drill->site),
                link: "/health-safety/drills?drill={$drill->id}",
            );
        }

        return $items;
    }
}
