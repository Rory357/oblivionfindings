<?php

namespace App\Support\HealthSafety;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Overdue checks skip work whose H&S event was soft-deleted (MASTER for Stephan, 30 Oct): a deleted event is hidden
 * everywhere else, so its open actions and investigations must not keep raising daily Control Room alerts.
 * Only a soft-deleted event excludes an item. An item with no event, or whose event row is missing outright, still
 * alerts, so a broken link can never silently hide real work.
 */
final class RetiredEventItems
{
    public static function exclude(Builder $items, string $itemTable): Builder
    {
        return $items->whereNotExists(fn (QueryBuilder $event) => $event->selectRaw('1')
            ->from('hs_events as retired_hs_event')
            ->whereColumn('retired_hs_event.id', $itemTable.'.hs_event_id')
            ->whereNotNull('retired_hs_event.deleted_at'));
    }
}
