<?php

namespace App\Domain\Finance\Services\AccountingSyncProviders;

use App\Domain\Finance\Contracts\AccountingSyncProviderInterface;
use App\Domain\Finance\Models\FinAccountingIntegration;
use Illuminate\Support\Collection;
use RuntimeException;

class MyobSyncProvider implements AccountingSyncProviderInterface
{
    private const UNSUPPORTED_MESSAGE = 'MYOB accounting sync is not supported yet. Use Xero sync or CSV/manual export for this organization.';

    /**
     * {@inheritDoc}
     */
    public function pushAccounts(FinAccountingIntegration $integration, Collection $accounts): array
    {
        $this->unsupported();
    }

    /**
     * {@inheritDoc}
     */
    public function pullAccounts(FinAccountingIntegration $integration): array
    {
        $this->unsupported();
    }

    /**
     * {@inheritDoc}
     */
    public function pushJournals(FinAccountingIntegration $integration, Collection $journals): array
    {
        $this->unsupported();
    }

    /**
     * {@inheritDoc}
     */
    public function pullJournals(FinAccountingIntegration $integration, string $since): array
    {
        $this->unsupported();
    }

    /**
     * {@inheritDoc}
     */
    public function pushInvoices(FinAccountingIntegration $integration, Collection $bills): array
    {
        $this->unsupported();
    }

    /**
     * {@inheritDoc}
     */
    public function pullInvoices(FinAccountingIntegration $integration, string $since): array
    {
        $this->unsupported();
    }

    /**
     * {@inheritDoc}
     */
    public function pushContacts(FinAccountingIntegration $integration, Collection $vendors): array
    {
        $this->unsupported();
    }

    /**
     * {@inheritDoc}
     */
    public function pullContacts(FinAccountingIntegration $integration): array
    {
        $this->unsupported();
    }

    /**
     * {@inheritDoc}
     */
    public function testConnection(FinAccountingIntegration $integration): bool
    {
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function refreshToken(FinAccountingIntegration $integration): void
    {
        $this->unsupported();
    }

    private function unsupported(): never
    {
        throw new RuntimeException(self::UNSUPPORTED_MESSAGE);
    }
}
