<?php

namespace App\Observers;

use App\Domain\Finance\Jobs\ProcessFinancialEventJob;
use App\Domain\Finance\Models\FinFinancialEvent;
use App\Models\ClientLedgerEntry;
use App\Support\LegacyStorageContext;
use Illuminate\Support\Facades\Log;

/**
 * Posts client ledger entries to GL via FinancialEventService.
 *
 * Skips entries where:
 *   - posts_to_gl is false (informational only)
 *   - type is 'transfer' (internal movement)
 *   - journal_id is already set (already posted)
 *
 * GL mappings:
 *   contribution (inflow)    → DR 1000 Bank / CR 4210 Resident Contributions
 *   funding (inflow)         → DR 1000 Bank / CR 4100 Funding Income
 *   purchase (outflow)       → DR category-based expense / CR 1000 Bank
 *   reimbursement (outflow)  → DR 2310 Claims Payable / CR 1000 Bank
 *   adjustment               → direction determines debit/credit
 */
class ClientLedgerEntryObserver
{
    public function created(ClientLedgerEntry $entry): void
    {
        if (! $entry->posts_to_gl) {
            return;
        }

        if ($entry->type === 'transfer') {
            return;
        }

        if ($entry->journal_id) {
            return;
        }

        if (bccomp((string) $entry->amount, '0', 2) <= 0) {
            return;
        }

        try {
            [$eventType, $debitCode, $creditCode] = $this->resolveAccounts($entry);

            if (! $eventType) {
                return;
            }

            ProcessFinancialEventJob::dispatch([
                'organization_id' => LegacyStorageContext::id(),
                'source_type' => ClientLedgerEntry::class,
                'source_id' => $entry->id,
                'event_type' => $eventType,
                'description' => "Client ledger [{$entry->type}]: {$entry->description}",
                'amount' => (string) $entry->amount,
                'event_date' => $entry->entry_date->toDateString(),
                'debit_account_code' => $debitCode,
                'credit_account_code' => $creditCode,
                'payment_type' => FinFinancialEvent::PAYMENT_CASH,
                'journal_type' => 'standard',
                'site_id' => $entry->site_id,
                'client_id' => $entry->client_id,
                'source_updated_at' => $entry->updated_at?->toISOString(),
            ]);
        } catch (\Throwable $e) {
            Log::error("ClientLedgerEntryObserver: Failed to dispatch GL job for entry #{$entry->id}: {$e->getMessage()}");
        }
    }

    /**
     * @return array{string|null, string, string}
     */
    private function resolveAccounts(ClientLedgerEntry $entry): array
    {
        $category = strtolower(trim($entry->category ?? ''));

        return match ($entry->type) {
            'contribution' => [
                'client_ledger_income',
                '1000',
                config('finance.client_ledger_income_categories.' . $category, '4210'),
            ],
            'funding' => [
                'client_ledger_income',
                '1000',
                '4100', // Funding Income
            ],
            'purchase' => [
                'client_ledger_expense',
                config('finance.client_ledger_expense_categories.' . $category, '6440'),
                '1000',
            ],
            'reimbursement' => [
                'client_ledger_expense',
                '2310', // Claims Payable (settling the obligation)
                '1000', // Bank
            ],
            'adjustment' => $this->resolveAdjustment($entry, $category),
            default => [null, '', ''],
        };
    }

    private function resolveAdjustment(ClientLedgerEntry $entry, string $category): array
    {
        if ($entry->isInflow()) {
            return [
                'client_ledger_income',
                '1000',
                config('finance.client_ledger_income_categories.' . $category, '4210'),
            ];
        }

        return [
            'client_ledger_expense',
            config('finance.client_ledger_expense_categories.' . $category, '6440'),
            '1000',
        ];
    }
}
