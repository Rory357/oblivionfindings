<?php

namespace App\Services\Sites\Calendar\Providers;

use App\Models\SiteMealPlanEntry;
use App\Services\Sites\Calendar\CalendarItem;
use Illuminate\Support\Carbon;

/**
 * Surfaces planned meals from the Meal Planner read-only on the calendar
 * (plan_date + meal_slot → a timed entry), deep-linking to the site's
 * Meal Planner tab.
 */
class MealPlanObligationProvider extends ObligationProvider
{
    /** Default time-of-day per meal slot. */
    private const SLOT_TIMES = [
        'breakfast' => '08:00',
        'morning_tea' => '10:30',
        'lunch' => '12:30',
        'afternoon_tea' => '15:00',
        'dinner' => '17:30',
        'supper' => '20:00',
    ];

    private const SLOT_LABELS = [
        'breakfast' => 'Breakfast',
        'morning_tea' => 'Morning tea',
        'lunch' => 'Lunch',
        'afternoon_tea' => 'Afternoon tea',
        'dinner' => 'Dinner',
        'supper' => 'Supper',
    ];

    public function sourceKey(): string
    {
        return 'meal';
    }

    public function obligations(array $siteIds, Carbon $start, Carbon $end): array
    {
        if ($siteIds === []) {
            return [];
        }

        return SiteMealPlanEntry::query()
            ->whereIn('site_id', $siteIds)
            ->whereBetween('plan_date', [$start->toDateString(), $end->toDateString()])
            ->with(['site:id,name,type', 'recipe:id,name'])
            ->get()
            ->map(function (SiteMealPlanEntry $entry) {
                $time = self::SLOT_TIMES[$entry->meal_slot] ?? '12:00';
                $startAt = $entry->plan_date->copy()->setTimeFromTimeString($time);
                $slotLabel = self::SLOT_LABELS[$entry->meal_slot] ?? ucfirst((string) $entry->meal_slot);

                return new CalendarItem(
                    id: "meal-{$entry->id}",
                    source: 'meal',
                    group: 'auto',
                    title: $slotLabel.' — '.$entry->displayName(),
                    start: $startAt->toIso8601String(),
                    end: $startAt->copy()->addHour()->toIso8601String(),
                    allDay: false,
                    status: $entry->served_at ? 'completed' : 'approved',
                    room: 'Kitchen',
                    ref: 'MEAL-'.$entry->id,
                    site: $this->siteArray($entry->site),
                    link: "/sites/{$entry->site_id}?tab=meal-planner",
                );
            })
            ->all();
    }
}
