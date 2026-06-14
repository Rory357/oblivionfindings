<?php

namespace App\Domain\Finance\Services\Calendar\Contracts;

use App\Domain\Finance\Services\Calendar\FinanceCalendarItem;
use Illuminate\Support\Carbon;

/**
 * A source of dated finance obligations (invoices/bills falling due, scheduled
 * payment runs, GST filing deadlines). Each provider reads dated rows from one
 * Finance module and normalises them to read-only
 * {@see FinanceCalendarItem}s — it never writes calendar events.
 */
interface FinanceObligationProvider
{
    /**
     * Stable source key used for colour-coding, filtering and the legend.
     */
    public function sourceKey(): string;

    /**
     * Dated obligations for the organisation intersecting [$start, $end].
     *
     * @return FinanceCalendarItem[]
     */
    public function obligations(?int $orgId, Carbon $start, Carbon $end): array;
}
