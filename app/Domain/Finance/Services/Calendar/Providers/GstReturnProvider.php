<?php

namespace App\Domain\Finance\Services\Calendar\Providers;

use App\Domain\Finance\Models\FinGstReturn;
use App\Domain\Finance\Services\Calendar\FinanceCalendarItem;
use Illuminate\Support\Carbon;

/**
 * Surfaces GST return filing/payment deadlines onto the finance calendar. The
 * deadline is computed from the taxable period end using IRD's rule: returns and
 * payment are due by the 28th of the month following the period end, with two
 * holiday concessions — the period ending 30 November is due 15 January, and the
 * period ending 31 March is due 7 May.
 *
 * Reads the SAME {@see FinGstReturn} rows the Tax hub renders. Because the due
 * date is derived (not stored), this provider over-fetches by period_end and
 * filters on the computed deadline.
 */
class GstReturnProvider extends ObligationProvider
{
    public function sourceKey(): string
    {
        return 'gst_due';
    }

    public function obligations(?int $orgId, Carbon $start, Carbon $end): array
    {
        // A due date is at most ~2 months after the period end, so widen the
        // period_end fetch window to be sure we catch every deadline in range.
        $fetchFrom = $start->copy()->subMonths(3)->toDateString();
        $fetchTo = $end->copy()->toDateString();

        return FinGstReturn::query()
            ->forOrganization($orgId)
            ->whereNotNull('period_end')
            ->whereBetween('period_end', [$fetchFrom, $fetchTo])
            ->orderBy('period_end')
            ->get()
            ->map(function (FinGstReturn $return) {
                $due = $this->gstDueDate(Carbon::parse($return->period_end));
                $payable = (float) $return->gst_payable;

                return [$return, $due, $payable];
            })
            ->filter(fn ($t) => $t[1]->between($start->copy()->startOfDay(), $end->copy()->endOfDay(), true))
            ->map(function ($t) {
                [$return, $due, $payable] = $t;
                $periodLabel = Carbon::parse($return->period_start)->format('M').'–'.Carbon::parse($return->period_end)->format('M Y');

                return new FinanceCalendarItem(
                    id: "gst-{$return->id}",
                    source: 'gst_due',
                    title: "GST return — {$periodLabel}",
                    start: $this->isoDate($due),
                    status: $this->dueStatus($due, $return->status !== 'draft', 'filed'),
                    amount: $payable,
                    direction: $payable < 0 ? 'inflow' : 'outflow',
                    ref: $return->ird_period,
                    link: route('finance.gst-returns.show', $return->id, false),
                    meta: [
                        'return_status' => $return->status,
                        'filing_frequency' => $return->filing_frequency,
                        'period_start' => $this->isoDate($return->period_start),
                        'period_end' => $this->isoDate($return->period_end),
                    ],
                );
            })
            ->values()
            ->all();
    }

    /**
     * IRD GST deadline for a taxable period: the 28th of the month after the
     * period end, except Nov→15 Jan and Mar→7 May (holiday concessions).
     */
    private function gstDueDate(Carbon $periodEnd): Carbon
    {
        $month = (int) $periodEnd->month;
        $year = (int) $periodEnd->year;

        if ($month === 11) {
            return Carbon::create($year + 1, 1, 15)->startOfDay();
        }

        if ($month === 3) {
            return Carbon::create($year, 5, 7)->startOfDay();
        }

        return $periodEnd->copy()->startOfMonth()->addMonth()->day(28)->startOfDay();
    }
}
