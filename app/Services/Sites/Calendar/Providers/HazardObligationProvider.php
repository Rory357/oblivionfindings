<?php

namespace App\Services\Sites\Calendar\Providers;

use App\Models\SiteHazard;
use App\Services\Sites\Calendar\CalendarItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class HazardObligationProvider extends ObligationProvider
{
    /**
     * Date columns that each represent a dated obligation on a hazard.
     */
    private const DATE_FIELDS = [
        'due_date' => 'action due',
        'review_date' => 'review',
        'control_review_date' => 'control review',
    ];

    public function sourceKey(): string
    {
        return 'hazard';
    }

    public function obligations(array $siteIds, Carbon $start, Carbon $end): array
    {
        if ($siteIds === []) {
            return [];
        }

        $hazards = SiteHazard::query()
            ->whereIn('site_id', $siteIds)
            ->where(function ($q) use ($start, $end) {
                foreach (array_keys(self::DATE_FIELDS) as $col) {
                    $q->orWhereBetween($col, [$start->toDateString(), $end->toDateString()]);
                }
            })
            ->with(['site:id,name,type', 'assignedTo:id,name'])
            ->get();

        $items = [];

        foreach ($hazards as $hazard) {
            $open = $hazard->isOpen();
            $label = Str::limit($hazard->description ?: ($hazard->reference_number ?: 'Hazard'), 48);

            foreach (self::DATE_FIELDS as $col => $kind) {
                $date = $hazard->{$col};
                if (! $this->inRange($date, $start, $end)) {
                    continue;
                }

                $items[] = new CalendarItem(
                    id: "hazard-{$hazard->id}-{$col}",
                    source: 'hazard',
                    group: 'auto',
                    title: Str::ucfirst($kind).': '.$label,
                    start: $this->isoDate($date),
                    allDay: true,
                    status: $open ? ($date->lt(Carbon::today()) ? 'overdue' : 'scheduled') : 'completed',
                    owner: $this->ownerArray($hazard->assignedTo),
                    ref: $hazard->reference_number,
                    site: $this->siteArray($hazard->site),
                    link: "/hazards/{$hazard->id}",
                    priority: in_array($hazard->risk_rating, ['high', 'extreme'], true) ? 'high' : null,
                );
            }
        }

        return $items;
    }
}
