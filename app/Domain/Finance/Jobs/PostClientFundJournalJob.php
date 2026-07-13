<?php

namespace App\Domain\Finance\Jobs;

use App\Domain\Finance\Services\ClientFundJournalService;
use App\Models\ClientFundTransaction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PostClientFundJournalJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300, 600];

    public int $uniqueFor = 900;

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

    public function uniqueId(): string
    {
        return 'client-fund-transaction:'.$this->transaction->getKey();
    }

    public function failed(\Throwable $exception): void
    {
        Log::critical('Client fund transaction remains unposted after queue retries.', [
            'transaction_id' => $this->transaction->getKey(),
            'error' => $exception->getMessage(),
            'recovery' => ReconcileUnpostedClientFundJournalsJob::class,
        ]);
    }
}
