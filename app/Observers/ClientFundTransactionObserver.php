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
        $this->dispatchApprovedTransaction($transaction);
    }

    public function updated(ClientFundTransaction $transaction): void
    {
        if (! $transaction->wasChanged('status') || $transaction->status !== 'approved') {
            return;
        }

        $this->dispatchApprovedTransaction($transaction);
    }

    private function dispatchApprovedTransaction(ClientFundTransaction $transaction): void
    {
        if ($transaction->status !== 'approved'
            || $transaction->balance_effect_applied_at === null
            || $transaction->journal_id !== null
            || $transaction->source_type === 'client_fund_transfer_counterpart') {
            return;
        }

        $hasCanonicalClientSite = ClientFundTransaction::query()
            ->whereKey($transaction->id)
            ->whereHas('fund.client', fn ($clientQuery) => $clientQuery
                ->whereColumn('clients.id', 'client_fund_transactions.client_id')
                ->whereColumn('clients.site_id', 'client_fund_transactions.site_id')
                ->whereNotNull('site_id')
                ->whereHas('site', fn ($siteQuery) => $siteQuery
                    ->active()
                    ->notArchived()
                    ->whereNull('archived_at')))
            ->exists();

        if (! $hasCanonicalClientSite) {
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
