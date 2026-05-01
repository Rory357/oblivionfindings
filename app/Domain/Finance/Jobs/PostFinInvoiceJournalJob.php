<?php

namespace App\Domain\Finance\Jobs;

use App\Domain\Finance\Models\FinInvoice;
use App\Domain\Finance\Services\FinInvoiceJournalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PostFinInvoiceJournalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public readonly FinInvoice $invoice,
    ) {}

    public function handle(FinInvoiceJournalService $service): void
    {
        $invoice = $this->invoice->refresh();

        if ($invoice->journal_id !== null) {
            Log::info('FinInvoice journal posting skipped; invoice already has a journal.', [
                'invoice_id' => $invoice->id,
                'journal_id' => $invoice->journal_id,
            ]);

            return;
        }

        $journal = $service->postInvoiceJournal($invoice);

        Log::info('FinInvoice journal posted successfully.', [
            'invoice_id' => $invoice->id,
            'journal_id' => $journal->id,
            'journal_number' => $journal->journal_number,
        ]);
    }
}
