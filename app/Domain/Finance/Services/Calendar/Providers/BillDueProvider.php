<?php

namespace App\Domain\Finance\Services\Calendar\Providers;

use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Services\Calendar\FinanceCalendarItem;
use Illuminate\Support\Carbon;

/**
 * Surfaces accounts-payable bill due dates onto the finance calendar. Reads the
 * SAME {@see FinBill} rows the Payables hub renders. A bill is "settled" once it
 * is fully paid (amount_paid >= total_amount); cancelled bills are excluded.
 */
class BillDueProvider extends ObligationProvider
{
    public function sourceKey(): string
    {
        return 'bill_due';
    }

    public function obligations(?int $orgId, Carbon $start, Carbon $end): array
    {
        return FinBill::query()
            ->forOrganization($orgId)
            ->with('vendor:id,name')
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [$start->toDateString(), $end->toDateString()])
            ->where('status', '!=', 'cancelled')
            ->orderBy('due_date')
            ->get()
            ->map(function (FinBill $bill) {
                $due = Carbon::parse($bill->due_date);
                $vendor = $bill->vendor?->name;
                $settled = (float) $bill->amount_paid >= (float) $bill->total_amount;

                return new FinanceCalendarItem(
                    id: "bill-{$bill->id}",
                    source: 'bill_due',
                    title: $vendor ? "Bill {$bill->bill_number} — {$vendor}" : "Bill {$bill->bill_number}",
                    start: $this->isoDate($due),
                    status: $this->dueStatus($due, $settled),
                    amount: $bill->getAmountDue(),
                    direction: 'outflow',
                    ref: $bill->bill_number,
                    counterparty: $vendor,
                    link: route('finance.bills.show', $bill->id, false),
                    meta: [
                        'bill_status' => $bill->status,
                        'total_amount' => (float) $bill->total_amount,
                        'amount_paid' => (float) $bill->amount_paid,
                    ],
                );
            })
            ->all();
    }
}
