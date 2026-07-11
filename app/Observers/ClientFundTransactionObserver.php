<?php

namespace App\Observers;

use App\Domain\Finance\Jobs\PostClientFundJournalJob;
use App\Domain\Finance\Jobs\ReconcileUnpostedClientFundJournalsJob;
use App\Models\ClientFundTransaction;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Log;

class ClientFundTransactionObserver implements ShouldHandleEventsAfterCommit
{
    public function created(ClientFundTransaction $transaction): void
    {
        if ($transaction->journal_id !== null) {
            return;
        }

        if (! $transaction->organization_id) {
            return;
        }

        if (bccomp((string) $transaction->amount, '0', 2) === 0) {
            return;
        }

        try {
            PostClientFundJournalJob::dispatch($transaction);
        } catch (\Throwable $e) {
            // The committed transaction row (journal_id = null) is the durable
            // recovery record. The scheduled reconciler will redispatch it.
            Log::critical('Failed to dispatch client fund GL job; recovery sweep required.', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
                'recovery' => ReconcileUnpostedClientFundJournalsJob::class,
            ]);
        }
    }
}
