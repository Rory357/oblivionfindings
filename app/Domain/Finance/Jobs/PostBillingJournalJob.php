<?php

namespace App\Domain\Finance\Jobs;

use App\Domain\Finance\Services\BillingJournalService;
use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PostBillingJournalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly Invoice $invoice,
    ) {}

    public function handle(BillingJournalService $service): void
    {
        $journal = $service->postInvoiceJournal($this->invoice);

        Log::info("Posted invoice #{$this->invoice->id} ({$this->invoice->invoice_number}) to journal {$journal->journal_number}.");
    }
}
