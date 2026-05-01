<?php

namespace App\Domain\Finance\Jobs;

use App\Domain\Finance\Services\ClientFundJournalService;
use App\Models\ClientFundTransaction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PostClientFundJournalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly ClientFundTransaction $transaction,
    ) {}

    public function handle(ClientFundJournalService $service): void
    {
        $this->transaction->refresh();

        if ($this->transaction->journal_id !== null) {
            return;
        }

        $journal = $service->postClientFundJournal($this->transaction);

        Log::info("Posted client fund transaction #{$this->transaction->id} to journal {$journal->journal_number}.");
    }
}
