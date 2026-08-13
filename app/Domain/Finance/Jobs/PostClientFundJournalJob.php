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
use Illuminate\Support\Str;

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

        if ($this->transaction->journal_id !== null || $this->transaction->status === 'posted') {
            return;
        }

        if ($this->transaction->status !== 'approved'
            || $this->transaction->source_type === 'client_fund_transfer_counterpart') {
            return;
        }

        try {
            $journal = $service->postClientFundJournal($this->transaction);
        } catch (\Throwable $exception) {
            ClientFundTransaction::query()->whereKey($this->transaction->id)->update([
                'posting_attempted_at' => now(),
                'posting_failed_at' => now(),
                'posting_failure_code' => class_basename($exception),
                'posting_failure_message' => Str::limit($exception->getMessage(), 1000, ''),
            ]);

            throw $exception;
        }

        Log::info("Posted client fund transaction #{$this->transaction->id} to journal {$journal->journal_number}.");
    }

    public function uniqueId(): string
    {
        return 'client-fund-transaction:'.$this->transaction->getKey();
    }

    public function failed(\Throwable $exception): void
    {
        ClientFundTransaction::query()->whereKey($this->transaction->getKey())->update([
            'posting_failed_at' => now(),
            'posting_failure_code' => class_basename($exception),
            'posting_failure_message' => Str::limit($exception->getMessage(), 1000, ''),
        ]);

        Log::critical('Client fund transaction remains unposted after queue retries.', [
            'transaction_id' => $this->transaction->getKey(),
            'error' => $exception->getMessage(),
            'recovery' => ReconcileUnpostedClientFundJournalsJob::class,
        ]);
    }
}
