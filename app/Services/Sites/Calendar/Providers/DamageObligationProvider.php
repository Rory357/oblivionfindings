<?php

namespace App\Services\Sites\Calendar\Providers;

use App\Models\SiteDamage;
use App\Services\Sites\Calendar\CalendarItem;
use Illuminate\Support\Carbon;

class DamageObligationProvider extends ObligationProvider
{
    public function sourceKey(): string
    {
        return 'damage';
    }

    public function obligations(array $siteIds, Carbon $start, Carbon $end): array
    {
        if ($siteIds === []) {
            return [];
        }

        return SiteDamage::query()
            ->whereIn('site_id', $siteIds)
            ->whereNotNull('discovered_date')
            ->whereBetween('discovered_date', [$start->toDateString(), $end->toDateString()])
            ->with(['site:id,name,type', 'assignedTo:id,name'])
            ->get()
            ->map(function (SiteDamage $damage) {
                $repaired = in_array($damage->status, ['repaired', 'closed'], true);
                $awaiting = in_array($damage->status, ['reported', 'assessed'], true);

                return new CalendarItem(
                    id: "damage-{$damage->id}",
                    source: 'damage',
                    group: 'auto',
                    title: $damage->title ?: 'Damage follow-up',
                    start: $this->isoDate($damage->discovered_date),
                    allDay: true,
                    status: $repaired ? 'completed' : ($awaiting ? 'pending' : 'scheduled'),
                    owner: $this->ownerArray($damage->assignedTo),
                    room: $damage->location_in_site,
                    ref: 'DMG-'.$damage->id,
                    site: $this->siteArray($damage->site),
                    link: "/sites/{$damage->site_id}/damages",
                    priority: $damage->severity === 'high' || $damage->severity === 'critical' ? 'high' : null,
                );
            })
            ->all();
    }
}
