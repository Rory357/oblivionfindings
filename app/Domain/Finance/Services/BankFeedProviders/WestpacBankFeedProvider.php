<?php

namespace App\Domain\Finance\Services\BankFeedProviders;

use App\Domain\Finance\Contracts\BankFeedProviderInterface;
use App\Domain\Finance\Models\FinBankFeed;
use RuntimeException;

class WestpacBankFeedProvider implements BankFeedProviderInterface
{
    public function fetchTransactions(FinBankFeed $feed, string $fromDate, string $toDate): array
    {
        throw new RuntimeException(
            'Westpac bank-feed API import is not yet supported. Use the bank transaction CSV import workflow.'
        );
    }

    public function initiateConsent(FinBankFeed $feed): string
    {
        $clientId = config('services.bank_feeds.westpac.client_id');

        if (empty($clientId)) {
            throw new \RuntimeException('API credentials not configured for Westpac. Configure in finance settings.');
        }

        $redirectUri = config('services.bank_feeds.westpac.redirect_uri');
        $scope = config('services.bank_feeds.westpac.scope', 'accounts transactions');

        return sprintf(
            '%s/oauth2/authorize?client_id=%s&redirect_uri=%s&scope=%s&response_type=code&state=%s',
            config('services.bank_feeds.westpac.base_url', 'https://api.westpac.co.nz'),
            urlencode($clientId),
            urlencode($redirectUri),
            urlencode($scope),
            urlencode((string) $feed->id),
        );
    }

    public function handleConsentCallback(FinBankFeed $feed, array $params): void
    {
        $clientId = config('services.bank_feeds.westpac.client_id');

        if (empty($clientId)) {
            throw new \RuntimeException('API credentials not configured for Westpac. Configure in finance settings.');
        }

        $feed->update([
            'consent_token' => $params['code'] ?? null,
            'consent_expires_at' => now()->addDays(90),
        ]);
    }

    public function revokeConsent(FinBankFeed $feed): void
    {
        $clientId = config('services.bank_feeds.westpac.client_id');

        if (empty($clientId)) {
            throw new \RuntimeException('API credentials not configured for Westpac. Configure in finance settings.');
        }

        $feed->update([
            'consent_token' => null,
            'consent_expires_at' => null,
        ]);
    }

    public function getProviderName(): string
    {
        return 'Westpac';
    }
}
