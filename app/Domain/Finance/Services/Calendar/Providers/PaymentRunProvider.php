<?php

namespace App\Domain\Finance\Services\Calendar\Providers;

use App\Domain\Finance\Models\FinPaymentRun;
use App\Domain\Finance\Services\Calendar\FinanceCalendarItem;
use Illuminate\Support\Carbon;

/**
 * Surfaces scheduled payment runs onto the finance calendar on their
 * payment_date. Reads the SAME {@see FinPaymentRun} rows the Payables hub
 * renders. Cancelled runs are excluded; processed runs are marked complete.
 */
class PaymentRunProvider extends ObligationProvider
{
    public function sourceKey(): string
    {
        return 'payment_run';
    }

    public function obligations(?int $orgId, Carbon $start, Carbon $end): array
    {
        return FinPaymentRun::query()
            ->forOrganization($orgId)
            ->whereNotNull('payment_date')
            ->whereBetween('payment_date', [$start->toDateString(), $end->toDateString()])
            ->where('status', '!=', 'cancelled')
            ->orderBy('payment_date')
            ->get()
            ->map(function (FinPaymentRun $run) {
                $date = Carbon::parse($run->payment_date);

                return new FinanceCalendarItem(
                    id: "payment-run-{$run->id}",
                    source: 'payment_run',
                    title: "Payment run {$run->run_number}",
                    start: $this->isoDate($date),
                    status: $run->status === 'completed' ? 'processed' : 'scheduled',
                    amount: (float) $run->total_amount,
                    direction: 'outflow',
                    ref: $run->run_number,
                    link: route('finance.payment-runs.show', $run->id, false),
                    meta: [
                        'run_status' => $run->status,
                        'item_count' => (int) $run->item_count,
                    ],
                );
            })
            ->all();
    }
}
