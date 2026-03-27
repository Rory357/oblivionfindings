<?php

namespace App\Domain\Finance\Contracts;

use App\Domain\Finance\Models\FinAccountingIntegration;
use Illuminate\Support\Collection;

interface AccountingSyncProviderInterface
{
    /**
     * Push local chart-of-accounts entries to the external system.
     *
     * @param  Collection<int, \App\Domain\Finance\Models\FinAccount>  $accounts
     * @return array{success: int, errors: array<int, array{id: int, message: string}>}
     */
    public function pushAccounts(FinAccountingIntegration $integration, Collection $accounts): array;

    /**
     * Pull accounts from the external system into local format.
     *
     * @return array{accounts: array<int, array{external_id: string, code: string, name: string, type: string}>, errors: array<int, array{message: string}>}
     */
    public function pullAccounts(FinAccountingIntegration $integration): array;

    /**
     * Push local journals to the external system.
     *
     * @param  Collection<int, \App\Domain\Finance\Models\FinJournal>  $journals
     * @return array{success: int, errors: array<int, array{id: int, message: string}>}
     */
    public function pushJournals(FinAccountingIntegration $integration, Collection $journals): array;

    /**
     * Pull journals from the external system since a given date.
     *
     * @return array{journals: array<int, array{external_id: string, date: string, reference: string|null, lines: array}>, errors: array<int, array{message: string}>}
     */
    public function pullJournals(FinAccountingIntegration $integration, string $since): array;

    /**
     * Push local bills as invoices to the external system.
     *
     * @param  Collection<int, \App\Domain\Finance\Models\FinBill>  $bills
     * @return array{success: int, errors: array<int, array{id: int, message: string}>}
     */
    public function pushInvoices(FinAccountingIntegration $integration, Collection $bills): array;

    /**
     * Pull invoices from the external system since a given date.
     *
     * @return array{invoices: array<int, array{external_id: string, date: string, total: float, contact_id: string|null}>, errors: array<int, array{message: string}>}
     */
    public function pullInvoices(FinAccountingIntegration $integration, string $since): array;

    /**
     * Push local vendors as contacts to the external system.
     *
     * @param  Collection<int, \App\Domain\Finance\Models\FinVendor>  $vendors
     * @return array{success: int, errors: array<int, array{id: int, message: string}>}
     */
    public function pushContacts(FinAccountingIntegration $integration, Collection $vendors): array;

    /**
     * Pull contacts from the external system.
     *
     * @return array{contacts: array<int, array{external_id: string, name: string, email: string|null}>, errors: array<int, array{message: string}>}
     */
    public function pullContacts(FinAccountingIntegration $integration): array;

    /**
     * Test the connection to the external accounting system.
     */
    public function testConnection(FinAccountingIntegration $integration): bool;

    /**
     * Refresh the OAuth access token using the stored refresh token.
     */
    public function refreshToken(FinAccountingIntegration $integration): void;
}
