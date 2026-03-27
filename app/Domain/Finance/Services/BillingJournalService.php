<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinJournal;
use App\Models\Invoice;
use InvalidArgumentException;
use RuntimeException;

class BillingJournalService
{
    /**
     * GL account code -> FinAccount cache (per-request, keyed by orgId:code).
     *
     * @var array<string, FinAccount>
     */
    private array $accountCache = [];

    public function __construct(
        private readonly JournalPostingService $journalPostingService,
    ) {}

    /* ------------------------------------------------------------------
     |  Post an invoice to the General Ledger
     | ------------------------------------------------------------------ */

    public function postInvoiceJournal(Invoice $invoice): FinJournal
    {
        if ($invoice->journal_id !== null) {
            throw new InvalidArgumentException(
                "Invoice #{$invoice->id} ({$invoice->invoice_number}) has already been posted to journal #{$invoice->journal_id}."
            );
        }

        $orgId = $invoice->organization_id;

        $lines = [];

        // DR 1100 Accounts Receivable: total_amount
        if (bccomp((string) $invoice->total_amount, '0', 2) > 0) {
            $lines[] = [
                'account_id'  => $this->findAccountByCode($orgId, '1100')->id,
                'description' => 'Accounts Receivable',
                'debit'       => $invoice->total_amount,
                'credit'      => 0,
            ];
        }

        // CR 4030 Private Pay Revenue: subtotal
        if (bccomp((string) $invoice->subtotal, '0', 2) > 0) {
            $lines[] = [
                'account_id'  => $this->findAccountByCode($orgId, '4030')->id,
                'description' => 'Private Pay Revenue',
                'debit'       => 0,
                'credit'      => $invoice->subtotal,
            ];
        }

        // CR 2200 GST Collected: tax_amount
        if (bccomp((string) $invoice->tax_amount, '0', 2) > 0) {
            $lines[] = [
                'account_id'  => $this->findAccountByCode($orgId, '2200')->id,
                'description' => 'GST Collected',
                'debit'       => 0,
                'credit'      => $invoice->tax_amount,
            ];
        }

        if (count($lines) < 2) {
            throw new RuntimeException(
                "Invoice #{$invoice->id} ({$invoice->invoice_number}) produced fewer than 2 journal lines. Cannot post."
            );
        }

        $journal = $this->journalPostingService->createAndPost($orgId, [
            'journal_date' => $invoice->issue_date->toDateString(),
            'type'         => 'billing',
            'source_type'  => 'invoice',
            'source_id'    => $invoice->id,
            'description'  => "Invoice {$invoice->invoice_number}",
            'lines'        => $lines,
        ]);

        $invoice->update([
            'journal_id'   => $journal->id,
            'gl_posted_at' => now(),
        ]);

        return $journal;
    }

    /* ------------------------------------------------------------------
     |  Reverse a previously posted invoice journal
     | ------------------------------------------------------------------ */

    public function reverseInvoiceJournal(Invoice $invoice): ?FinJournal
    {
        if ($invoice->journal_id === null) {
            return null;
        }

        $journal = FinJournal::findOrFail($invoice->journal_id);

        $reversingJournal = $this->journalPostingService->reverse(
            $journal,
            "Reversal of invoice {$invoice->invoice_number}"
        );

        $invoice->update([
            'journal_id'   => null,
            'gl_posted_at' => null,
        ]);

        return $reversingJournal;
    }

    /* ------------------------------------------------------------------
     |  Helper: find a GL account by code (cached per request)
     | ------------------------------------------------------------------ */

    public function findAccountByCode(int $orgId, string $code): FinAccount
    {
        $cacheKey = "{$orgId}:{$code}";

        if (isset($this->accountCache[$cacheKey])) {
            return $this->accountCache[$cacheKey];
        }

        $account = FinAccount::where('organization_id', $orgId)
            ->where('code', $code)
            ->where('is_active', true)
            ->first();

        if (! $account) {
            throw new RuntimeException(
                "GL account with code '{$code}' not found (or inactive) for organisation #{$orgId}."
            );
        }

        $this->accountCache[$cacheKey] = $account;

        return $account;
    }
}
