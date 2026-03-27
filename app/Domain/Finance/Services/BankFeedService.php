<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinBankFeed;
use App\Domain\Finance\Models\FinBankFeedLog;
use App\Domain\Finance\Models\FinBankTransaction;
use Illuminate\Support\Facades\Log;

class BankFeedService
{
    public function __construct(
        private readonly BankFeedProviderFactory $providerFactory,
    ) {}

    public function syncFeed(FinBankFeed $feed): FinBankFeedLog
    {
        $startTime = now();
        $provider = $this->providerFactory->make($feed->provider);

        try {
            $fromDate = $feed->last_sync_at?->toDateString()
                ?? $feed->sync_from_date?->toDateString()
                ?? now()->subDays(30)->toDateString();

            $toDate = now()->toDateString();

            $transactions = $provider->fetchTransactions($feed, $fromDate, $toDate);

            $imported = 0;
            $skipped = 0;
            $fetched = count($transactions);

            foreach ($transactions as $txn) {
                $exists = FinBankTransaction::where('bank_account_id', $feed->bank_account_id)
                    ->where('external_id', $txn['external_id'])
                    ->exists();

                if ($exists) {
                    $skipped++;

                    continue;
                }

                FinBankTransaction::create([
                    'organization_id' => $feed->organization_id,
                    'bank_account_id' => $feed->bank_account_id,
                    'transaction_date' => $txn['date'],
                    'description' => $txn['description'],
                    'reference' => $txn['reference'] ?? null,
                    'amount' => $txn['amount'],
                    'source' => 'feed',
                    'bank_feed_id' => $feed->id,
                    'external_id' => $txn['external_id'],
                    'is_from_feed' => true,
                    'status' => 'unreconciled',
                ]);

                $imported++;
            }

            $durationMs = (int) now()->diffInMilliseconds($startTime);

            $status = $fetched > 0 && $skipped > 0 && $imported > 0 ? 'partial' : 'success';

            $log = $feed->logs()->create([
                'synced_at' => now(),
                'status' => $status,
                'transactions_fetched' => $fetched,
                'transactions_imported' => $imported,
                'transactions_skipped' => $skipped,
                'error_message' => null,
                'duration_ms' => $durationMs,
            ]);

            $feed->update([
                'last_sync_at' => now(),
                'last_sync_status' => 'success',
                'last_error' => null,
            ]);

            Log::info("Bank feed sync completed for feed #{$feed->id}.", [
                'provider' => $feed->provider,
                'fetched' => $fetched,
                'imported' => $imported,
                'skipped' => $skipped,
                'duration_ms' => $durationMs,
            ]);

            return $log;
        } catch (\Throwable $e) {
            $durationMs = (int) now()->diffInMilliseconds($startTime);

            $log = $feed->logs()->create([
                'synced_at' => now(),
                'status' => 'failed',
                'transactions_fetched' => 0,
                'transactions_imported' => 0,
                'transactions_skipped' => 0,
                'error_message' => $e->getMessage(),
                'duration_ms' => $durationMs,
            ]);

            $feed->update([
                'last_sync_status' => 'failed',
                'last_error' => $e->getMessage(),
            ]);

            Log::error("Bank feed sync failed for feed #{$feed->id}.", [
                'provider' => $feed->provider,
                'error' => $e->getMessage(),
                'duration_ms' => $durationMs,
            ]);

            return $log;
        }
    }

    /**
     * Sync all active feeds, optionally filtered by organisation.
     *
     * @return array<int, FinBankFeedLog>
     */
    public function syncAllActive(?int $orgId = null): array
    {
        $query = FinBankFeed::active();

        if ($orgId !== null) {
            $query->forOrganization($orgId);
        }

        $feeds = $query->get();
        $logs = [];

        foreach ($feeds as $feed) {
            $logs[] = $this->syncFeed($feed);
        }

        return $logs;
    }
}
