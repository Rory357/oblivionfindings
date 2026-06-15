<?php

namespace App\Domain\Finance\Services\Calendar\Providers;

use App\Domain\Finance\Models\FinFiscalPeriod;
use App\Domain\Finance\Services\Calendar\FinanceCalendarItem;
use Illuminate\Support\Carbon;

/**
 * Surfaces fiscal-period close dates onto the finance calendar on each period's
 * end date — the obligation to close the books. Reads the SAME
 * {@see FinFiscalPeriod} rows the Ledger hub renders. Closed periods are marked
 * processed; open periods are still due.
 */
class PeriodCloseProvider extends ObligationProvider
{
    public function sourceKey(): string
    {
        return 'period_close';
    }

    public function obligations(?int $orgId, Carbon $start, Carbon $end): array
    {
        return FinFiscalPeriod::query()
            ->forOrganization($orgId)
            ->whereNotNull('end_date')
            ->whereBetween('end_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('end_date')
            ->get()
            ->map(function (FinFiscalPeriod $period) {
                $date = Carbon::parse($period->end_date);

                return new FinanceCalendarItem(
                    id: "period-close-{$period->id}",
                    source: 'period_close',
                    title: "Period close — {$period->name}",
                    start: $this->isoDate($date),
                    status: $period->status === 'closed' ? 'processed' : 'due',
                    amount: null,
                    direction: null,
                    ref: $period->name,
                    link: route('finance.fiscal-periods.index', [], false),
                    meta: ['period_status' => $period->status],
                );
            })
            ->all();
    }
}
