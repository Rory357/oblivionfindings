<?php

namespace App\Domain\Finance\Services\Calendar\Providers;

use App\Domain\Finance\Models\FinInvoice;
use App\Domain\Finance\Services\Calendar\FinanceCalendarItem;
use Illuminate\Support\Carbon;

/**
 * Surfaces accounts-receivable invoice due dates onto the finance calendar.
 * Reads the SAME {@see FinInvoice} rows the Receivables hub renders, so the two
 * surfaces share one source of truth. Cancelled invoices are excluded; paid
 * invoices remain visible (marked complete) so a month shows what was settled.
 */
class InvoiceDueProvider extends ObligationProvider
{
    public function sourceKey(): string
    {
        return 'invoice_due';
    }

    public function obligations(?int $orgId, Carbon $start, Carbon $end): array
    {
        return FinInvoice::query()
            ->forOrganization($orgId)
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [$start->toDateString(), $end->toDateString()])
            ->where('status', '!=', 'cancelled')
            ->orderBy('due_date')
            ->get()
            ->map(function (FinInvoice $invoice) {
                $due = Carbon::parse($invoice->due_date);
                $name = $invoice->client_name;

                return new FinanceCalendarItem(
                    id: "invoice-{$invoice->id}",
                    source: 'invoice_due',
                    title: $name ? "Invoice {$invoice->invoice_number} — {$name}" : "Invoice {$invoice->invoice_number}",
                    start: $this->isoDate($due),
                    status: $this->dueStatus($due, $invoice->status === 'paid'),
                    amount: (float) $invoice->total_amount,
                    direction: 'inflow',
                    ref: $invoice->invoice_number,
                    counterparty: $name,
                    link: route('finance.invoices.show', $invoice->id, false),
                    meta: ['invoice_status' => $invoice->status],
                );
            })
            ->all();
    }
}
