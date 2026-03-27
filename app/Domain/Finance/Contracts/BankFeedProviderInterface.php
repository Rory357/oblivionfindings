<?php

namespace App\Domain\Finance\Contracts;

use App\Domain\Finance\Models\FinBankFeed;

interface BankFeedProviderInterface
{
    /**
     * Fetch transactions from the bank feed provider.
     *
     * @return array<int, array{external_id: string, date: string, description: string, reference: string|null, amount: float}>
     */
    public function fetchTransactions(FinBankFeed $feed, string $fromDate, string $toDate): array;

    /**
     * Initiate the consent/authorisation flow with the bank.
     *
     * @return string The redirect URL for the consent flow.
     */
    public function initiateConsent(FinBankFeed $feed): string;

    /**
     * Handle the callback from the bank's consent flow.
     *
     * @param  array<string, mixed>  $params
     */
    public function handleConsentCallback(FinBankFeed $feed, array $params): void;

    /**
     * Revoke the consent/authorisation with the bank.
     */
    public function revokeConsent(FinBankFeed $feed): void;

    /**
     * Get the display name of this bank feed provider.
     */
    public function getProviderName(): string;
}
