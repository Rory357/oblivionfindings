<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinInvoice;
use App\Domain\Finance\Models\FinJournal;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FinInvoiceJournalService
{
    /**
     * GL account code -> FinAccount cache, keyed by orgId:code.
     *
     * @var array<string, FinAccount>
     */
    private array $accountCache = [];

    public function __construct(
        private readonly JournalPostingService $journalPostingService,
        private readonly GstTaxRateResolver $gstTaxRateResolver,
    ) {}

    public function postInvoiceJournal(FinInvoice $invoice): FinJournal
    {
        return DB::transaction(function () use ($invoice) {
            $invoice = FinInvoice::query()
                ->with('lines')
                ->lockForUpdate()
                ->findOrFail($invoice->id);

            if ($invoice->journal_id !== null) {
                return $invoice->journal()->firstOrFail();
            }

            $orgId = $invoice->organization_id;
            $lines = [];

            if (bccomp((string) $invoice->total_amount, '0', 2) > 0) {
                $lines[] = [
                    'account_id' => $this->findAccountByCode($orgId, '1100')->id,
                    'description' => "Accounts Receivable - {$invoice->invoice_number}",
                    'debit' => $invoice->total_amount,
                    'credit' => 0,
                ];
            }

            foreach ($invoice->lines as $invoiceLine) {
                $lineRevenue = bcsub((string) $invoiceLine->line_total, (string) $invoiceLine->tax_amount, 2);

                if (bccomp($lineRevenue, '0', 2) <= 0) {
                    continue;
                }

                $taxRate = $this->gstTaxRateResolver->resolveInvoiceRate(
                    (int) $orgId,
                    $invoiceLine->tax_rate_id === null ? null : (int) $invoiceLine->tax_rate_id,
                    (string) $invoiceLine->tax_amount,
                    "Invoice {$invoice->invoice_number} line #{$invoiceLine->id}",
                );

                $lines[] = [
                    'account_id' => $invoiceLine->account_id
                        ?: $this->findAccountByCode($orgId, '4030')->id,
                    'description' => $invoiceLine->description,
                    'debit' => 0,
                    'credit' => $lineRevenue,
                    // Carries funder attribution into the GL, where the
                    // funding-stream summary report reads it.
                    'funding_stream_id' => $invoiceLine->funding_stream_id,
                    'tax_rate_id' => $taxRate?->id,
                    'tax_amount' => $invoiceLine->tax_amount,
                ];
            }

            if (bccomp((string) $invoice->tax_amount, '0', 2) > 0) {
                $lines[] = [
                    'account_id' => $this->findAccountByCode($orgId, '2200')->id,
                    'description' => "GST Collected - {$invoice->invoice_number}",
                    'debit' => 0,
                    'credit' => $invoice->tax_amount,
                ];
            }

            if (count($lines) < 2) {
                throw new RuntimeException(
                    "FinInvoice #{$invoice->id} ({$invoice->invoice_number}) produced fewer than 2 journal lines. Cannot post."
                );
            }

            $journal = $this->journalPostingService->createAndPost($orgId, [
                'journal_date' => $invoice->invoice_date->toDateString(),
                'type' => 'billing',
                'reference' => $invoice->invoice_number,
                'source_type' => FinInvoice::class,
                'source_id' => $invoice->id,
                'description' => "Invoice {$invoice->invoice_number}",
                'lines' => $lines,
            ]);

            $invoice->update([
                'journal_id' => $journal->id,
                'gl_posted_at' => now(),
            ]);

            return $journal;
        });
    }

    public function reverseInvoiceJournal(FinInvoice $invoice): ?FinJournal
    {
        return DB::transaction(function () use ($invoice) {
            $invoice = FinInvoice::query()
                ->lockForUpdate()
                ->findOrFail($invoice->id);

            if ($invoice->journal_id === null) {
                return null;
            }

            $journal = FinJournal::findOrFail($invoice->journal_id);

            if ($journal->reversed_by_journal_id !== null) {
                $invoice->update([
                    'journal_id' => null,
                    'gl_posted_at' => null,
                ]);

                return $journal->reversedByJournal()->first();
            }

            $reversingJournal = $this->journalPostingService->reverse(
                $journal,
                "Invoice {$invoice->invoice_number} cancelled"
            );

            $invoice->update([
                'journal_id' => null,
                'gl_posted_at' => null,
            ]);

            return $reversingJournal;
        });
    }

    public function findAccountByCode(?int $orgId, string $code): FinAccount
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
