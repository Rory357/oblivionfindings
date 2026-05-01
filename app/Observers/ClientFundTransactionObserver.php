<?php

namespace App\Observers;

use App\Domain\Finance\Jobs\PostClientFundJournalJob;
use App\Models\ClientFundTransaction;
use Illuminate\Support\Facades\Log;

class ClientFundTransactionObserver
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
            Log::error("ClientFundTransactionObserver: Failed to dispatch GL job for transaction #{$transaction->id}: {$e->getMessage()}");
        }
    }
}
