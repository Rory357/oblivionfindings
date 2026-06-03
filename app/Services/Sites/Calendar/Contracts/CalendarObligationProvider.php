<?php

namespace App\Services\Sites\Calendar\Contracts;

use App\Services\Sites\Calendar\CalendarItem;
use Illuminate\Support\Carbon;

/**
 * A source of auto-derived calendar obligations (inspections due, certificates
 * expiring, meals planned, …). Each provider reads dated rows from one existing
 * Sites module and normalises them to read-only {@see CalendarItem}s — it never
 * writes calendar events.
 */
interface CalendarObligationProvider
{
    /**
     * Stable source key used for colour-coding, filtering and the legend
     * (matches the keys in {@see \App\Services\Sites\Calendar\CalendarSources}).
     */
    public function sourceKey(): string;

    /**
     * Dated obligations for the given sites intersecting [$start, $end].
     *
     * @param  int[]  $siteIds
     * @return CalendarItem[]
     */
    public function obligations(array $siteIds, Carbon $start, Carbon $end): array;
}
