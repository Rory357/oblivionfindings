<?php

namespace App\Services\Sites\Calendar\Providers;

use App\Models\Asset;
use App\Services\Sites\Calendar\CalendarItem;
use Illuminate\Support\Carbon;

/**
 * Fleet & asset maintenance obligations — vehicle WOF / registration / CoF and
 * general asset inspection / maintenance / warranty dates, pulled from the Asset
 * register so they surface on the Site Calendar without re-entry.
 */
class AssetMaintenanceObligationProvider extends ObligationProvider
{
    /**
     * Date column => human label. Each non-null date that falls in range becomes
     * its own obligation occurrence.
     *
     * @var array<string, string>
     */
    private const DATE_FIELDS = [
        'wof_expires_at' => 'WOF expires',
        'registration_expires_at' => 'Registration expires',
        'cof_expires_at' => 'CoF expires',
        'inspection_due_at' => 'Inspection due',
        'maintenance_due_at' => 'Maintenance due',
        'warranty_expires_at' => 'Warranty expires',
    ];

    public function sourceKey(): string
    {
        return 'asset';
    }

    public function obligations(array $siteIds, Carbon $start, Carbon $end): array
    {
        if ($siteIds === []) {
            return [];
        }

        $items = [];

        $assets = Asset::query()
            ->whereIn('site_id', $siteIds)
            // Don't nag about disposed/retired kit; null status stays included.
            ->where(function ($q) {
                $q->whereNull('status')
                    ->orWhereNotIn('status', ['disposed', 'retired', 'sold']);
            })
            ->with('site:id,name,type')
            ->get();

        foreach ($assets as $asset) {
            $label = $asset->name ?: ($asset->asset_tag ?: 'Asset');

            foreach (self::DATE_FIELDS as $field => $suffix) {
                $due = $asset->{$field};
                if (! $due instanceof Carbon || ! $this->inRange($due, $start, $end)) {
                    continue;
                }

                $items[] = new CalendarItem(
                    id: "asset-{$asset->id}-{$field}",
                    source: 'asset',
                    group: 'auto',
                    title: $label.' — '.$suffix,
                    start: $this->isoDate($due),
                    allDay: true,
                    status: $this->dueStatus($due, false),
                    ref: $asset->asset_tag,
                    site: $this->siteArray($asset->site),
                    link: "/assets/{$asset->id}",
                );
            }
        }

        return $items;
    }
}
