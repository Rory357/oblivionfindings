<?php

namespace App\Services\Sites\Calendar\Providers;

use App\Models\FleetServiceSchedule;
use App\Services\Sites\Calendar\CalendarItem;
use Illuminate\Support\Carbon;

/**
 * Recurring fleet service plans (fleet_service_schedules) — e.g. "10,000 km
 * service" — surfaced by their next_due_at so scheduled servicing shows on
 * the Site Calendar alongside the WOF/rego/CoF dates the sibling
 * AssetMaintenanceObligationProvider already pulls from the Asset register.
 *
 * Shares the 'asset' source key (legend: "Fleet / asset") so it rides the
 * existing calendar layer, filter and colour token — no new taxonomy entry.
 */
class FleetServiceScheduleObligationProvider extends ObligationProvider
{
    public function sourceKey(): string
    {
        return 'asset';
    }

    public function obligations(array $siteIds, Carbon $start, Carbon $end): array
    {
        if ($siteIds === []) {
            return [];
        }

        $schedules = FleetServiceSchedule::query()
            ->where('is_active', true)
            ->whereNotNull('next_due_at')
            ->whereHas('asset', function ($q) use ($siteIds) {
                $q->whereIn('site_id', $siteIds)
                    // Don't nag about disposed/retired kit; null status stays included.
                    ->where(function ($q2) {
                        $q2->whereNull('status')
                            ->orWhereNotIn('status', ['disposed', 'retired', 'sold']);
                    });
            })
            ->with(['asset:id,name,asset_tag,site_id', 'asset.site:id,name,type'])
            ->get();

        $items = [];

        foreach ($schedules as $schedule) {
            $due = $schedule->next_due_at;
            if (! $due instanceof Carbon || ! $this->inRange($due, $start, $end)) {
                continue;
            }

            $assetLabel = $schedule->asset?->name
                ?: ($schedule->asset?->asset_tag ?: 'Asset');

            $items[] = new CalendarItem(
                id: "fleet-service-{$schedule->id}",
                source: 'asset',
                group: 'auto',
                title: 'Service due — '.$assetLabel.($schedule->name ? ' ('.$schedule->name.')' : ''),
                start: $this->isoDate($due),
                allDay: true,
                status: $this->dueStatus($due, false),
                ref: $schedule->asset?->asset_tag,
                site: $this->siteArray($schedule->asset?->site),
                link: '/fleet-assets/maintenance/schedules',
            );
        }

        return $items;
    }
}
