<?php

namespace App\Domain\Finance\Services\BankFeedProviders;

use App\Domain\Finance\Contracts\BankFeedProviderInterface;
use App\Domain\Finance\Models\FinBankFeed;
use Illuminate\Support\Facades\Log;

class BnzBankFeedProvider implements BankFeedProviderInterface
{
    public function fetchTransactions(FinBankFeed $feed, string $fromDate, string $toDate): array
    {
        Log::info('BNZ bank feed integration pending. No transactions fetched.', [
            'bank_feed_id' => $feed->id,
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'client_id_configured' => ! empty(config('services.bank_feeds.bnz.client_id')),
        ]);

        return [];
    }

    public function initiateConsent(FinBankFeed $feed): string
    {
        $clientId = config('services.bank_feeds.bnz.client_id');

        if (empty($clientId)) {
            throw new \RuntimeException('API credentials not configured for BNZ. Configure in finance settings.');
        }

        $redirectUri = config('services.bank_feeds.bnz.redirect_uri');
        $scope = config('services.bank_feeds.bnz.scope', 'accounts transactions');

        return sprintf(
            '%s/oauth2/authorize?client_id=%s&redirect_uri=%s&scope=%s&response_type=code&state=%s',
            config('services.bank_feeds.bnz.base_url', 'https://api.bnz.co.nz'),
            urlencode($clientId),
            urlencode($redirectUri),
            urlencode($scope),
            urlencode((string) $feed->id),
        );
    }

    public function handleConsentCallback(FinBankFeed $feed, array $params): void
    {
        $clientId = config('services.bank_feeds.bnz.client_id');

        if (empty($clientId)) {
            throw new \RuntimeException('API credentials not configured for BNZ. Configure in finance settings.');
        }

        $feed->update([
            'consent_token' => $params['code'] ?? null,
            'consent_expires_at' => now()->addDays(90),
        ]);
    }

    public function revokeConsent(FinBankFeed $feed): void
    {
        $clientId = config('services.bank_feeds.bnz.client_id');

        if (empty($clientId)) {
            throw new \RuntimeException('API credentials not configured for BNZ. Configure in finance settings.');
        }

        $feed->update([
            'consent_token' => null,
            'consent_expires_at' => null,
        ]);
    }

    public function getProviderName(): string
    {
        return 'BNZ';
    }
}
