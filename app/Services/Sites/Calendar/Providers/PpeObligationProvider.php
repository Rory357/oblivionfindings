<?php

namespace App\Services\Sites\Calendar\Providers;

use App\Models\PpeInventory;
use App\Services\Sites\Calendar\CalendarItem;
use Illuminate\Support\Carbon;

/**
 * PPE & Equipment obligations — inspection-due and expiry dates pulled from the PPE
 * inventory register so they surface on the Site Calendar without re-entry. One
 * register (/health-safety/ppe) stays the single write surface.
 */
class PpeObligationProvider extends ObligationProvider
{
    /**
     * Date column => human label. Each non-null date in range becomes its own occurrence.
     *
     * @var array<string, string>
     */
    private const DATE_FIELDS = [
        'next_inspection_due' => 'PPE inspection due',
        'expiry_date' => 'PPE expires',
    ];

    public function sourceKey(): string
    {
        return 'ppe';
    }

    public function obligations(array $siteIds, Carbon $start, Carbon $end): array
    {
        if ($siteIds === []) {
            return [];
        }

        $items = [];

        $inventory = PpeInventory::query()
            ->whereIn('site_id', $siteIds)
            ->whereNotIn('status', ['condemned', 'disposed'])
            ->with(['site:id,name,type', 'ppeType:id,name'])
            ->get();

        foreach ($inventory as $item) {
            $label = trim(($item->ppeType?->name ?? 'PPE item').($item->serial_number ? ' · '.$item->serial_number : ''));

            foreach (self::DATE_FIELDS as $field => $suffix) {
                $due = $item->{$field};
                if (! $due instanceof Carbon || ! $this->inRange($due, $start, $end)) {
                    continue;
                }

                $items[] = new CalendarItem(
                    id: "ppe-{$item->id}-{$field}",
                    source: 'ppe',
                    group: 'auto',
                    title: $label.' — '.$suffix,
                    start: $this->isoDate($due),
                    allDay: true,
                    status: $this->dueStatus($due, false),
                    ref: $item->serial_number,
                    site: $this->siteArray($item->site),
                    link: "/health-safety/ppe?item={$item->id}",
                );
            }
        }

        return $items;
    }
}
