<?php

namespace App\Domain\Finance\Jobs;

use App\Models\ClientFundTransaction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Treat an unposted client-fund transaction as the durable recovery record.
 * The source transaction and balance are already committed atomically; this
 * sweep makes a transient queue-dispatch or posting failure recoverable.
 */
class ReconcileUnpostedClientFundJournalsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 180, 600];

    public int $uniqueFor = 240;

    public function handle(): void
    {
        $redispatched = 0;

        ClientFundTransaction::query()
            ->whereNull('journal_id')
            ->whereNotNull('organization_id')
            ->where('amount', '!=', 0)
            ->where('created_at', '<=', now()->subMinute())
            ->orderBy('id')
            ->chunkById(100, function ($transactions) use (&$redispatched): void {
                foreach ($transactions as $transaction) {
                    PostClientFundJournalJob::dispatch($transaction);
                    $redispatched++;
                }
            });

        if ($redispatched > 0) {
            Log::warning('Redispatched unposted client fund transactions.', [
                'count' => $redispatched,
            ]);
        }
    }

    public function uniqueId(): string
    {
        return 'client-fund-journal-reconciliation';
    }
}
