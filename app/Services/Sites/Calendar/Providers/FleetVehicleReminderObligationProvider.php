<?php

namespace App\Services\Sites\Calendar\Providers;

use App\Models\FleetVehicleReminder;
use App\Services\Fleet\VehicleReminderAccess;
use App\Services\Sites\Calendar\CalendarItem;
use Illuminate\Support\Carbon;

/**
 * Open vehicle follow-ups (PKG-02B reminders) on the Site Calendar. A
 * reminder is a prompt for its owner, never a booking or an unavailable
 * period, so it rides the existing 'asset' layer without a new taxonomy entry.
 */
class FleetVehicleReminderObligationProvider extends ObligationProvider
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

        $reminders = app(VehicleReminderAccess::class)->scope(FleetVehicleReminder::query(), auth()->user())
            ->whereIn('state', ['scheduled', 'acknowledged'])
            ->whereBetween('due_at', [$start->copy()->utc(), $end->copy()->utc()])
            ->whereHas('asset', fn ($asset) => $asset->whereIn('site_id', $siteIds))
            ->with(['asset:id,name,asset_tag,site_id', 'asset.site:id,name,type', 'owner:id,name'])
            ->orderBy('due_at')
            ->limit(500)
            ->get();

        return $reminders->map(fn (FleetVehicleReminder $reminder): CalendarItem => new CalendarItem(
            id: "fleet-reminder-{$reminder->id}",
            source: 'asset',
            group: 'auto',
            title: $reminder->title.' — '.($reminder->asset?->name ?: 'Vehicle'),
            start: $reminder->due_at->toIso8601String(),
            allDay: false,
            status: $this->dueStatus($reminder->due_at, false),
            owner: $this->ownerArray($reminder->owner),
            ref: $reminder->asset?->asset_tag,
            site: $this->siteArray($reminder->asset?->site),
            link: "/fleet-assets/vehicles/{$reminder->asset_id}?tab=service&view=reminders",
        ))->all();
    }
}
